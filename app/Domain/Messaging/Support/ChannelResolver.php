<?php

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Enums\ChannelStatus;
use App\Domain\Messaging\Models\MessagingChannel;
use InvalidArgumentException;

/**
 * Picks the provider for a channel in the current tenant (ADR-018): the channel's connected provider
 * when the tenant has connected it in Settings → Messaging, the configured fallback otherwise.
 */
class ChannelResolver
{
    /** @return array{provider: string, simulated: bool, window_hours: ?int} */
    public function resolve(string $channel): array
    {
        $config = config("messaging.channels.{$channel}") ?? throw new InvalidArgumentException("Unknown messaging channel [{$channel}].");
        $provider = $config['connected_provider'] && $this->connected($channel) ? $config['connected_provider'] : $config['provider'];

        return [
            'provider' => $provider,
            'simulated' => (bool) config("messaging.providers.{$provider}.simulated", false),
            'window_hours' => config("messaging.providers.{$provider}.window_hours"),
        ];
    }

    /** The tenant's connected channel row, if any. */
    public function connected(string $channel): ?MessagingChannel
    {
        $row = MessagingChannel::query()->where('channel', $channel)->where('status', ChannelStatus::Connected)->first();

        return $row?->isConnected() ? $row : null;
    }

    public function isSimulated(string $channel): bool
    {
        return $this->resolve($channel)['simulated'];
    }

    /** Whether the channel's messages form conversations (WhatsApp, Instagram — not email). */
    public static function hasConversations(string $channel): bool
    {
        return (bool) config("messaging.channels.{$channel}.conversations", false);
    }
}
