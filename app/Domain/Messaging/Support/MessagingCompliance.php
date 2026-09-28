<?php

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Every compliance decision for outbound messages (ADR-018): the provider's customer service window,
 * opt-out, and quiet hours. The automation step and the inbox both ask here before queueing.
 */
class MessagingCompliance
{
    public function __construct(
        private readonly ChannelResolver $channels,
        private readonly QuietHours $quietHours,
    ) {}

    public function conversationFor(string $channel, ?string $recipient): ?Conversation
    {
        $handle = ContactHandle::for($channel, $recipient);

        return $handle ? Conversation::query()->where('channel', $channel)->where('contact_handle', $handle)->first() : null;
    }

    public function isOptedOut(string $channel, ?string $recipient): bool
    {
        return (bool) $this->conversationFor($channel, $recipient)?->isOptedOut();
    }

    /** When the free-form window closes; null when the channel has no window (simulated, email) or it is closed. */
    public function windowClosesAt(string $channel, ?Conversation $conversation): ?Carbon
    {
        $hours = $this->channels->resolve($channel)['window_hours'];

        if ($hours === null || ! $conversation?->last_inbound_at) {
            return null;
        }

        $closes = $conversation->last_inbound_at->copy()->addHours((int) $hours);

        return $closes->isFuture() ? $closes : null;
    }

    /** Whether the channel enforces a window at all (a real Meta provider is connected). */
    public function hasWindow(string $channel): bool
    {
        return $this->channels->resolve($channel)['window_hours'] !== null;
    }

    /** Null when free text may be sent now; otherwise why not. */
    public function textBlockedReason(string $channel, ?Conversation $conversation): ?string
    {
        if (! $this->hasWindow($channel) || $this->windowClosesAt($channel, $conversation)) {
            return null;
        }

        return $channel === 'whatsapp'
            ? __('The customer has not messaged in the last 24 hours, so WhatsApp only allows an approved template.')
            : __(':channel only allows replies within 24 hours of the customer’s last message.', ['channel' => config("messaging.channels.{$channel}.label", $channel)]);
    }

    /** Null when a template may be sent; otherwise why not. */
    public function templateBlockedReason(string $channel, ?Conversation $conversation): ?string
    {
        if (! config("messaging.channels.{$channel}.templates")) {
            return __('This channel has no message templates.');
        }

        return $conversation?->isOptedOut() ? __('This contact opted out of messages.') : null;
    }

    /** When an outbound message must wait for the end of quiet hours (automation messages only). */
    public function scheduleFor(string $channel, MessagePurpose $purpose): ?CarbonImmutable
    {
        return $purpose === MessagePurpose::Automation ? $this->quietHours->delayUntil($channel) : null;
    }

    /** 'out' for STOP-style messages, 'in' for START-style ones, null otherwise. Whole-message, case-insensitive. */
    public static function keyword(?string $text): ?string
    {
        $normalised = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $text) ?? ''));
        $normalised = rtrim($normalised, '.!');

        return match (true) {
            $normalised === '' => null,
            in_array($normalised, config('messaging.opt_out_keywords', []), true) => 'out',
            in_array($normalised, config('messaging.opt_in_keywords', []), true) => 'in',
            default => null,
        };
    }
}
