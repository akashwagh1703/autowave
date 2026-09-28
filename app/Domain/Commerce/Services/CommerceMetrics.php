<?php

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Product;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Live figures for the commerce dashboard widgets. Computed only when the commerce engine is on;
 * order figures need `orders.view`, stock figures `products.view` and money figures `reports.view`.
 * Revenue counts completed orders only.
 */
class CommerceMetrics
{
    public const SALES_DAYS = 30;

    public const TOP_PRODUCTS = 5;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $widgets
     * @return array<string, array{value: int|float|string, type: string, hint: string, href: ?string}>
     */
    public function for(User $user, array $widgets): array
    {
        if (! $this->context->hasEngine('commerce')) {
            return [];
        }

        $orders = $user->can('orders.view');
        $money = $orders && $user->can('reports.view');
        $todayStart = TenantTime::now()->startOfDay()->utc();
        $metrics = [];

        foreach ($widgets as $widget) {
            $metric = match (true) {
                $orders && $widget === 'orders_today' => [
                    'value' => Order::query()->notCancelled()->where('created_at', '>=', $todayStart)->count(),
                    'type' => 'number',
                    'hint' => 'Placed today',
                    'href' => route('orders.index', ['status' => 'all', 'range' => 'today']),
                ],
                $money && $widget === 'revenue_today' => [
                    'value' => $this->money(Order::query()->where('status', OrderStatus::Completed)->where('completed_at', '>=', $todayStart)->sum('total')),
                    'type' => 'currency',
                    'hint' => 'Completed orders today',
                    'href' => route('orders.index', ['status' => 'completed', 'range' => 'today']),
                ],
                $money && $widget === 'product_sales' => [
                    'value' => $this->money(OrderItem::query()
                        ->whereHas('order', fn (Builder $order) => $order->where('status', OrderStatus::Completed)->where('completed_at', '>=', now()->subDays(self::SALES_DAYS)))
                        ->sum('line_total')),
                    'type' => 'currency',
                    'hint' => 'Products sold, last '.self::SALES_DAYS.' days',
                    'href' => route('orders.index', ['status' => 'completed', 'range' => '30d']),
                ],
                $widget === 'low_stock' && $user->can('products.view') => [
                    'value' => Product::query()->active()->lowStock()->count(),
                    'type' => 'number',
                    'hint' => 'Products at or below their low-stock level',
                    'href' => route('products.index', ['stock' => 'low']),
                ],
                // With the booking engine, BookingMetrics counts repeat visits instead.
                $orders && $widget === 'top_products' => $this->topProducts(),
                $orders && $widget === 'kitchen_queue' && $this->context->hasEngine('food') => [
                    'value' => Order::query()
                        ->whereIn('status', [OrderStatus::Confirmed, OrderStatus::Ready])
                        ->whereHas('items', fn (Builder $items) => $items->where('kitchen_status', OrderItem::KITCHEN_QUEUED))
                        ->count(),
                    'type' => 'number',
                    'hint' => 'Orders waiting in the kitchen',
                    'href' => route('kitchen.index'),
                ],
                $orders && $widget === 'repeat_customers' && ! $this->context->hasEngine('booking') => [
                    'value' => Order::query()
                        ->where('status', OrderStatus::Completed)
                        ->whereNotNull('customer_id')
                        ->groupBy('customer_id')
                        ->havingRaw('count(*) >= 2')
                        ->select('customer_id')
                        ->get()
                        ->count(),
                    'type' => 'number',
                    'hint' => 'Customers with 2+ completed orders',
                    'href' => null,
                ],
                default => null,
            };

            if ($metric) {
                $metrics[$widget] = $metric;
            }
        }

        return $metrics;
    }

    /**
     * Best sellers by quantity over the last SALES_DAYS days (completed orders).
     *
     * @return array{value: string, type: string, hint: string, href: ?string, items: list<array{label: string, value: int}>}
     */
    private function topProducts(): array
    {
        $rows = OrderItem::query()
            ->whereHas('order', fn (Builder $order) => $order->where('status', OrderStatus::Completed)->where('completed_at', '>=', now()->subDays(self::SALES_DAYS)))
            ->selectRaw('product_name, sum(quantity) as sold')
            ->groupBy('product_name')
            ->orderByDesc('sold')->orderBy('product_name')
            ->limit(self::TOP_PRODUCTS)
            ->get();

        return [
            'value' => $rows->first()?->product_name ?? '—',
            'type' => 'list',
            'hint' => 'Best sellers, last '.self::SALES_DAYS.' days',
            'href' => route('products.index'),
            'items' => $rows->map(fn ($row) => ['label' => $row->product_name, 'value' => (int) $row->sold])->all(),
        ];
    }

    private function money(mixed $sum): string
    {
        return number_format((float) ($sum ?? 0), 2, '.', '');
    }
}
