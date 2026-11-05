<?php

namespace App\Domain\Loyalty\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Loyalty\Models\LoyaltyStamp;
use App\Domain\Loyalty\Support\LoyaltySettings;

/** Awards stamps when an order is completed (idempotent per order). */
class EarnFromOrder
{
    public function __construct(
        private readonly LoyaltySettings $settings,
        private readonly EnrollCustomer $enroll,
        private readonly AddStamps $addStamps,
    ) {}

    public function handle(Order $order): ?LoyaltyStamp
    {
        if (! $this->settings->enabled()) {
            return null;
        }

        $order->loadMissing(['customer', 'items']);
        $customer = $order->customer;

        if (! $customer || $customer->trashed()) {
            return null;
        }

        $delta = $this->stampsFor($order);

        if ($delta < 1) {
            return null;
        }

        $account = $this->enroll->forCustomer($customer, via: 'order');

        return $this->addStamps->handle(
            $account,
            $delta,
            'order',
            $order->id,
            note: $order->reference(),
        );
    }

    private function stampsFor(Order $order): int
    {
        $settings = $this->settings->all();
        $productIds = $settings['product_ids'];
        $items = $order->items;

        if ($productIds !== []) {
            $items = $items->filter(fn ($item) => in_array((int) $item->product_id, $productIds, true));
        }

        if ($items->isEmpty()) {
            return 0;
        }

        return $settings['earn_per'] === 'order'
            ? 1
            : (int) $items->sum('quantity');
    }
}
