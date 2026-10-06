<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotEngine;
use App\Domain\Chatbot\Support\ChatbotSettings;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Answers one inbound WhatsApp message with the assistant (ADR-021). Stays quiet when:
 * - the message is old (a delayed webhook) or a newer one from the contact is already in;
 * - a team member replied in this chat within the pause hours, or the contact asked for a person;
 * - the chat hit the per-minute reply limit.
 * The reply's idempotency key is the inbound message, so a retried job never answers twice.
 */
class ReplyWithChatbot implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $conversationId,
        public readonly int $messageId,
    ) {
        $this->onQueue(config('chatbot.queue'));
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TenantContext $context): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant || ! $tenant->isActive()) {
            return;
        }

        $context->run($tenant, function () use ($context) {
            $settings = app(ChatbotSettings::class)->all();
            $conversation = Conversation::query()->with(['lead', 'customer'])->find($this->conversationId);
            $message = ConversationMessage::query()->where('conversation_id', $this->conversationId)->find($this->messageId);

            if (! $settings['enabled'] || ! $context->hasModule('messaging') || ! $conversation || ! $message || $conversation->isOptedOut()) {
                return;
            }

            if ($message->direction !== ConversationMessage::INBOUND
                || $message->sent_at->lt(now()->subMinutes((int) config('chatbot.max_age_minutes')))
                || $this->newerInboundExists($message)
                || $this->teamReplied($conversation, $settings['pause_hours'])
                || app(MessagingCompliance::class)->textBlockedReason('whatsapp', $conversation) !== null) {
                return;
            }

            if (! RateLimiter::attempt("chatbot:{$this->tenantId}:{$conversation->id}", (int) config('chatbot.rate_limit'), fn () => true, 60)) {
                return;
            }

            DB::transaction(function () use ($conversation, $message) {
                $session = $this->session($conversation);

                if ($session->isPaused()) {
                    if (! $this->resumes($session, $message)) {
                        return;
                    }

                    $session->forceFill(['paused_until' => null, 'data' => Arr::except($session->data ?? [], 'paused_by') ?: null]);
                }

                $reply = app(ChatbotEngine::class)->respond($session, $conversation, $message);
                $session->save();

                if ($reply === null) {
                    return;
                }

                app(MessagingService::class)->queue([
                    'channel' => 'whatsapp',
                    'recipient' => $conversation->contact_handle,
                    'recipient_name' => $conversation->contact_name,
                    'body' => $reply['body'],
                    'interactive' => $reply['interactive'],
                    'assistant' => true,
                    'purpose' => MessagePurpose::System,
                    'idempotency_key' => "assistant:{$message->id}",
                    'conversation_id' => $conversation->id,
                    'lead_id' => $conversation->lead_id,
                    'customer_id' => $conversation->customer_id,
                ]);
            });
        });
    }

    /** The contact sent something newer; that message's job answers instead. */
    private function newerInboundExists(ConversationMessage $message): bool
    {
        return ConversationMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', ConversationMessage::INBOUND)
            ->where('id', '>', $message->id)
            ->exists();
    }

    private function teamReplied(Conversation $conversation, int $hours): bool
    {
        return OutboundMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('sent_by_user_id')
            ->where('created_at', '>=', now()->subHours($hours))
            ->exists();
    }

    /** After "Talk to a person", typing "menu" or tapping one of the assistant's options brings it back. */
    private function resumes(ChatbotSession $session, ConversationMessage $message): bool
    {
        if (($session->data['paused_by'] ?? null) !== 'contact') {
            return false;
        }

        $replyId = $message->meta['reply_id'] ?? null;
        $text = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', (string) $message->body) ?? ''));

        return (is_string($replyId) && str_starts_with($replyId, 'aw.'))
            || ($message->type === 'text' && in_array($text, config('chatbot.keywords.menu'), true));
    }

    /** The conversation's session, locked for this message. */
    private function session(Conversation $conversation): ChatbotSession
    {
        if (! ChatbotSession::query()->where('conversation_id', $conversation->id)->exists()) {
            try {
                DB::transaction(fn () => ChatbotSession::query()->create(['conversation_id' => $conversation->id]));
            } catch (UniqueConstraintViolationException) {
                // Another message created it a moment ago.
            }
        }

        return ChatbotSession::query()->where('conversation_id', $conversation->id)->lockForUpdate()->firstOrFail();
    }
}
