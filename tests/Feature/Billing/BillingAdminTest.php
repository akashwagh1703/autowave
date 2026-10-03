<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Notifications\PaymentDetailsChanged;
use App\Domain\Billing\Support\BillingSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class BillingAdminTest extends TestCase
{
    use CreatesTenants, ManagesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        config(['files.disks.private' => 'local']);
    }

    private function settings(array $overrides = []): array
    {
        return array_replace_recursive([
            'enforce' => false,
            'manual_enabled' => true,
            'online_enabled' => false,
            'seller' => ['name' => 'AutoWave', 'address' => 'Pune', 'email' => 'billing@autowave.test', 'phone' => ''],
            'gst' => ['enabled' => false, 'gstin' => ''],
            'upi' => ['id' => 'autowave@okaxis', 'payee' => 'AutoWave'],
            'bank' => ['account_name' => '', 'account_number' => '', 'ifsc' => '', 'bank_name' => ''],
            'instructions' => '',
        ], $overrides);
    }

    public function test_admins_save_payment_details_and_every_admin_is_alerted_when_they_change(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $other = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);

        $this->put($this->adminUrl('/settings/billing'), $this->settings())->assertSessionHasNoErrors()->assertSessionHas('success');

        $billing = app(BillingSettings::class);
        $this->assertSame('autowave@okaxis', $billing->get('upi.id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.settings_updated', 'user_id' => $admin->id]);
        Notification::assertSentTo([$admin, $other], PaymentDetailsChanged::class);

        Notification::fake();
        $this->put($this->adminUrl('/settings/billing'), $this->settings(['seller' => ['address' => 'Mumbai']]))->assertSessionHasNoErrors();
        Notification::assertNothingSent();

        $this->get($this->adminUrl('/settings'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('billing.upi.id', 'autowave@okaxis')
                ->where('billing.gateway.configured', false)
                ->missing('billing.gateways'));
    }

    public function test_payment_methods_cannot_all_be_off_and_online_needs_the_gateway(): void
    {
        $this->actingAs(User::factory()->platformAdmin()->create());

        $this->put($this->adminUrl('/settings/billing'), $this->settings(['manual_enabled' => false]))->assertSessionHasErrors('manual_enabled');
        $this->put($this->adminUrl('/settings/billing'), $this->settings(['online_enabled' => true]))->assertSessionHasErrors('online_enabled');
        $this->put($this->adminUrl('/settings/billing'), $this->settings(['upi' => ['id' => '']]))->assertSessionHasErrors('upi.id');
        $this->put($this->adminUrl('/settings/billing'), $this->settings(['upi' => ['id' => 'not a upi']]))->assertSessionHasErrors('upi.id');
        $this->put($this->adminUrl('/settings/billing'), $this->settings(['gst' => ['enabled' => true]]))->assertSessionHasErrors('gst.gstin');

        config(['billing.gateway' => 'razorpay', 'billing.gateways.razorpay' => ['key_id' => 'rzp_test_x', 'key_secret' => 'secret', 'webhook_secret' => 'hook']]);
        $this->put($this->adminUrl('/settings/billing'), $this->settings(['manual_enabled' => false, 'online_enabled' => true]))->assertSessionHasNoErrors();
        $this->assertTrue(app(BillingSettings::class)->onlineEnabled());
        $this->assertFalse(app(BillingSettings::class)->manualEnabled());

        $this->put($this->adminUrl('/settings/billing'), $this->settings())->assertSessionHasNoErrors();
        $this->assertTrue(app(BillingSettings::class)->manualEnabled());
    }

    public function test_the_upi_qr_image_is_uploaded_privately_replaced_and_removed(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);

        $this->post($this->adminUrl('/settings/billing/qr'), ['qr' => UploadedFile::fake()->create('qr.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('qr');
        $this->post($this->adminUrl('/settings/billing/qr'), ['qr' => UploadedFile::fake()->image('tiny.png', 50, 50)])->assertSessionHasErrors('qr');

        $this->post($this->adminUrl('/settings/billing/qr'), ['qr' => UploadedFile::fake()->image('qr.png', 400, 400)])->assertSessionHasNoErrors();
        $first = app(BillingSettings::class)->qr();
        Storage::disk('local')->assertExists($first['path']);
        $this->assertStringStartsWith('platform/billing/upi-qr-', $first['path']);
        $this->assertSame($admin->email, $first['updated_by']);
        Notification::assertSentTo($admin, PaymentDetailsChanged::class);
        $this->get($this->adminUrl('/settings/billing/qr'))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->post($this->adminUrl('/settings/billing/qr'), ['qr' => UploadedFile::fake()->image('qr2.png', 400, 400)])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($first['path']);

        $tenant = $this->createTenant();
        $this->configureBilling();
        $this->actingAs($this->ownerOf($tenant));
        $this->get($this->appUrl('/settings/billing/qr'))->assertOk();
        $this->get($this->appUrl('/settings/billing'))->assertInertia(fn (Assert $page) => $page->where('methods.manual.has_qr', true));

        $this->actingAs($admin);
        $this->delete($this->adminUrl('/settings/billing/qr'))->assertSessionHas('success');
        $this->assertNull(app(BillingSettings::class)->qr());
        Storage::disk('local')->assertDirectoryEmpty('platform/billing');
    }

    public function test_billing_admin_pages_are_for_platform_admins_only(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->adminUrl('/billing/payments'))->assertForbidden();
        $this->get($this->adminUrl('/billing/plans'))->assertForbidden();
        $this->put($this->adminUrl('/settings/billing'), $this->settings())->assertForbidden();
        $this->post($this->adminUrl("/tenants/{$tenant->id}/payments"), ['plan' => 'growth', 'period' => 'monthly', 'method' => 'cash', 'paid_on' => now()->toDateString()])->assertForbidden();
    }

    public function test_admins_edit_plans_and_change_a_business_plan(): void
    {
        $tenant = $this->createTenant();
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);
        $growth = Plan::query()->where('code', 'growth')->firstOrFail();

        $this->get($this->adminUrl('/billing/plans'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/billing/Plans')->has('plans', 4));

        $this->put($this->adminUrl("/billing/plans/{$growth->id}"), [
            'name' => 'Growth', 'description' => 'For growing teams', 'price_monthly' => 1799, 'price_yearly' => 17990, 'is_public' => true, 'is_active' => true,
            'limits' => ['members' => 8, 'storage_mb' => 8192, 'ai_tokens' => 600000, 'automations' => null, 'instagram' => true],
        ])->assertSessionHasNoErrors();

        $growth->refresh();
        $this->assertSame(179900, $growth->price_monthly);
        $this->assertSame(8, $growth->limit('members'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.plan_updated']);

        $this->put($this->adminUrl("/tenants/{$tenant->id}/subscription"), ['plan' => 'growth', 'period' => 'yearly', 'ends_at' => now()->addYear()->toDateString(), 'reason' => 'Annual deal signed offline'])
            ->assertSessionHasNoErrors();

        $subscription = $this->subscriptionOf($tenant);
        $this->assertSame('growth', $subscription->plan->code);
        $this->assertFalse($subscription->is_trial);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.subscription_adjusted', 'tenant_id' => $tenant->id]);

        $this->get($this->adminUrl('/tenants'))->assertInertia(fn (Assert $page) => $page
            ->where('tenants.data.0.subscription.plan.code', 'growth')
            ->where('tenants.data.0.storage.default_mb', 8192));
    }
}
