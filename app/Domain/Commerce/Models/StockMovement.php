<?php

namespace App\Domain\Commerce\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a product's stock (the ledger). Written only by StockLedger, never edited:
 * `balance_after` is the product's stock right after the change.
 */
#[Fillable(['tenant_id', 'product_id', 'quantity_change', 'balance_after', 'reason', 'order_id', 'note', 'created_by_user_id', 'created_at'])]
class StockMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reasonLabel(): string
    {
        return config("commerce.stock_reasons.{$this->reason}.label", $this->reason);
    }
}
