<?php

namespace App\Domain\Messaging\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Messaging\Enums\ChannelStatus;
use App\Domain\Messaging\Models\MessagingChannel;

/**
 * Forgets the token and app secret. The row, webhook key and verify token stay, so the webhook set up in
 * Meta keeps working after a reconnect. New messages fall back to the simulated provider.
 */
class DisconnectChannel
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(MessagingChannel $channel): void
    {
        $channel->forceFill([
            'status' => ChannelStatus::Disconnected,
            'credentials' => ['verify_token' => $channel->verifyToken()],
            'external_id' => null,
            'business_account_id' => null,
            'last_error' => null,
            'connected_at' => null,
        ])->save();

        $this->audit->log('messaging.channel_disconnected', $channel, ['channel' => $channel->channel]);
    }
}
