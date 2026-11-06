<?php

namespace App\Domain\Loyalty\Listeners;

use App\Domain\Commerce\Events\OrderCreated;
use App\Domain\Commerce\Models\Order;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\Phone;
use App\Support\TenantTime;
use Throwable;

/**
 * On a customer’s birthday, the first order of the day gets a warm WhatsApp wish and an offer of
 * one free item (staff honour it when they confirm / complete the order).
 */
class SendBirthdayWishOnFirstOrder
{
    public function __construct(
        private readonly LoyaltySettings $loyalty,
        private readonly MessagingService $messaging,
        private readonly MessagingCompliance $compliance,
        private readonly TenantContext $context,
    ) {}

    public function handle(OrderCreated $event): void
    {
        try {
            $this->send($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function send(OrderCreated $event): void
    {
        $order = $event->order->loadMissing('customer');
        $customer = $order->customer;

        if (! $customer || $customer->trashed() || ! $customer->date_of_birth) {
            return;
        }

        $today = TenantTime::now();

        if (! $customer->isBirthdayOn($today)) {
            return;
        }

        $dayStartUtc = $today->copy()->startOfDay()->utc();
        $ordersToday = Order::query()
            ->where('customer_id', $customer->id)
            ->where('created_at', '>=', $dayStartUtc)
            ->count();

        if ($ordersToday !== 1) {
            return;
        }

        $phone = Phone::normalize($customer->phone);

        if (! $phone) {
            return;
        }

        $conversation = $this->compliance->conversationFor('whatsapp', $phone);

        if ($conversation?->isOptedOut()) {
            return;
        }

        if ($this->compliance->textBlockedReason('whatsapp', $conversation)) {
            return;
        }

        $settings = $this->loyalty->enabled() ? $this->loyalty->all() : [];
        $treat = filled($settings['birthday_reward_label'] ?? null)
            ? (string) $settings['birthday_reward_label']
            : (filled($settings['reward_label'] ?? null) ? (string) $settings['reward_label'] : 'one free item');
        $business = $this->context->tenant()->name;
        $firstName = strstr(trim($customer->name), ' ', true) ?: $customer->name;

        $body = implode("\n\n", [
            "🎂 Happy birthday, {$firstName}!",
            "The whole team at {$business} is celebrating you today. Thanks for starting your special day with us on order {$order->reference()}.",
            "🎁 Birthday treat: reply here with *one item* you’d love as your {$treat} (one item only). We’ll add it when we confirm your order — just for you, today.",
            'Wishing you a wonderful year ahead. 💛',
        ]);

        $this->messaging->queue([
            'channel' => 'whatsapp',
            'recipient' => $phone,
            'recipient_name' => $customer->name,
            'body' => $body,
            'idempotency_key' => 'birthday-first-order-wa:'.$customer->id.':'.$today->toDateString(),
            'purpose' => MessagePurpose::Automation,
            'customer_id' => $customer->id,
        ]);

        // Claim this year’s greeting so the daily birthday job doesn’t send a second wish.
        if (! $customer->birthday_greeted_on || (int) $customer->birthday_greeted_on->year < (int) $today->year) {
            $customer->forceFill(['birthday_greeted_on' => $today->toDateString()])->save();
        }
    }
}
