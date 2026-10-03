<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\ManagePayments;
use App\Domain\Billing\Actions\SubscriptionLifecycle;
use App\Domain\Billing\Enums\SubscriptionState;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Billing\Support\PriceCalculator;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use CreatesTenants, ManagesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Kolkata'));
    }

    private function state(Tenant $tenant): SubscriptionState
    {
        $this->app->forgetScopedInstances();

        return app(Entitlements::class)->state($tenant);
    }

    private function record(Tenant $tenant, string $plan, string $period = 'monthly', array $extra = []): BillingPayment
    {
        return app(ManagePayments::class)->record($tenant, User::factory()->platformAdmin()->create(), ['plan' => $plan, 'period' => $period, 'method' => 'bank_transfer', ...$extra]);
    }

    public function test_a_new_business_gets_a_fourteen_day_trial_with_trial_limits(): void
    {
        $tenant = $this->createTenant();
        $subscription = $this->subscriptionOf($tenant);

        $this->assertTrue($subscription->is_trial);
        $this->assertSame('trial', $subscription->plan->code);
        $this->assertTrue($subscription->ends_at->equalTo(now()->addDays(14)));
        $this->assertSame(SubscriptionState::Trial, $this->state($tenant));
        $this->assertSame(1024, app(Entitlements::class)->limit($tenant, 'storage_mb'));
    }

    public function test_the_internal_business_never_needs_to_pay(): void
    {
        $tenant = $this->createTenant('AutoWave', options: ['is_internal' => true]);
        $subscription = $this->subscriptionOf($tenant);

        $this->assertNull($subscription->ends_at);
        $this->assertSame('business', $subscription->plan->code);
        $this->assertSame(SubscriptionState::Unlimited, $this->state($tenant));
    }

    public function test_the_backfill_gives_existing_businesses_a_trial_once(): void
    {
        $tenant = $this->createTenant();
        Subscription::withoutTenantScope()->delete();

        $this->seed(TenantBackfillSeeder::class);
        $first = $this->subscriptionOf($tenant);
        $this->assertTrue($first->is_trial);

        $this->travel(3)->days();
        $this->seed(TenantBackfillSeeder::class);

        $this->assertTrue($this->subscriptionOf($tenant)->ends_at->equalTo($first->ends_at));
    }

    public function test_paying_during_the_trial_starts_the_plan_when_the_trial_ends(): void
    {
        $tenant = $this->createTenant();
        $trialEnd = $this->subscriptionOf($tenant)->ends_at;

        $payment = $this->record($tenant, 'growth');

        $this->assertSame(BillingPayment::KIND_RENEWAL, $payment->kind);
        $this->assertTrue($payment->covers_from->equalTo($trialEnd));
        $subscription = $this->subscriptionOf($tenant);
        $this->assertFalse($subscription->is_trial);
        $this->assertTrue($subscription->ends_at->equalTo($trialEnd->copy()->addMonthNoOverflow()));
        $this->assertSame('trial', $subscription->plan->code, 'Trial limits stay until the trial ends.');
        $this->assertSame('growth', $subscription->nextPlan->code);

        $this->travelTo($trialEnd->copy()->addMinute());
        $this->assertSame('growth', app(Entitlements::class)->plan($tenant)->code);

        $this->artisan('billing:sweep')->assertSuccessful();
        $subscription = $this->subscriptionOf($tenant);
        $this->assertSame('growth', $subscription->plan->code);
        $this->assertNull($subscription->next_plan_id);
    }

    public function test_renewing_early_extends_from_the_current_end_date(): void
    {
        $tenant = $this->createTenant();
        $this->record($tenant, 'starter');
        $end = $this->subscriptionOf($tenant)->ends_at;

        $this->travel(5)->days();
        $this->record($tenant, 'starter');

        $this->assertTrue($this->subscriptionOf($tenant)->ends_at->equalTo($end->copy()->addMonthNoOverflow()));
    }

    public function test_a_plan_waiting_to_start_can_be_renewed_but_not_switched(): void
    {
        $tenant = $this->createTenant();
        $this->record($tenant, 'growth');

        try {
            $this->record($tenant, 'starter');
            $this->fail('Switching a plan that has not started yet should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }

        $this->assertSame(1, BillingPayment::withoutTenantScope()->count());
        $this->assertSame('growth', $this->subscriptionOf($tenant)->nextPlan->code);
    }

    public function test_upgrading_starts_now_and_credits_the_unused_days(): void
    {
        $tenant = $this->createTenant();
        $this->endsAt($tenant, now()->subDay());
        $this->record($tenant, 'starter');
        $this->travel(15)->days();

        $quote = app(PriceCalculator::class)->quote($tenant, SubscriptionLifecycle::plan('growth'), 'monthly');

        $this->assertSame(BillingPayment::KIND_NOW, $quote->kind);
        $this->assertGreaterThan(20000, $quote->credit);
        $this->assertLessThan(30000, $quote->credit);
        $this->assertSame(149900 - $quote->credit, $quote->amount);

        $this->record($tenant, 'growth');
        $subscription = $this->subscriptionOf($tenant);
        $this->assertSame('growth', $subscription->plan->code);
        $this->assertTrue($subscription->ends_at->equalTo(now()->addMonthNoOverflow()));
    }

    public function test_a_cheaper_plan_starts_at_renewal(): void
    {
        $tenant = $this->createTenant();
        $this->endsAt($tenant, now()->subDay());
        $this->record($tenant, 'growth');
        $end = $this->subscriptionOf($tenant)->ends_at;

        $payment = $this->record($tenant, 'starter');

        $this->assertSame(BillingPayment::KIND_RENEWAL, $payment->kind);
        $subscription = $this->subscriptionOf($tenant);
        $this->assertSame('growth', $subscription->plan->code);
        $this->assertSame('starter', $subscription->nextPlan->code);
        $this->assertTrue($subscription->plan_changes_at->equalTo($end));
    }

    public function test_states_follow_the_dates_and_a_pending_payment_keeps_access(): void
    {
        $tenant = $this->createTenant();
        $end = $this->subscriptionOf($tenant)->ends_at;

        $this->travelTo($end->copy()->addDays(3));
        $this->assertSame(SubscriptionState::Due, $this->state($tenant));

        $this->travelTo($end->copy()->addDays(10));
        $this->assertSame(SubscriptionState::ReadOnly, $this->state($tenant));

        $this->travelTo($end->copy()->addDays(31));
        $this->assertSame(SubscriptionState::Locked, $this->state($tenant));

        BillingPayment::withoutTenantScope()->create([
            'tenant_id' => $tenant->id, 'plan_id' => $this->subscriptionOf($tenant)->plan_id, 'period' => 'monthly', 'kind' => 'now',
            'credit' => 0, 'amount' => 49900, 'tax_amount' => 0, 'total' => 49900, 'method' => 'upi', 'status' => 'pending', 'reference' => 'UTR123456',
        ]);
        $this->assertSame(SubscriptionState::Due, $this->state($tenant));

        $this->travel(4)->days();
        $this->assertSame(SubscriptionState::Locked, $this->state($tenant), 'The grace for a pending payment lasts three days.');
    }

    public function test_admins_can_adjust_a_subscription_directly(): void
    {
        $tenant = $this->createTenant();
        $growth = SubscriptionLifecycle::plan('growth');

        app(SubscriptionLifecycle::class)->adjust($tenant, $growth, now()->addMonths(2), 'yearly');

        $subscription = $this->subscriptionOf($tenant);
        $this->assertSame('growth', $subscription->plan->code);
        $this->assertSame('yearly', $subscription->period);
        $this->assertFalse($subscription->is_trial);
        $this->assertSame(SubscriptionState::Active, $this->state($tenant));
    }
}
