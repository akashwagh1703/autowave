<?php

namespace App\Domain\Commerce\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A coupon code (offers module, ADR-020): a percentage or fixed amount off an order's subtotal, with
 * optional minimum subtotal, cap, validity window and usage limit. `online` allows it at the website
 * checkout; the team can apply any active coupon.
 */
#[Fillable([
    'tenant_id', 'code', 'description', 'type', 'value', 'min_subtotal', 'max_discount', 'starts_at', 'ends_at',
    'usage_limit', 'times_used', 'online', 'is_active',
])]
class Coupon extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $attributes = [
        'times_used' => 0,
        'online' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_subtotal' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'times_used' => 'integer',
            'online' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $code) ?? $code);
    }

    /** "10% off" / "₹100 off" style summary without currency formatting. */
    public function summary(): string
    {
        return $this->type === 'percent'
            ? rtrim(rtrim((string) $this->value, '0'), '.').'% off'
            : (string) $this->value.' off';
    }
}
