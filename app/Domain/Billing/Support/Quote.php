<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Models\BillingCoupon;
use App\Domain\Billing\Models\Plan;
use Illuminate\Support\Carbon;

/** What a plan period costs a business right now. Amounts in paise: amount = price − credit − discount. */
final readonly class Quote
{
    /** @param  list<array{label: string, rate: float, amount: int}>  $tax */
    public function __construct(
        public Plan $plan,
        public string $period,
        public string $kind,
        public int $price,
        public int $credit,
        public int $amount,
        public array $tax,
        public int $taxAmount,
        public int $total,
        public Carbon $from,
        public Carbon $until,
        public int $discount = 0,
        public ?BillingCoupon $coupon = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan->code,
            'plan_name' => $this->plan->name,
            'period' => $this->period,
            'kind' => $this->kind,
            'price' => $this->price,
            'credit' => $this->credit,
            'discount' => $this->discount,
            'coupon' => $this->coupon?->code,
            'amount' => $this->amount,
            'tax' => $this->tax,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
            'from' => $this->from->toIso8601String(),
            'until' => $this->until->toIso8601String(),
        ];
    }

    /** Columns every new payment for this quote gets. */
    public function paymentAttributes(): array
    {
        return [
            'plan_id' => $this->plan->id,
            'period' => $this->period,
            'kind' => $this->kind,
            'credit' => $this->credit,
            'discount' => $this->discount,
            'coupon_id' => $this->coupon?->id,
            'coupon_code' => $this->coupon?->code,
            'amount' => $this->amount,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
            'tax' => $this->tax ?: null,
        ];
    }
}
