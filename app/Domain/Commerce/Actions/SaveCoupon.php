<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Coupon;
use Illuminate\Validation\ValidationException;

/** Creates or updates a coupon code. Codes are unique per tenant (case-insensitive), stored upper-case. */
class SaveCoupon
{
    public const MAX_COUPONS = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, description?: ?string, type: string, value: numeric-string|float, min_subtotal?: numeric-string|float|null, max_discount?: numeric-string|float|null, starts_at?: ?\DateTimeInterface, ends_at?: ?\DateTimeInterface, usage_limit?: ?int, online?: bool, is_active?: bool}  $data
     */
    public function handle(array $data, ?Coupon $coupon = null): Coupon
    {
        $code = Coupon::normalizeCode($data['code']);

        if (! preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) {
            throw ValidationException::withMessages(['code' => __('Use 3–30 letters, numbers, dashes or underscores.')]);
        }

        $taken = Coupon::query()
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->when($coupon, fn ($query) => $query->whereKeyNot($coupon->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => __('This code is already used by another coupon.')]);
        }

        if (! $coupon && Coupon::query()->count() >= self::MAX_COUPONS) {
            throw ValidationException::withMessages(['code' => __('You can have at most :max coupons.', ['max' => self::MAX_COUPONS])]);
        }

        $type = $data['type'];
        $value = (float) $data['value'];

        if (! in_array($type, ['percent', 'fixed'], true) || $value <= 0 || ($type === 'percent' && $value > 100)) {
            throw ValidationException::withMessages(['value' => $type === 'percent' ? __('Enter a percentage between 1 and 100.') : __('Enter an amount greater than zero.')]);
        }

        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $data['ends_at'] ?? null;

        if ($startsAt && $endsAt && $endsAt <= $startsAt) {
            throw ValidationException::withMessages(['ends_at' => __('The end must be after the start.')]);
        }

        $money = fn ($amount) => $amount === null || $amount === '' ? null : number_format((float) $amount, 2, '.', '');

        $attributes = [
            'code' => $code,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'type' => $type,
            'value' => number_format($value, 2, '.', ''),
            'min_subtotal' => $money($data['min_subtotal'] ?? null),
            'max_discount' => $type === 'percent' ? $money($data['max_discount'] ?? null) : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'usage_limit' => ! empty($data['usage_limit']) ? (int) $data['usage_limit'] : null,
            'online' => (bool) ($data['online'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if ($coupon) {
            $coupon->update($attributes);
        } else {
            $coupon = Coupon::query()->create($attributes);
        }

        $this->audit->log($coupon->wasRecentlyCreated ? 'coupon.created' : 'coupon.updated', $coupon, ['code' => $coupon->code]);

        return $coupon;
    }
}
