<?php

namespace App\Http\Presenters;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Food\Models\Reservation;

/** Browser-safe shapes for the food pages (tables, reservations, kitchen). */
final class FoodPresenter
{
    /** @return array<string, mixed> */
    public static function table(DiningTable $table): array
    {
        return [
            'id' => $table->id,
            'name' => $table->name,
            'seats' => $table->seats,
            'area' => $table->area,
            'is_active' => $table->is_active,
            'deleted' => $table->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    public static function reservation(Reservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'reserved_at' => $reservation->reserved_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'duration_minutes' => (int) $reservation->reserved_at->diffInMinutes($reservation->ends_at),
            'party_size' => $reservation->party_size,
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'holds_table' => $reservation->status->holdsTable(),
            'source' => $reservation->source,
            'source_label' => config("food.sources.{$reservation->source}", $reservation->source),
            'notes' => $reservation->notes,
            'cancellation_reason' => $reservation->cancellation_reason,
            'confirmed_at' => $reservation->confirmed_at?->toIso8601String(),
            'seated_at' => $reservation->seated_at?->toIso8601String(),
            'completed_at' => $reservation->completed_at?->toIso8601String(),
            'cancelled_at' => $reservation->cancelled_at?->toIso8601String(),
            'created_at' => $reservation->created_at?->toIso8601String(),
            'customer_id' => $reservation->customer_id,
            'customer' => $reservation->relationLoaded('customer') && $reservation->customer
                ? [
                    'id' => $reservation->customer->id,
                    'name' => $reservation->customer->name,
                    'phone' => $reservation->customer->phone,
                    'deleted' => $reservation->customer->trashed(),
                ]
                : null,
            'dining_table_id' => $reservation->dining_table_id,
            'table' => $reservation->relationLoaded('table') && $reservation->table
                ? ['id' => $reservation->table->id, 'name' => $reservation->table->name, 'seats' => $reservation->table->seats, 'deleted' => $reservation->table->trashed()]
                : null,
            'transitions' => array_map(
                fn (ReservationStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                $reservation->status->allowedTransitions(),
            ),
        ];
    }

    /** @return array<string, mixed> an order ticket for the kitchen screen */
    public static function ticket(Order $order): array
    {
        $queued = $order->items->where('kitchen_status', OrderItem::KITCHEN_QUEUED);

        return [
            'id' => $order->id,
            'reference' => $order->reference(),
            'status' => $order->status->value,
            'status_label' => $order->statusLabel(),
            'fulfilment' => $order->fulfilment,
            'fulfilment_label' => config("commerce.fulfilment.{$order->fulfilment}.label", $order->fulfilment),
            'table' => $order->table?->name,
            'customer' => $order->customer?->name,
            'notes' => $order->notes,
            'created_at' => $order->created_at?->toIso8601String(),
            'queued_since' => $queued->min('added_at')?->toIso8601String(),
            'items' => $queued->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'notes' => $item->notes,
                'added_later' => $item->added_at && $order->created_at && $item->added_at->diffInSeconds($order->created_at, true) > 60,
            ])->values()->all(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function statuses(): array
    {
        return array_map(fn (ReservationStatus $status) => ['value' => $status->value, 'label' => $status->label()], ReservationStatus::cases());
    }
}
