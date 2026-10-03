<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Models\BillingCoupon;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Whether a business may use a coupon code for a plan and period. With `$lock` the coupon row is locked so
 * two businesses can't take the last redemption at once (call inside the transaction that creates the
 * payment). The business's own unfinished online checkouts don't count: starting a new payment expires them.
 */
class Coupons
{
    /** @throws ValidationException */
    public function resolve(?string $code, Tenant $tenant, Plan $plan, string $period, bool $lock = false): ?BillingCoupon
    {
        $code = BillingCoupon::normalizeCode($code);

        if ($code === '') {
            return null;
        }

        $coupon = BillingCoupon::query()->where('code', $code)->when($lock, fn ($query) => $query->lockForUpdate())->first();

        if (! $coupon || ! $coupon->isLive()) {
            $this->fail(__('This coupon code is not valid, or has expired.'));
        }

        if (! $coupon->appliesTo($plan->code, $period)) {
            $this->fail(__('This coupon can\'t be used for the :plan plan (:period).', [
                'plan' => $plan->name,
                'period' => strtolower((string) config("billing.periods.{$period}.label")),
            ]));
        }

        $payments = fn () => BillingPayment::withoutTenantScope()->where('tenant_id', $tenant->getKey());

        if ($coupon->first_payment_only && $payments()->where('status', BillingPayment::APPROVED)->where('total', '>', 0)->exists()) {
            $this->fail(__('This coupon is only for a business\'s first payment.'));
        }

        if ($coupon->once_per_business && $payments()->where('coupon_id', $coupon->id)->whereIn('status', [BillingPayment::PENDING, BillingPayment::APPROVED])->exists()) {
            $this->fail(__('Your business has already used this coupon.'));
        }

        if ($coupon->max_redemptions !== null) {
            $used = BillingPayment::withoutTenantScope()
                ->where('coupon_id', $coupon->id)
                ->whereIn('status', BillingCoupon::REDEEMING)
                ->where(fn ($query) => $query->where('tenant_id', '!=', $tenant->getKey())->orWhere('status', '!=', BillingPayment::INITIATED))
                ->count();

            if ($used >= $coupon->max_redemptions) {
                $this->fail(__('This coupon has been fully used.'));
            }
        }

        return $coupon;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['coupon' => $message]);
    }
}
