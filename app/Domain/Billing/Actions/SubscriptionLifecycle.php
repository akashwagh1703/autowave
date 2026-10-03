<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Billing\Support\PriceCalculator;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Changes to a business's subscription: the trial at sign-up, an approved payment (any method), a
 * scheduled plan change taking effect, and Super Admin adjustments.
 */
class SubscriptionLifecycle
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /** The free trial for a new business; the internal business gets a plan that never ends. Idempotent. */
    public function startTrial(Tenant $tenant, ?Carbon $now = null): Subscription
    {
        $now ??= now();
        $existing = Subscription::withoutTenantScope()->where('tenant_id', $tenant->getKey())->first();

        if ($existing) {
            return $existing;
        }

        $internal = (bool) $tenant->is_internal;
        $plan = self::plan($internal ? config('billing.internal_plan') : config('billing.trial.plan'));

        $subscription = new Subscription([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->id,
            'is_trial' => ! $internal,
            'starts_at' => $now,
            'ends_at' => $internal ? null : $now->copy()->addDays((int) config('billing.trial.days')),
        ]);
        $subscription->save();
        $this->entitlements->forget($tenant);

        return $subscription;
    }

    /**
     * Applies an approved payment. Call inside a transaction.
     *
     * @return array{0: Carbon, 1: Carbon} the period it pays for
     */
    public function apply(BillingPayment $payment, ?Carbon $now = null): array
    {
        $now ??= now();
        $subscription = Subscription::withoutTenantScope()->where('tenant_id', $payment->tenant_id)->lockForUpdate()->first()
            ?? new Subscription(['tenant_id' => $payment->tenant_id, 'plan_id' => $payment->plan_id]);

        $this->settle($subscription, $now);
        $running = $subscription->exists && $subscription->ends_at?->gt($now);

        if ($payment->kind === BillingPayment::KIND_NOW || ! $running) {
            $from = $now->copy();
            $until = PriceCalculator::periodEnd($from, $payment->period);
            $subscription->fill([
                'plan_id' => $payment->plan_id,
                'period' => $payment->period,
                'starts_at' => $from,
                'next_plan_id' => null,
                'next_period' => null,
                'plan_changes_at' => null,
            ]);
        } else {
            $from = $subscription->ends_at->copy();
            $until = PriceCalculator::periodEnd($from, $payment->period);
            $planAtEnd = $subscription->next_plan_id ?? $subscription->plan_id;
            $periodAtEnd = $subscription->next_plan_id ? $subscription->next_period : $subscription->period;

            if ($subscription->is_trial || $planAtEnd !== $payment->plan_id || $periodAtEnd !== $payment->period) {
                $subscription->fill([
                    'next_plan_id' => $payment->plan_id,
                    'next_period' => $payment->period,
                    'plan_changes_at' => $subscription->plan_changes_at ?? $from,
                ]);
            }
        }

        $subscription->fill(['is_trial' => false, 'ends_at' => $until, 'reminders' => null])->save();
        $this->forget($payment->tenant_id);

        return [$from, $until];
    }

    /** Makes a scheduled plan change permanent once its date has passed. Does not save. */
    public function settle(Subscription $subscription, ?Carbon $now = null): bool
    {
        $now ??= now();

        if (! $subscription->next_plan_id || ! $subscription->plan_changes_at || $now->lt($subscription->plan_changes_at)) {
            return false;
        }

        $subscription->fill([
            'plan_id' => $subscription->next_plan_id,
            'period' => $subscription->next_period,
            'starts_at' => $subscription->plan_changes_at,
            'next_plan_id' => null,
            'next_period' => null,
            'plan_changes_at' => null,
        ]);

        return true;
    }

    /** Super Admin: set the plan and end date directly (corrections, free months). Null end = never ends. */
    public function adjust(Tenant $tenant, Plan $plan, ?Carbon $endsAt, ?string $period = null): Subscription
    {
        $subscription = Subscription::withoutTenantScope()->where('tenant_id', $tenant->getKey())->first()
            ?? new Subscription(['tenant_id' => $tenant->getKey(), 'starts_at' => now()]);

        $subscription->fill([
            'plan_id' => $plan->id,
            'period' => $plan->isTrial() ? null : ($period ?? $subscription->period ?? 'monthly'),
            'is_trial' => $plan->isTrial(),
            'ends_at' => $endsAt,
            'next_plan_id' => null,
            'next_period' => null,
            'plan_changes_at' => null,
            'reminders' => null,
        ])->save();
        $this->entitlements->forget($tenant);

        return $subscription;
    }

    public static function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->first()
            ?? throw new RuntimeException("Plan [{$code}] is missing; run PlanSeeder.");
    }

    private function forget(int $tenantId): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant) {
            $this->entitlements->forget($tenant);
        }
    }
}
