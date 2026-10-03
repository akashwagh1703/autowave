<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\SubscriptionState;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * What a business may do under its plan: limits, and whether it can make changes (`full`), only look
 * (`read_only`) or only reach Billing (`locked`). Worked out from the subscription dates and approved
 * payments only, never from how a period was paid. Scoped: one instance per request or queued job.
 */
class Entitlements
{
    public const FULL = 'full';

    public const READ_ONLY = 'read_only';

    public const LOCKED = 'locked';

    /** @var array<int, ?Subscription> */
    private array $subscriptions = [];

    /** @var array<int, bool> */
    private array $pending = [];

    public function __construct(private readonly BillingSettings $settings) {}

    public function subscription(Tenant $tenant): ?Subscription
    {
        return $this->subscriptions[$tenant->getKey()] ??= Subscription::withoutTenantScope()
            ->with(['plan', 'nextPlan'])
            ->where('tenant_id', $tenant->getKey())
            ->first();
    }

    public function forget(Tenant $tenant): void
    {
        unset($this->subscriptions[$tenant->getKey()], $this->pending[$tenant->getKey()]);
    }

    public function plan(Tenant $tenant): ?Plan
    {
        return $this->subscription($tenant)?->planAt();
    }

    /** The state from the dates alone; enforcement and the internal business are applied by access(). */
    public function state(Tenant $tenant, ?Carbon $now = null): SubscriptionState
    {
        $now ??= now();
        $subscription = $this->subscription($tenant);

        if (! $subscription || $subscription->ends_at === null) {
            return SubscriptionState::Unlimited;
        }

        if ($now->lt($subscription->ends_at)) {
            return $subscription->is_trial ? SubscriptionState::Trial : SubscriptionState::Active;
        }

        $state = match (true) {
            $now->lt($subscription->ends_at->copy()->addDays((int) config('billing.grace_days'))) => SubscriptionState::Due,
            $now->lt($subscription->ends_at->copy()->addDays((int) config('billing.lock_after_days'))) => SubscriptionState::ReadOnly,
            default => SubscriptionState::Locked,
        };

        // A payment waiting for approval keeps full access for a few days.
        return ! $state->hasFullAccess() && $this->hasRecentPendingPayment($tenant, $now) ? SubscriptionState::Due : $state;
    }

    public function access(Tenant $tenant): string
    {
        if ($tenant->is_internal || ! $this->settings->enforced()) {
            return self::FULL;
        }

        return match ($this->state($tenant)) {
            SubscriptionState::ReadOnly => self::READ_ONLY,
            SubscriptionState::Locked => self::LOCKED,
            default => self::FULL,
        };
    }

    /** May change data, send messages and run automations. */
    public function canOperate(Tenant $tenant): bool
    {
        return $this->access($tenant) === self::FULL;
    }

    public function websiteOnline(Tenant $tenant): bool
    {
        return $this->access($tenant) !== self::LOCKED;
    }

    /** A plan limit, or $default without a subscription. Null means unlimited. */
    public function limit(Tenant $tenant, string $key, mixed $default = null): mixed
    {
        $plan = $this->plan($tenant);

        return $plan ? $plan->limit($key) : $default;
    }

    private function hasRecentPendingPayment(Tenant $tenant, Carbon $now): bool
    {
        return $this->pending[$tenant->getKey()] ??= BillingPayment::withoutTenantScope()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', BillingPayment::PENDING)
            ->where('created_at', '>=', $now->copy()->subDays((int) config('billing.pending_access_days')))
            ->exists();
    }
}
