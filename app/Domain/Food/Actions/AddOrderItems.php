<?php

namespace App\Domain\Food\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Services\OrderPricing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds items to an open dine-in order (a table ordering more). Prices come from the products; stock
 * is taken as for a new order; the new lines join the kitchen queue. Totals and payment status are
 * recomputed; the discount stays as it was.
 */
class AddOrderItems
{
    public function __construct(
        private readonly OrderPricing $pricing,
        private readonly PlaceOrder $placeOrder,
        private readonly RecordActivity $recordActivity,
    ) {}

    /** @param  mixed  $items  list of {product_id, quantity} */
    public function handle(Order $order, mixed $items, ?User $actor = null): Order
    {
        $normalized = OrderPricing::normalize($items);

        return DB::transaction(function () use ($order, $normalized, $actor) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->fulfilment !== 'dine_in' || ! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['items' => __('Items can only be added to an open dine-in order.')]);
            }

            if ($locked->items()->count() + count($normalized) > (int) config('commerce.limits.items_per_order')) {
                throw ValidationException::withMessages(['items' => __('An order can have up to :max lines.', ['max' => config('commerce.limits.items_per_order')])]);
            }

            $priced = $this->pricing->strict($normalized);
            $this->placeOrder->addItems($locked, $priced['lines'], $actor);

            $subtotal = bcadd((string) $locked->subtotal, $priced['subtotal'], 2);
            $total = bcadd(bcsub($subtotal, (string) $locked->discount, 2), (string) $locked->delivery_fee, 2);

            $locked->forceFill([
                'subtotal' => $subtotal,
                'total' => $total,
                'payment_status' => PaymentStatus::for((string) $locked->amount_paid, $total),
            ])->save();

            $added = collect($priced['lines'])->map(fn (array $line) => $line['quantity'].' × '.$line['name'])->implode(', ');

            $this->recordActivity->handle('order_items_added', order: $locked, actor: $actor, metadata: [
                ...PlaceOrder::summary($locked),
                'added' => $added,
                'added_total' => $priced['subtotal'],
            ]);

            return $locked;
        });
    }
}
