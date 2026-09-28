<?php

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\Order;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Coupon codes on orders (offers module, ADR-020). A coupon takes a percentage (optionally capped) or
 * a fixed amount off the subtotal, never more than the subtotal. A use is claimed under a row lock
 * when the order is placed, so a usage limit can never be exceeded; cancelling the order gives the
 * use back.
 */
class Coupons
{
    public function __construct(private readonly TenantContext $context) {}

    public function enabled(): bool
    {
        return $this->context->hasModule('offers');
    }

    /**
     * The coupon for a code and the discount it gives on this subtotal. Null when no code was given.
     *
     * @return ?array{coupon: Coupon, discount: string}
     */
    public function resolve(?string $code, string $subtotal, bool $online, string $field = 'coupon_code'): ?array
    {
        $code = Coupon::normalizeCode((string) $code);

        if ($code === '') {
            return null;
        }

        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! $this->enabled()) {
            $fail(__('Coupon codes are not accepted.'));
        }

        $coupon = Coupon::query()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->first();

        if (! $coupon || ! $coupon->is_active || ($online && ! $coupon->online)) {
            $fail(__('This coupon code is not valid.'));
        }

        if ($coupon->starts_at?->isFuture()) {
            $fail(__('This coupon is not active yet.'));
        }

        if ($coupon->ends_at?->isPast()) {
            $fail(__('This coupon has expired.'));
        }

        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            $fail(__('This coupon has been fully used.'));
        }

        if ($coupon->min_subtotal !== null && bccomp($subtotal, (string) $coupon->min_subtotal, 2) < 0) {
            $fail(__('This coupon needs an order of at least :amount.', ['amount' => $coupon->min_subtotal]));
        }

        return ['coupon' => $coupon, 'discount' => self::discount($coupon, $subtotal)];
    }

    public static function discount(Coupon $coupon, string $subtotal): string
    {
        $discount = $coupon->type === 'percent'
            ? bcdiv(bcmul($subtotal, (string) $coupon->value, 4), '100', 2)
            : (string) $coupon->value;

        if ($coupon->max_discount !== null && bccomp($discount, (string) $coupon->max_discount, 2) > 0) {
            $discount = (string) $coupon->max_discount;
        }

        return bccomp($discount, $subtotal, 2) > 0 ? $subtotal : number_format((float) $discount, 2, '.', '');
    }

    /** Takes one use of the coupon, under a lock on its row (inside the order transaction). */
    public function claim(Coupon $coupon, string $field = 'coupon_code'): void
    {
        $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();

        if ($locked->usage_limit !== null && $locked->times_used >= $locked->usage_limit) {
            throw ValidationException::withMessages([$field => __('This coupon has been fully used.')]);
        }

        $locked->increment('times_used');
    }

    /** Gives back the use taken by a cancelled order. */
    public static function release(Order $order): void
    {
        if ($order->coupon_id) {
            Coupon::withTrashed()->whereKey($order->coupon_id)->where('times_used', '>', 0)->decrement('times_used');
        }
    }
}
