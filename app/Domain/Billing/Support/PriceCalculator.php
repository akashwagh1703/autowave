<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Models\BillingCoupon;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The only place amounts are worked out, for every payment method (the browser only sends a plan and
 * period).
 *
 * - Nothing is running (the trial or period ended): the new period starts now at the full price.
 * - A more expensive plan while a paid period runs: starts now; the unused days of the current period are
 *   credited.
 * - During the trial, or the same plan, a cheaper one or another period length: starts when the current
 *   trial or period ends, so no paid or trial days are lost.
 * - While a chosen plan is waiting to start (paid during the trial, or a cheaper plan at renewal), only that
 *   plan can be renewed; switching again is possible once it has started.
 *
 * A coupon comes off after the credit and before GST.
 *
 * GST is added only while it is switched on: CGST + SGST inside the seller's state, IGST otherwise (the
 * buyer's state is read from their GSTIN; without one, the seller's state).
 */
class PriceCalculator
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly BillingSettings $settings,
    ) {}

    public function quote(Tenant $tenant, Plan $plan, string $period, ?string $buyerGstin = null, ?BillingCoupon $coupon = null, ?Carbon $now = null): Quote
    {
        $now ??= now();
        $subscription = $this->entitlements->subscription($tenant);
        $price = $plan->price($period);
        $running = $subscription && $subscription->ends_at?->gt($now);
        $current = $subscription?->planAt($now);
        $scheduled = $running && $subscription->next_plan_id && $subscription->plan_changes_at?->gt($now);
        $credit = 0;

        if ($scheduled && ($subscription->next_plan_id !== $plan->id || $subscription->next_period !== $period)) {
            throw ValidationException::withMessages(['plan' => __('The :plan plan (:period) starts on :date. You can renew it now, or switch plans after it starts.', [
                'plan' => $subscription->nextPlan->name,
                'period' => strtolower((string) config("billing.periods.{$subscription->next_period}.label")),
                'date' => $subscription->plan_changes_at->copy()->timezone('Asia/Kolkata')->format('j M Y'),
            ])]);
        }

        if (! $running) {
            $kind = BillingPayment::KIND_NOW;
            $from = $now->copy();
        } elseif (! $scheduled && ! $current->isTrial() && $plan->price_monthly > $current->price_monthly) {
            $kind = BillingPayment::KIND_NOW;
            $from = $now->copy();
            $currentPeriod = $subscription->periodAt($now) ?? 'monthly';
            $remaining = $now->diffInSeconds($subscription->ends_at);
            $length = (int) config("billing.periods.{$currentPeriod}.days") * 86400;
            $credit = min($price, (int) floor($current->price($currentPeriod) * $remaining / max(1, $length)));
        } else {
            $kind = BillingPayment::KIND_RENEWAL;
            $from = $subscription->ends_at->copy();
        }

        $beforeDiscount = max(0, $price - $credit);
        $discount = $coupon?->discountOn($beforeDiscount) ?? 0;
        $amount = $beforeDiscount - $discount;
        $tax = $this->tax($amount, $buyerGstin);
        $taxAmount = array_sum(array_column($tax, 'amount'));

        return new Quote($plan, $period, $kind, $price, $credit, $amount, $tax, $taxAmount, $amount + $taxAmount, $from, self::periodEnd($from, $period), $discount, $coupon);
    }

    public static function periodEnd(Carbon $from, string $period): Carbon
    {
        return $from->copy()->addMonthsNoOverflow((int) config("billing.periods.{$period}.months"));
    }

    /** @return list<array{label: string, rate: float, amount: int}> */
    public function tax(int $amount, ?string $buyerGstin = null): array
    {
        if (! $this->settings->gstEnabled() || $amount === 0) {
            return [];
        }

        $rate = (float) config('billing.gst.rate');
        $sellerState = (string) ($this->settings->get('gst.state_code') ?: substr((string) $this->settings->get('gst.gstin'), 0, 2));
        $buyerState = $buyerGstin ? substr($buyerGstin, 0, 2) : $sellerState;

        if ($buyerState !== $sellerState) {
            return [['label' => 'IGST', 'rate' => $rate, 'amount' => (int) round($amount * $rate / 100)]];
        }

        $half = (int) round($amount * $rate / 200);

        return [
            ['label' => 'CGST', 'rate' => $rate / 2, 'amount' => $half],
            ['label' => 'SGST', 'rate' => $rate / 2, 'amount' => $half],
        ];
    }
}
