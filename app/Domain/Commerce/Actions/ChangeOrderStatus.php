<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Events\OrderCancelled;
use App\Domain\Commerce\Events\OrderCompleted;
use App\Domain\Commerce\Events\OrderConfirmed;
use App\Domain\Commerce\Events\OrderReady;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Services\Coupons;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an order through its lifecycle (OrderStatus::allowedTransitions). Cancelling puts the
 * stock taken by the order back. Completed orders are final (returns are not supported yet).
 * Each change is recorded on the timeline and fires its automation trigger.
 */
class ChangeOrderStatus
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly StockLedger $ledger,
    ) {}

    public function handle(Order $order, OrderStatus $status, ?User $actor = null, ?string $reason = null): Order
    {
        $this->ensureAllowed($order, $status);

        return DB::transaction(function () use ($order, $status, $actor, $reason) {
            $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->ensureAllowed($fresh, $status);

            $reason = filled($reason) ? trim($reason) : null;
            $order->forceFill(['status' => $status, ...$this->timestamps($order, $status, $reason)])->save();

            if ($status === OrderStatus::Cancelled) {
                $this->restock($order, $actor);
                Coupons::release($order);
            }

            // A closed order leaves the kitchen queue.
            if (! $status->isOpen()) {
                OrderItem::query()->where('order_id', $order->id)->where('kitchen_status', OrderItem::KITCHEN_QUEUED)->update(['kitchen_status' => null]);
            }

            $this->recordActivity->handle(
                'order_'.$status->value,
                order: $order,
                actor: $actor,
                body: $status === OrderStatus::Cancelled ? $reason : null,
                metadata: PlaceOrder::summary($order->loadMissing('items')),
            );

            match ($status) {
                OrderStatus::Confirmed => OrderConfirmed::dispatch($order),
                OrderStatus::Ready => OrderReady::dispatch($order),
                OrderStatus::Completed => OrderCompleted::dispatch($order),
                OrderStatus::Cancelled => OrderCancelled::dispatch($order),
                OrderStatus::Pending => null,
            };

            return $order;
        });
    }

    private function restock(Order $order, ?User $actor): void
    {
        $items = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('stock_deducted', true)
            ->orderBy('product_id')
            ->with('product')
            ->get();

        foreach ($items as $item) {
            $this->ledger->move($item->product, $item->quantity, 'cancellation', $actor, $order);
            $item->forceFill(['stock_deducted' => false])->save();
        }
    }

    private function ensureAllowed(Order $order, OrderStatus $status): void
    {
        if (! $order->status->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => __('A :from order cannot be marked :to.', [
                    'from' => strtolower($order->status->label()),
                    'to' => strtolower($status->labelFor($order->fulfilment)),
                ]),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function timestamps(Order $order, OrderStatus $status, ?string $reason): array
    {
        return match ($status) {
            OrderStatus::Confirmed => ['confirmed_at' => now()],
            OrderStatus::Ready => ['ready_at' => now(), 'confirmed_at' => $order->confirmed_at ?? now()],
            OrderStatus::Completed => ['completed_at' => now(), 'confirmed_at' => $order->confirmed_at ?? now()],
            OrderStatus::Cancelled => ['cancelled_at' => now(), 'cancellation_reason' => $reason],
            OrderStatus::Pending => [],
        };
    }
}
