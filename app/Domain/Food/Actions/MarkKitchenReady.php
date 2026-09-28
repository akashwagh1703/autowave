<?php

namespace App\Domain\Food\Actions;

use App\Domain\Commerce\Actions\ChangeOrderStatus;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The kitchen marks an order's queued items as ready (a KOT is done). A confirmed order then moves
 * to Ready ("Ready to serve", "Ready for pickup"…), which fires the order.ready automation.
 */
class MarkKitchenReady
{
    public function __construct(private readonly ChangeOrderStatus $changeStatus) {}

    public function handle(Order $order, ?User $actor = null): int
    {
        return DB::transaction(function () use ($order, $actor) {
            $marked = OrderItem::query()
                ->where('order_id', $order->id)
                ->where('kitchen_status', OrderItem::KITCHEN_QUEUED)
                ->update(['kitchen_status' => OrderItem::KITCHEN_READY]);

            if ($marked === 0) {
                throw ValidationException::withMessages(['order' => __('Nothing is waiting in the kitchen for this order.')]);
            }

            if (in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)) {
                $this->changeStatus->handle($order, OrderStatus::Ready, $actor);
            }

            return $marked;
        });
    }
}
