<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A discount code for AutoWave plans (platform data, not tenant-scoped), made in Super Admin → Coupons.
 * `percent` takes `value`% off; `fixed` takes `value` paise off. Applied after any upgrade credit and before
 * GST. `plans` / `periods` null = any. A redemption is a payment with this coupon that is checking out,
 * waiting for approval or approved, so abandoned checkouts and rejected payments free it again.
 */
#[Fillable([
    'code', 'description', 'type', 'value', 'plans', 'periods', 'max_redemptions', 'once_per_business', 'first_payment_only',
    'starts_at', 'ends_at', 'is_active', 'created_by_user_id',
])]
class BillingCoupon extends Model
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9\-]{2,29}$/';

    /** Payment statuses that hold a redemption. */
    public const REDEEMING = [BillingPayment::INITIATED, BillingPayment::PENDING, BillingPayment::APPROVED];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'plans' => 'array',
            'periods' => 'array',
            'max_redemptions' => 'integer',
            'once_per_business' => 'boolean',
            'first_payment_only' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class, 'coupon_id');
    }

    public static function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    /** The discount on `$amount` paise, never more than the amount. */
    public function discountOn(int $amount): int
    {
        $discount = $this->type === self::PERCENT
            ? (int) floor($amount * min(100, $this->value) / 100)
            : $this->value;

        return max(0, min($amount, $discount));
    }

    public function appliesTo(string $planCode, string $period): bool
    {
        return (empty($this->plans) || in_array($planCode, $this->plans, true))
            && (empty($this->periods) || in_array($period, $this->periods, true));
    }

    public function isLive(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gt($now));
    }

    public function redemptions(): int
    {
        return BillingPayment::withoutTenantScope()->where('coupon_id', $this->id)->whereIn('status', self::REDEEMING)->count();
    }
}
