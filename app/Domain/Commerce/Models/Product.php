<?php

namespace App\Domain\Commerce\Models;

use App\Domain\Media\Models\Media;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something the business sells by quantity (master prompt §29–30). Stock is tracked only when
 * `track_stock` is on; `stock_quantity` then never drops below zero (database check) and every
 * change is written to stock_movements.
 */
#[Fillable([
    'tenant_id', 'product_category_id', 'name', 'description', 'sku', 'price', 'compare_at_price',
    'image_media_id', 'is_active', 'track_stock', 'stock_quantity', 'low_stock_threshold', 'sort_order',
    'created_by_user_id',
])]
class Product extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $attributes = [
        'is_active' => true,
        'track_stock' => false,
        'stock_quantity' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** How many can be sold now; null when stock is not tracked. */
    public function available(): ?int
    {
        return $this->track_stock ? max(0, $this->stock_quantity) : null;
    }

    public function isInStock(): bool
    {
        return ! $this->track_stock || $this->stock_quantity > 0;
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->stock_quantity <= $this->lowStockLevel();
    }

    public function lowStockLevel(): int
    {
        return $this->low_stock_threshold ?? (int) config('commerce.low_stock_threshold');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    /** Tracked products at or below their low-stock level (null threshold: the config default). */
    public function scopeLowStock(Builder $query): void
    {
        $query->where('track_stock', true)
            ->whereRaw('stock_quantity <= coalesce(low_stock_threshold, ?)', [(int) config('commerce.low_stock_threshold')]);
    }
}
