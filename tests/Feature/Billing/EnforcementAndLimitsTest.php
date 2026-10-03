<?php

namespace Tests\Feature\Billing;

use App\Domain\AI\Support\AIUsageMeter;
use App\Domain\Automation\Models\Automation;
use App\Domain\Billing\Actions\SubscriptionLifecycle;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Files\Support\StorageAllowance;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class EnforcementAndLimitsTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, ManagesBilling, RefreshDatabase;

    private const SITE = 'abc-salon.autowave.test';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Kolkata'));
    }

    private function enforce(bool $on = true): void
    {
        app(BillingSettings::class)->update(['enforce' => $on]);
        $this->app->forgetScopedInstances();
    }

    private function onPlan(Tenant $tenant, string $plan): void
    {
        app(SubscriptionLifecycle::class)->adjust($tenant, SubscriptionLifecycle::plan($plan), now()->addMonth(), 'monthly');
        $this->app->forgetScopedInstances();
    }

    public function test_nobody_is_blocked_while_enforcement_is_off(): void
    {
        $tenant = $this->createTenant();
        $this->endsAt($tenant, now()->subDays(40));
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/dashboard'))->assertOk();
        $this->post($this->appUrl('/leads'), [])->assertSessionHasErrors()->assertSessionMissing('error');
        $this->get($this->siteUrl(self::SITE))->assertOk();
        $this->assertTrue(app(Entitlements::class)->canOperate($tenant));
    }

    public function test_after_the_grace_days_the_business_is_read_only_but_can_still_pay(): void
    {
        $this->enforce();
        $tenant = $this->createTenant();
        $this->endsAt($tenant, now()->subDays(3));
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/leads'), [])->assertSessionHasErrors();

        $this->endsAt($tenant, now()->subDays(10));

        $this->get($this->appUrl('/leads'))->assertOk();
        $this->post($this->appUrl('/leads'), [])->assertRedirect()->assertSessionHas('error')->assertSessionDoesntHaveErrors();
        $this->postJson($this->appUrl('/leads'), [])->assertForbidden();
        $this->get($this->appUrl('/settings/billing'))->assertOk();
        $this->getJson($this->appUrl('/settings/billing/quote?plan=starter&period=monthly'))->assertOk();
        $this->get($this->siteUrl(self::SITE))->assertOk();
        $this->assertFalse(app(Entitlements::class)->canOperate($tenant));
    }

    public function test_a_locked_business_only_reaches_billing_and_its_website_is_offline(): void
    {
        $this->enforce();
        $tenant = $this->createTenant();
        $this->endsAt($tenant, now()->subDays(31));
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/dashboard'))->assertRedirect($this->appUrl('/settings/billing'));
        $this->get($this->appUrl('/settings/billing'))->assertOk();
        $this->get($this->siteUrl(self::SITE))->assertStatus(503);

        $this->onPlan($tenant, 'starter');
        $this->get($this->appUrl('/dashboard'))->assertOk();
        $this->get($this->siteUrl(self::SITE))->assertOk();
    }

    public function test_the_internal_business_is_never_blocked(): void
    {
        $this->enforce();
        $tenant = $this->createTenant('AutoWave', options: ['is_internal' => true]);
        $this->endsAt($tenant, now()->subDays(60));

        $this->assertSame(Entitlements::FULL, app(Entitlements::class)->access($tenant));
    }

    public function test_storage_and_ai_allowances_follow_the_plan_unless_overridden(): void
    {
        $tenant = $this->createTenant();
        $storage = app(StorageAllowance::class);
        $ai = app(AIUsageMeter::class);

        $this->assertSame(1024, $storage->capMb($tenant));
        $this->assertSame(200000, $ai->cap($tenant));

        $this->onPlan($tenant, 'growth');
        $this->assertSame(5120, app(StorageAllowance::class)->capMb($tenant));
        $this->assertSame(500000, app(AIUsageMeter::class)->cap($tenant));

        app(StorageAllowance::class)->setCap($tenant, 300);
        app(AIUsageMeter::class)->setCap($tenant, 1000);
        $this->assertSame(300, app(StorageAllowance::class)->capMb($tenant));
        $this->assertSame(1000, app(AIUsageMeter::class)->cap($tenant));
    }

    public function test_the_plan_limits_active_automations(): void
    {
        $tenant = $this->createTenant();
        $this->onPlan($tenant, 'starter');
        Plan::query()->where('code', 'starter')->update(['limits' => json_encode([...config('billing.plans.starter.limits'), 'automations' => 1])]);
        $this->app->forgetScopedInstances();

        $automations = $this->inTenant($tenant, fn () => Automation::query()->orderBy('id')->get());
        $this->assertGreaterThanOrEqual(2, $automations->count());
        $this->inTenant($tenant, fn () => Automation::query()->update(['is_active' => false]));
        $this->actingAs($this->ownerOf($tenant));

        $this->patch($this->appUrl("/automations/{$automations[0]->id}/toggle"), ['is_active' => true])->assertSessionHas('success');
        $this->patch($this->appUrl("/automations/{$automations[1]->id}/toggle"), ['is_active' => true])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'allows 1 active automations'));

        $this->assertFalse(Automation::withoutTenantScope()->findOrFail($automations[1]->id)->is_active);
    }

    public function test_instagram_needs_a_plan_that_includes_it(): void
    {
        Http::fake(['graph.instagram.com/*' => Http::response(['user_id' => '17840000000000009', 'username' => 'abcsalon', 'name' => 'ABC Salon'])]);
        $tenant = $this->createTenant();
        $this->onPlan($tenant, 'starter');
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/settings/messaging/instagram'), ['access_token' => 'IGQ-token', 'app_secret' => 'ig-secret'])
            ->assertSessionHasErrors(['access_token' => 'Instagram is not included in your plan. Upgrade your plan in Billing to connect it.']);
        Http::assertNothingSent();

        $this->onPlan($tenant, 'growth');
        $this->post($this->appUrl('/settings/messaging/instagram'), ['access_token' => 'IGQ-token', 'app_secret' => 'ig-secret'])->assertSessionHasNoErrors();
    }
}
