<?php

namespace App\Domain\Commerce\Models;

use App\Domain\Activity\Models\Activity;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's order of products (a dine-in order may have no customer: a walk-in at a table).
 * `number` is sequential per tenant (#1001, #1002…). Items keep a
 * copy of the product name and price at the time of the order. Never deleted: cancellation is a
 * status. Money columns always satisfy total = subtotal − discount + delivery_fee and
 * amount_paid ≤ total (database checks).
 */
#[Fillable([
    'tenant_id', 'number', 'customer_id', 'dining_table_id', 'status', 'source', 'fulfilment', 'subtotal', 'discount',
    'coupon_id', 'coupon_code', 'delivery_fee', 'total', 'amount_paid', 'payment_status', 'delivery_address', 'notes',
    'confirmed_at', 'ready_at', 'completed_at', 'cancelled_at', 'cancellation_reason', 'created_by_user_id',
])]
class Order extends Model
{
    use BelongsToTenant;

    public const FIRST_NUMBER = 1001;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id')->withTrashed();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reference(): string
    {
        return '#'.$this->number;
    }

    public function statusLabel(): string
    {
        return $this->status->labelFor($this->fulfilment);
    }

    /** Amount still to be paid. */
    public function balance(): string
    {
        return bcsub((string) $this->total, (string) $this->amount_paid, 2);
    }

    /** "2 × Hair serum, 1 × Shampoo" (items must be loaded or are queried). */
    public function itemSummary(int $limit = 5): string
    {
        $items = $this->items;
        $parts = $items->take($limit)->map(fn (OrderItem $item) => $item->quantity.' × '.$item->product_name)->all();

        return implode(', ', $parts).($items->count() > $limit ? ' and '.($items->count() - $limit).' more' : '');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', OrderStatus::OPEN);
    }

    public function scopeNotCancelled(Builder $query): void
    {
        $query->where('status', '!=', OrderStatus::Cancelled->value);
    }
}
