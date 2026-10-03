<?php

namespace App\Http\Presenters;

use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Models\MessagingChannel;
use App\Domain\Tenant\Models\TenantUser;

/** Browser-safe shapes for the inbox and messaging settings. Never includes credentials or webhook keys. */
class MessagingPresenter
{
    /** @return array<string, mixed> */
    public static function conversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'channel' => $conversation->channel,
            'channel_label' => config("messaging.channels.{$conversation->channel}.label", $conversation->channel),
            'name' => $conversation->displayName(),
            'handle' => $conversation->channel === 'whatsapp' ? $conversation->contact_handle : null,
            'profile_name' => $conversation->contact_name,
            'status' => $conversation->status->value,
            'unread_count' => $conversation->unread_count,
            'preview' => $conversation->last_message_preview,
            'last_direction' => $conversation->last_message_direction,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_inbound_at' => $conversation->last_inbound_at?->toIso8601String(),
            'opted_out' => $conversation->isOptedOut(),
            'assignee' => $conversation->assignee ? ['id' => $conversation->assignee->id, 'name' => $conversation->assignee->user?->name] : null,
            'customer' => $conversation->customer && ! $conversation->customer->trashed() ? ['id' => $conversation->customer->id, 'name' => $conversation->customer->name] : null,
            'lead' => $conversation->lead && ! $conversation->lead->trashed() ? ['id' => $conversation->lead->id, 'name' => $conversation->lead->name] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function message(ConversationMessage $message): array
    {
        $outbound = $message->outbound;
        $attachment = $message->attachment;
        $media = $message->meta['media'] ?? null;

        return [
            'id' => $message->id,
            'direction' => $message->direction,
            'type' => $message->type,
            'body' => $attachment ? $message->caption() : $message->body,
            'attachment' => $attachment ? FilesPresenter::attachment($attachment) : null,
            'media_status' => $attachment ? null : ($media['status'] ?? null),
            'media_error' => $attachment ? null : ($media['error'] ?? null),
            'sent_at' => $message->sent_at->toIso8601String(),
            'status' => $outbound?->status->value,
            'status_label' => $outbound?->status->label(),
            'scheduled_for' => $outbound?->status === MessageStatus::Queued ? $outbound->scheduled_for?->toIso8601String() : null,
            'error' => $outbound?->status === MessageStatus::Failed ? $outbound->error : null,
            'simulated' => (bool) $outbound?->simulated,
            'template' => $message->meta['template'] ?? null,
            'sender' => $outbound?->sender?->name ?? ($outbound?->automation_run_id ? 'Automation' : null),
        ];
    }

    /** @return array<string, mixed> */
    public static function template(MessageTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'language' => $template->language,
            'category' => $template->category,
            'status' => $template->status,
            'approved' => $template->isApproved(),
            'body' => $template->body,
            'variables' => $template->variables,
            'synced_at' => $template->synced_at?->toIso8601String(),
        ];
    }

    /**
     * @param  bool  $withSetup  include the callback URL and verify token (settings.update only)
     * @return array<string, mixed>
     */
    public static function channel(MessagingChannel $channel, bool $withSetup): array
    {
        return [
            'channel' => $channel->channel,
            'connected' => $channel->isConnected(),
            'external_id' => $channel->external_id,
            'business_account_id' => $channel->business_account_id,
            'display_name' => $channel->display_name,
            'display_handle' => $channel->display_handle,
            'has_token' => $channel->credential('access_token') !== null,
            'has_secret' => $channel->credential('app_secret') !== null,
            'connected_at' => $channel->connected_at?->toIso8601String(),
            'last_webhook_at' => $channel->last_webhook_at?->toIso8601String(),
            'callback_url' => $withSetup ? route('webhooks.meta.receive', $channel->webhook_key) : null,
            'verify_token' => $withSetup ? $channel->verifyToken() : null,
        ];
    }

    /** @return array{id: int, name: ?string} */
    public static function member(TenantUser $member): array
    {
        return ['id' => $member->id, 'name' => $member->user?->name];
    }
}
