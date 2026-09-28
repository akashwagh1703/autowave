<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a product. Names and SKUs are unique per tenant among live products
 * (case-insensitive); the category is resolved inside the current tenant. Stock itself only
 * changes through StockLedger: a new tracked product records its opening stock there.
 */
class SaveProduct
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, description?: ?string, product_category_id?: ?int, sku?: ?string, price: numeric-string|float|int, compare_at_price?: numeric-string|float|int|null, is_active?: bool, track_stock?: bool, low_stock_threshold?: ?int, opening_stock?: ?int}  $data
     */
    public function handle(array $data, ?Product $product = null, ?User $actor = null): Product
    {
        $sku = filled($data['sku'] ?? null) ? trim($data['sku']) : null;
        $this->ensureUnique('name', $data['name'], $product?->id, __('A product with this name already exists.'));

        if ($sku !== null) {
            $this->ensureUnique('sku', $sku, $product?->id, __('Another product already uses this SKU.'));
        }

        $categoryId = $data['product_category_id'] ?? null;

        if ($categoryId && ! ProductCategory::query()->whereKey($categoryId)->exists()) {
            throw ValidationException::withMessages(['product_category_id' => __('Choose a valid category.')]);
        }

        $price = number_format((float) $data['price'], 2, '.', '');
        $compareAt = isset($data['compare_at_price']) && $data['compare_at_price'] !== '' && $data['compare_at_price'] !== null
            ? number_format((float) $data['compare_at_price'], 2, '.', '')
            : null;

        if ($compareAt !== null && bccomp($compareAt, $price, 2) <= 0) {
            throw ValidationException::withMessages(['compare_at_price' => __('The original price must be higher than the price.')]);
        }

        $trackStock = (bool) ($data['track_stock'] ?? false);

        return DB::transaction(function () use ($data, $product, $actor, $sku, $categoryId, $price, $compareAt, $trackStock) {
            $attributes = [
                'name' => $data['name'],
                'description' => filled($data['description'] ?? null) ? $data['description'] : null,
                'product_category_id' => $categoryId,
                'sku' => $sku,
                'price' => $price,
                'compare_at_price' => $compareAt,
                'is_active' => $data['is_active'] ?? true,
                ...(array_key_exists('food_type', $data) ? ['food_type' => array_key_exists((string) $data['food_type'], config('food.food_types')) ? $data['food_type'] : null] : []),
                ...(array_key_exists('is_available', $data) ? ['is_available' => (bool) $data['is_available']] : []),
                'track_stock' => $trackStock,
                'low_stock_threshold' => $trackStock && isset($data['low_stock_threshold']) && $data['low_stock_threshold'] !== ''
                    ? (int) $data['low_stock_threshold']
                    : null,
            ];

            if ($product) {
                $product->update($attributes);

                return $product;
            }

            $product = Product::query()->create([
                ...$attributes,
                'sort_order' => (int) Product::query()->max('sort_order') + 10,
                'created_by_user_id' => $actor?->id,
            ]);

            $opening = (int) ($data['opening_stock'] ?? 0);

            if ($trackStock && $opening > 0) {
                $this->ledger->move($product, $opening, 'opening', $actor, field: 'opening_stock');
            }

            $this->audit->log('product.created', $product, ['name' => $product->name]);

            return $product;
        });
    }

    private function ensureUnique(string $column, string $value, ?int $ignoreId, string $message): void
    {
        $taken = Product::query()
            ->whereRaw("lower({$column}) = ?", [mb_strtolower($value)])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$column => $message]);
        }
    }
}
