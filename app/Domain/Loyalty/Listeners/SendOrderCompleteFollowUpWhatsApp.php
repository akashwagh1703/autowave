<?php

namespace App\Domain\Loyalty\Listeners;

use App\Domain\Commerce\Events\OrderCompleted;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Support\CustomerBirthdayLink;
use App\Domain\Loyalty\Support\LoyaltyLinks;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Support\Phone;
use Throwable;

/**
 * After an order is completed (and stamps earned), tell the customer their loyalty balance and,
 * when birthday collection is on and DOB is missing, ask them to save it via a signed link.
 */
class SendOrderCompleteFollowUpWhatsApp
{
    public function __construct(
        private readonly LoyaltySettings $loyalty,
        private readonly MessagingService $messaging,
        private readonly MessagingCompliance $compliance,
    ) {}

    public function handle(OrderCompleted $event): void
    {
        try {
            $this->send($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function send(OrderCompleted $event): void
    {
        if (! $this->loyalty->enabled()) {
            return;
        }

        $order = $event->order->loadMissing('customer');
        $customer = $order->customer;

        if (! $customer || $customer->trashed()) {
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

        $settings = $this->loyalty->all();
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->first();
        $parts = [];

        if ($account) {
            $required = $settings['stamps_required'];
            $balance = $account->stamps_balance;
            $reward = $settings['reward_label'];
            $parts[] = "Thanks for your order {$order->reference()}! You now have {$balance} of {$required} stamps toward {$reward}.";

            if ($cardUrl = LoyaltyLinks::cardUrl($account)) {
                $parts[] = "Your stamp card: {$cardUrl}";
            }
        }

        $customer->refresh();

        if ($settings['collect_birthday'] && ! $customer->date_of_birth) {
            $birthdayUrl = CustomerBirthdayLink::url($customer);
            $parts[] = $birthdayUrl
                ? "Want a birthday treat from us? Save your date of birth here: {$birthdayUrl}"
                : 'Want a birthday treat from us? Reply with your date of birth as DD/MM/YYYY.';
        }

        if ($parts === []) {
            return;
        }

        $this->messaging->queue([
            'channel' => 'whatsapp',
            'recipient' => $phone,
            'recipient_name' => $customer->name,
            'body' => implode("\n\n", $parts),
            'idempotency_key' => 'order-complete-followup-wa:'.$order->id,
            'purpose' => MessagePurpose::Automation,
            'customer_id' => $customer->id,
        ]);
    }
}
