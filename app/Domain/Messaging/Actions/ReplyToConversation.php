<?php

namespace App\Domain\Messaging\Actions;

use App\Domain\Files\Actions\ManageAttachments;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Services\ConversationRecorder;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A team member's message from the inbox: free text inside the window (optionally with one file, on
 * WhatsApp), or an approved template.
 */
class ReplyToConversation
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly MessagingCompliance $compliance,
        private readonly ManageAttachments $attachments,
        private readonly ConversationRecorder $recorder,
    ) {}

    /**
     * @param  ?string  $clientId  a UUID from the composer, so a double submit sends once
     * @param  ?UploadedFile  $file  sent with $body as its caption; checked like any upload
     */
    public function text(Conversation $conversation, string $body, User $actor, ?string $clientId = null, ?UploadedFile $file = null): OutboundMessage
    {
        if ($reason = $this->compliance->textBlockedReason($conversation->channel, $conversation)) {
            throw ValidationException::withMessages(['body' => $reason]);
        }

        if ($file === null) {
            return $this->queue($conversation, $actor, $clientId, ['body' => trim($body)]);
        }

        if (! self::canSendFiles($conversation)) {
            throw ValidationException::withMessages(['file' => __('Files can only be sent on WhatsApp for now.')]);
        }

        // The delivery job is dispatched after commit, so it always finds the file attached.
        return DB::transaction(function () use ($conversation, $body, $actor, $clientId, $file) {
            $message = $this->queue($conversation, $actor, $clientId, ['body' => trim($body)]);
            $entry = $message->wasRecentlyCreated ? $message->entry()->first() : null;

            if ($entry) {
                $attachment = $this->attachments->upload($entry, 'conversation_message', $file, $actor);
                $entry->forceFill(['type' => $attachment->kind])->save();

                $conversation->refresh();
                $this->recorder->touch($conversation, $entry, ConversationMessage::OUTBOUND);
                $conversation->save();
            }

            return $message;
        });
    }

    public static function canSendFiles(Conversation $conversation): bool
    {
        return $conversation->channel === 'whatsapp';
    }

    /** @param  list<string>  $params */
    public function template(Conversation $conversation, MessageTemplate $template, array $params, User $actor, ?string $clientId = null): OutboundMessage
    {
        if ($reason = $this->compliance->templateBlockedReason($conversation->channel, $conversation)) {
            throw ValidationException::withMessages(['template' => $reason]);
        }

        if (! $template->isApproved() || $template->channel !== $conversation->channel) {
            throw ValidationException::withMessages(['template' => __('Choose an approved template.')]);
        }

        $params = array_map(fn ($value) => trim((string) $value), array_slice(array_values($params), 0, $template->variables));

        if (count($params) < $template->variables || in_array('', $params, true)) {
            throw ValidationException::withMessages(['params' => __('Fill in every template variable.')]);
        }

        return $this->queue($conversation, $actor, $clientId, [
            'body' => $template->render($params),
            'template' => ['name' => $template->name, 'language' => $template->language, 'params' => $params],
        ]);
    }

    /** @param  array<string, mixed>  $content */
    private function queue(Conversation $conversation, User $actor, ?string $clientId, array $content): OutboundMessage
    {
        return $this->messaging->queue([
            ...$content,
            'channel' => $conversation->channel,
            'recipient' => $conversation->contact_handle,
            'recipient_name' => Str::limit($conversation->displayName(), 150, ''),
            'idempotency_key' => 'reply:'.$conversation->id.':'.($clientId ?? Str::uuid()->toString()),
            'purpose' => MessagePurpose::Reply,
            'lead_id' => $conversation->lead_id,
            'customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
            'sent_by_user_id' => $actor->id,
        ]);
    }
}
