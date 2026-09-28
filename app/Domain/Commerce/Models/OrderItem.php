<?php

namespace App\Domain\Commerce\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of an order. Name, SKU and price are copied from the product when ordered.
 * `stock_deducted` records whether the line took stock, so cancelling returns exactly that.
 */
#[Fillable(['tenant_id', 'order_id', 'product_id', 'product_name', 'sku', 'unit_price', 'quantity', 'line_total', 'stock_deducted'])]
class OrderItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'line_total' => 'decimal:2',
            'stock_deducted' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
