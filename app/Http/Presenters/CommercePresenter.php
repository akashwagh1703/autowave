<?php

namespace App\Http\Presenters;

use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\OrderPayment;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Domain\Commerce\Models\StockMovement;
use App\Domain\Tenant\Support\TenantContext;

/**
 * Browser-safe shapes for product and order pages. Only fields listed here reach the frontend.
 * Money is a decimal string ("1250.00"); timestamps are ISO-8601 UTC.
 */
final class CommercePresenter
{
    /** @return array<string, mixed> */
    public static function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'sku' => $product->sku,
            'price' => (string) $product->price,
            'compare_at_price' => $product->compare_at_price !== null ? (string) $product->compare_at_price : null,
            'is_active' => $product->is_active,
            'food_type' => $product->food_type,
            'food_type_label' => $product->food_type ? config("food.food_types.{$product->food_type}") : null,
            'is_available' => (bool) $product->is_available,
            'track_stock' => $product->track_stock,
            'stock_quantity' => $product->stock_quantity,
            'low_stock_threshold' => $product->low_stock_threshold,
            'low_stock_level' => $product->lowStockLevel(),
            'is_low_stock' => $product->isLowStock(),
            'in_stock' => $product->isInStock(),
            'product_category_id' => $product->product_category_id,
            'category' => $product->relationLoaded('category') && $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name]
                : null,
            'image' => $product->relationLoaded('image') && $product->image
                ? ['id' => $product->image->id, 'url' => $product->image->url()]
                : null,
            'deleted' => $product->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    public static function category(ProductCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'products_count' => $category->products_count ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public static function movement(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'quantity_change' => $movement->quantity_change,
            'balance_after' => $movement->balance_after,
            'reason' => $movement->reason,
            'reason_label' => $movement->reasonLabel(),
            'note' => $movement->note,
            'order' => $movement->order_id ? ['id' => $movement->order_id, 'reference' => $movement->relationLoaded('order') && $movement->order ? $movement->order->reference() : null] : null,
            'user' => $movement->relationLoaded('creator') && $movement->creator ? ['id' => $movement->creator->id, 'name' => $movement->creator->name] : null,
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function order(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'reference' => $order->reference(),
            'status' => $order->status->value,
            'status_label' => $order->statusLabel(),
            'is_open' => $order->status->isOpen(),
            'source' => $order->source,
            'source_label' => config("commerce.sources.{$order->source}", $order->source),
            'fulfilment' => $order->fulfilment,
            'fulfilment_label' => config("commerce.fulfilment.{$order->fulfilment}.label", $order->fulfilment),
            'subtotal' => (string) $order->subtotal,
            'discount' => (string) $order->discount,
            'coupon_code' => $order->coupon_code,
            'dining_table_id' => $order->dining_table_id,
            'table' => $order->dining_table_id && $order->relationLoaded('table') && $order->table
                ? ['id' => $order->table->id, 'name' => $order->table->name, 'deleted' => $order->table->trashed()]
                : null,
            'delivery_fee' => (string) $order->delivery_fee,
            'total' => (string) $order->total,
            'amount_paid' => (string) $order->amount_paid,
            'balance' => $order->balance(),
            'payment_status' => $order->payment_status->value,
            'payment_status_label' => $order->payment_status->label(),
            'delivery_address' => $order->delivery_address,
            'notes' => $order->notes,
            'cancellation_reason' => $order->cancellation_reason,
            'created_at' => $order->created_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'customer_id' => $order->customer_id,
            'customer' => $order->relationLoaded('customer') && $order->customer
                ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                    'email' => $order->customer->email,
                    'deleted' => $order->customer->trashed(),
                ]
                : null,
            'items' => $order->relationLoaded('items')
                ? $order->items->map(fn (OrderItem $item) => self::item($item))->values()->all()
                : null,
            'item_summary' => $order->relationLoaded('items') ? $order->itemSummary(3) : null,
            'items_count' => $order->relationLoaded('items') ? (int) $order->items->sum('quantity') : null,
            'payments' => $order->relationLoaded('payments')
                ? $order->payments->map(fn (OrderPayment $payment) => self::payment($payment))->values()->all()
                : null,
            'creator' => $order->relationLoaded('creator') && $order->creator
                ? ['id' => $order->creator->id, 'name' => $order->creator->name]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function item(OrderItem $item): array
    {
        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product_name,
            'sku' => $item->sku,
            'unit_price' => (string) $item->unit_price,
            'quantity' => $item->quantity,
            'line_total' => (string) $item->line_total,
            'notes' => $item->notes,
            'kitchen_status' => $item->kitchen_status,
            'added_at' => $item->added_at?->toIso8601String(),
            'product_available' => $item->relationLoaded('product') ? ($item->product !== null && ! $item->product->trashed()) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(OrderPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (string) $payment->amount,
            'method' => $payment->method,
            'method_label' => $payment->methodLabel(),
            'reference' => $payment->reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'recorded_by' => $payment->relationLoaded('recorder') && $payment->recorder ? $payment->recorder->name : null,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function statuses(): array
    {
        return array_map(fn (OrderStatus $status) => ['value' => $status->value, 'label' => $status->label()], OrderStatus::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function paymentStatuses(): array
    {
        return array_map(fn (PaymentStatus $status) => ['value' => $status->value, 'label' => $status->label()], PaymentStatus::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function paymentMethods(): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(config('commerce.payment_methods')), config('commerce.payment_methods'));
    }

    /** @return list<array{value: string, label: string, staff_only: bool}> methods the current tenant can use */
    public static function fulfilmentOptions(): array
    {
        $methods = PlaceOrder::staffFulfilment(app(TenantContext::class));

        return array_map(
            fn (string $value, array $method) => ['value' => $value, 'label' => $method['label'], 'staff_only' => (bool) ($method['staff_only'] ?? false)],
            array_keys($methods),
            $methods,
        );
    }

    /** @return list<array{value: string, label: string}> */
    public static function foodTypes(): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(config('food.food_types')), config('food.food_types'));
    }

    /** @return list<array{value: string, label: string}> */
    public static function sources(): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(config('commerce.sources')), config('commerce.sources'));
    }

    /** @return list<array{value: string, label: string}> manual stock adjustment reasons */
    public static function stockReasons(): array
    {
        return collect(config('commerce.stock_reasons'))
            ->filter(fn (array $reason) => $reason['manual'] ?? false)
            ->map(fn (array $reason, string $value) => ['value' => $value, 'label' => $reason['label']])
            ->values()
            ->all();
    }
}
