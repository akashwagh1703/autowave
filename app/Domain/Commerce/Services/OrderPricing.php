<?php

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Prices a list of {product_id, quantity} from the database: the browser never supplies prices.
 * Duplicate products are merged. Only active, live products of the current tenant can be priced
 * (tenant scope), and tracked products cannot exceed their stock. Money is exact (bcmath, 2 dp).
 */
class OrderPricing
{
    /**
     * @param  mixed  $items  raw input
     * @return list<array{product_id: int, quantity: int}>
     */
    public static function normalize(mixed $items): array
    {
        $limits = config('commerce.limits');

        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:'.$limits['items_per_order']],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.$limits['max_quantity']],
        ], [
            'items.required' => __('Add at least one product.'),
            'items.min' => __('Add at least one product.'),
            'items.max' => __('An order can have up to :max different products.', ['max' => $limits['items_per_order']]),
            'items.*.quantity.max' => __('You can order up to :max of a product.', ['max' => $limits['max_quantity']]),
        ])->validate();

        $merged = [];

        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $merged[$id] = min(($merged[$id] ?? 0) + (int) $item['quantity'], (int) $limits['max_quantity']);
        }

        return array_map(fn (int $id, int $quantity) => ['product_id' => $id, 'quantity' => $quantity], array_keys($merged), $merged);
    }

    /**
     * Lines and subtotal. Lines whose product is gone, inactive or short of stock carry an `issue`.
     *
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return array{lines: list<array{product: ?Product, product_id: int, name: ?string, sku: ?string, unit_price: string, quantity: int, line_total: string, available: ?int, issue: ?string}>, subtotal: string, issues: list<string>}
     */
    public function lines(array $items): array
    {
        /** @var Collection<int, Product> $products */
        $products = Product::query()->active()->whereKey(array_column($items, 'product_id'))->get()->keyBy('id');
        $lines = [];
        $subtotal = '0.00';
        $issues = [];

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $issue = match (true) {
                $product === null => __('One of the products is no longer available.'),
                ! $product->is_available => __(':name is not available right now.', ['name' => $product->name]),
                $product->track_stock && $product->stock_quantity <= 0 => __(':name is out of stock.', ['name' => $product->name]),
                $product->track_stock && $product->stock_quantity < $item['quantity'] => __('Only :count of :name left in stock.', ['count' => $product->stock_quantity, 'name' => $product->name]),
                default => null,
            };

            $unit = $product ? number_format((float) $product->price, 2, '.', '') : '0.00';
            $lineTotal = bcmul($unit, (string) $item['quantity'], 2);

            if ($product && $issue === null) {
                $subtotal = bcadd($subtotal, $lineTotal, 2);
            }

            if ($issue !== null) {
                $issues[] = $issue;
            }

            $lines[] = [
                'product' => $product,
                'product_id' => $item['product_id'],
                'name' => $product?->name,
                'sku' => $product?->sku,
                'unit_price' => $unit,
                'quantity' => $item['quantity'],
                'line_total' => $lineTotal,
                'available' => $product?->available(),
                'issue' => $issue,
            ];
        }

        return ['lines' => $lines, 'subtotal' => $subtotal, 'issues' => $issues];
    }

    /**
     * Like lines(), but any issue is a validation error (used when placing an order).
     *
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return array{lines: list<array<string, mixed>>, subtotal: string, issues: list<string>}
     */
    public function strict(array $items): array
    {
        $priced = $this->lines($items);

        if ($priced['issues'] !== []) {
            throw ValidationException::withMessages(['items' => $priced['issues'][0]]);
        }

        return $priced;
    }

    public static function money(mixed $value): string
    {
        return number_format(max(0, (float) $value), 2, '.', '');
    }
}
