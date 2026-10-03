<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Support\ChannelResolver;
use App\Domain\Messaging\Support\ContactHandle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Finds or creates conversations and appends messages to them, for both directions. */
class ConversationRecorder
{
    /** @param  array<string, mixed>  $attributes  used only when the conversation is created */
    public function findOrCreate(string $channel, string $handle, array $attributes = []): Conversation
    {
        $find = fn () => Conversation::query()->where('channel', $channel)->where('contact_handle', $handle)->first();

        if ($existing = $find()) {
            return $existing;
        }

        try {
            // Savepoint: losing the race on the unique key must not abort an enclosing transaction.
            return DB::transaction(fn () => Conversation::query()->create([
                ...$attributes,
                'channel' => $channel,
                'contact_handle' => $handle,
                'status' => ConversationStatus::Open,
            ]));
        } catch (UniqueConstraintViolationException) {
            return $find() ?? throw new \LogicException('Duplicate conversation key without a conversation.');
        }
    }

    /**
     * Appends a just-queued outbound message to its conversation (creating it if needed) and links the
     * message back. Channels without conversations (email) and unusable recipients are skipped.
     */
    public function recordOutbound(OutboundMessage $message, bool $markRead = false): ?ConversationMessage
    {
        if (! ChannelResolver::hasConversations($message->channel)) {
            return null;
        }

        $conversation = $message->conversation_id
            ? Conversation::query()->find($message->conversation_id)
            : $this->forRecipient($message);

        if (! $conversation) {
            return null;
        }

        $conversation->forceFill(array_filter([
            'lead_id' => $conversation->lead_id ?? $message->lead_id,
            'customer_id' => $conversation->customer_id ?? $message->customer_id,
            'contact_name' => $conversation->contact_name ?? $message->recipient_name,
        ], fn ($value) => $value !== null));

        $entry = ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'channel' => $message->channel,
            'direction' => ConversationMessage::OUTBOUND,
            'type' => $message->template ? 'template' : 'text',
            'body' => $message->body,
            'outbound_message_id' => $message->id,
            'meta' => $message->template ? ['template' => $message->template['name'] ?? null] : null,
            'sent_at' => now(),
        ]);

        $this->touch($conversation, $entry, ConversationMessage::OUTBOUND);

        if ($markRead) {
            $conversation->unread_count = 0;
        }

        $conversation->save();

        if ($message->conversation_id !== $conversation->id) {
            $message->forceFill(['conversation_id' => $conversation->id])->saveQuietly();
        }

        return $entry;
    }

    /** Sets the conversation's last-message fields from a message (does not save). */
    public function touch(Conversation $conversation, ConversationMessage $message, string $direction): void
    {
        if ($conversation->last_message_at && $conversation->last_message_at->gt($message->sent_at)) {
            return;
        }

        $text = trim((string) $message->body);
        $placeholder = ConversationMessage::PLACEHOLDERS[$message->type] ?? '';

        if ($placeholder !== '' && ! str_starts_with($text, $placeholder)) {
            $text = trim($placeholder.' '.$text);
        }

        $conversation->forceFill([
            'last_message_at' => $message->sent_at,
            'last_message_preview' => Str::limit(preg_replace('/\s+/', ' ', $text) ?? '', (int) config('messaging.inbox.preview_length')),
            'last_message_direction' => $direction,
        ]);
    }

    private function forRecipient(OutboundMessage $message): ?Conversation
    {
        $handle = ContactHandle::for($message->channel, $message->recipient);

        return $handle ? $this->findOrCreate($message->channel, $handle, [
            'contact_name' => $message->recipient_name,
            'lead_id' => $message->lead_id,
            'customer_id' => $message->customer_id,
        ]) : null;
    }
}
