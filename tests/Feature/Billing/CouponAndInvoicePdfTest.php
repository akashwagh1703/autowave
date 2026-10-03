<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\BillingCoupon;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Notifications\PaymentReviewed;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class CouponAndInvoicePdfTest extends TestCase
{
    use CreatesTenants, ManagesBilling, RefreshDatabase;

    private int $utr = 412356789000;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Kolkata'));
        $this->configureBilling();
    }

    private function coupon(array $attributes = []): BillingCoupon
    {
        return BillingCoupon::query()->create([
            'code' => 'LAUNCH20',
            'type' => BillingCoupon::PERCENT,
            'value' => 20,
            'once_per_business' => true,
            'first_payment_only' => false,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function pay(array $overrides = []): TestResponse
    {
        return $this->post($this->appUrl('/settings/billing/payments'), [
            'plan' => 'growth',
            'period' => 'monthly',
            'method' => 'upi',
            'reference' => (string) $this->utr++,
            'paid_on' => '2026-10-05',
            ...$overrides,
        ]);
    }

    private function quote(string $coupon, string $plan = 'growth', string $period = 'monthly'): TestResponse
    {
        return $this->getJson($this->appUrl("/settings/billing/quote?plan={$plan}&period={$period}&coupon=".urlencode($coupon)));
    }

    private function approve(BillingPayment $payment): void
    {
        $owner = auth()->user();
        $this->actingAs(User::factory()->platformAdmin()->create());
        $this->post($this->adminUrl("/billing/payments/{$payment->id}/approve"))->assertSessionHas('success');
        $this->actingAs($owner);
    }

    /** @return array{Tenant, User} */
    private function owner(string $name = 'ABC Salon'): array
    {
        $tenant = $this->createTenant($name);
        $owner = $this->ownerOf($tenant);
        $this->actingAs($owner);

        return [$tenant, $owner];
    }

    public function test_a_percent_coupon_comes_off_before_gst_and_shows_on_the_invoice(): void
    {
        $this->enableGst();
        $this->coupon();
        $this->owner();

        $this->quote(' launch20 ')
            ->assertOk()
            ->assertJsonPath('coupon', 'LAUNCH20')
            ->assertJsonPath('discount', 29980)
            ->assertJsonPath('amount', 119920)
            ->assertJsonPath('total', 141506);

        $this->pay(['coupon' => 'launch20'])->assertSessionHasNoErrors();
        $payment = BillingPayment::withoutTenantScope()->sole();
        $this->assertSame(29980, $payment->discount);
        $this->assertSame('LAUNCH20', $payment->coupon_code);
        $this->assertSame(141506, $payment->total);

        $this->approve($payment);

        $invoice = BillingInvoice::withoutTenantScope()->sole();
        $this->assertSame(141506, $invoice->total);
        $this->assertSame(149900, $invoice->lines[0]['amount']);
        $this->assertSame('Discount (coupon LAUNCH20)', $invoice->lines[1]['description']);
        $this->assertSame(-29980, $invoice->lines[1]['amount']);
        $this->assertSame(119920, $invoice->subtotal);
    }

    public function test_a_fixed_coupon_is_capped_at_the_price(): void
    {
        $this->coupon(['code' => 'FLAT500', 'type' => BillingCoupon::FIXED, 'value' => 50000]);
        $this->coupon(['code' => 'HUGE', 'type' => BillingCoupon::FIXED, 'value' => 10000000]);
        $this->owner();

        $this->quote('FLAT500', 'starter')->assertJsonPath('discount', 49900)->assertJsonPath('total', 0);
        $this->quote('FLAT500')->assertJsonPath('discount', 50000)->assertJsonPath('total', 99900);
        $this->quote('HUGE')->assertJsonPath('discount', 149900)->assertJsonPath('total', 0);
    }

    public function test_coupon_rules_are_enforced(): void
    {
        $this->coupon(['code' => 'YEARLYGROWTH', 'plans' => ['growth'], 'periods' => ['yearly']]);
        $this->coupon(['code' => 'OLD', 'ends_at' => now()->subDay()]);
        $this->coupon(['code' => 'SOON', 'starts_at' => now()->addDay()]);
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $this->owner();

        $this->quote('YEARLYGROWTH', 'growth', 'yearly')->assertOk();
        $this->quote('YEARLYGROWTH', 'growth', 'monthly')->assertUnprocessable()->assertJsonValidationErrors('coupon');
        $this->quote('YEARLYGROWTH', 'starter', 'yearly')->assertUnprocessable()->assertJsonValidationErrors('coupon');
        $this->quote('OLD')->assertUnprocessable()->assertJsonValidationErrors('coupon');
        $this->quote('SOON')->assertUnprocessable()->assertJsonValidationErrors('coupon');
        $this->quote('OFF')->assertUnprocessable()->assertJsonValidationErrors('coupon');
        $this->quote('NOSUCHCODE')->assertUnprocessable()->assertJsonValidationErrors('coupon');

        $this->pay(['coupon' => 'OLD'])->assertSessionHasErrors('coupon');
        $this->assertSame(0, BillingPayment::withoutTenantScope()->count());
    }

    public function test_a_coupon_is_used_once_per_business_and_up_to_its_limit(): void
    {
        $this->coupon(['max_redemptions' => 2]);
        [$first] = $this->owner();
        $this->pay(['coupon' => 'LAUNCH20'])->assertSessionHasNoErrors();
        $this->approve(BillingPayment::withoutTenantScope()->sole());

        $this->quote('LAUNCH20')->assertUnprocessable()->assertJsonPath('errors.coupon.0', 'Your business has already used this coupon.');

        $this->owner('Second Salon');
        $this->pay(['coupon' => 'LAUNCH20'])->assertSessionHasNoErrors();

        $this->owner('Third Salon');
        $this->quote('LAUNCH20')->assertUnprocessable()->assertJsonPath('errors.coupon.0', 'This coupon has been fully used.');

        // A rejected payment gives its redemption back.
        $second = BillingPayment::withoutTenantScope()->where('tenant_id', '!=', $first->id)->sole();
        $this->actingAs(User::factory()->platformAdmin()->create());
        $this->post($this->adminUrl("/billing/payments/{$second->id}/reject"), ['reason' => 'No such UTR.'])->assertSessionHas('success');
        $this->owner('Fourth Salon');
        $this->quote('LAUNCH20')->assertOk();
    }

    public function test_a_first_payment_coupon_is_refused_after_a_paid_plan(): void
    {
        $this->coupon(['code' => 'WELCOME', 'first_payment_only' => true, 'once_per_business' => false]);
        $this->owner();
        $this->quote('WELCOME')->assertOk();

        $this->pay()->assertSessionHasNoErrors();
        $this->approve(BillingPayment::withoutTenantScope()->sole());

        $this->quote('WELCOME')->assertUnprocessable()->assertJsonPath('errors.coupon.0', 'This coupon is only for a business\'s first payment.');
    }

    public function test_a_full_discount_activates_the_plan_without_paying(): void
    {
        $this->coupon(['code' => 'FREEMONTH', 'value' => 100]);
        [$tenant, $owner] = $this->owner();
        $trialEnd = $this->subscriptionOf($tenant)->ends_at;

        $this->pay(['coupon' => 'FREEMONTH'])->assertSessionHasErrors('coupon');
        $this->post($this->appUrl('/settings/billing/activate'), ['plan' => 'growth', 'period' => 'monthly', 'coupon' => 'LAUNCH20'])->assertSessionHasErrors('coupon');

        $this->post($this->appUrl('/settings/billing/activate'), ['plan' => 'growth', 'period' => 'monthly', 'coupon' => 'freemonth'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $payment = BillingPayment::withoutTenantScope()->sole();
        $this->assertSame(BillingPayment::APPROVED, $payment->status);
        $this->assertSame('coupon', $payment->method);
        $this->assertSame(0, $payment->total);
        $this->assertSame(149900, $payment->discount);
        $this->assertTrue($this->subscriptionOf($tenant)->ends_at->equalTo($trialEnd->copy()->addMonthNoOverflow()));
        $this->assertSame(0, BillingInvoice::withoutTenantScope()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.coupon_activated', 'tenant_id' => $tenant->id]);
        Notification::assertSentTo($owner, PaymentReviewed::class);

        $mail = (new PaymentReviewed($payment->id))->toMail($owner);
        $this->assertSame('Your Growth plan is active', $mail->subject);

        $this->post($this->appUrl('/settings/billing/activate'), ['plan' => 'growth', 'period' => 'monthly', 'coupon' => 'FREEMONTH'])->assertSessionHasErrors('coupon');
    }

    public function test_staff_cannot_activate_a_coupon(): void
    {
        $this->coupon(['code' => 'FREEMONTH', 'value' => 100]);
        $tenant = $this->createTenant();
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->actingAs($staff);

        $this->post($this->appUrl('/settings/billing/activate'), ['plan' => 'growth', 'period' => 'monthly', 'coupon' => 'FREEMONTH'])->assertForbidden();
        $this->assertSame(0, BillingPayment::withoutTenantScope()->count());
    }

    public function test_super_admin_manages_coupons(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);

        $this->post($this->adminUrl('/billing/coupons'), [
            'code' => ' diwali-25 ',
            'description' => 'Diwali offer',
            'type' => 'fixed',
            'value' => 500,
            'plans' => ['growth', 'business'],
            'periods' => ['yearly'],
            'max_redemptions' => 100,
            'once_per_business' => true,
            'first_payment_only' => false,
            'starts_on' => '2026-10-20',
            'ends_on' => '2026-11-05',
            'is_active' => true,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $coupon = BillingCoupon::query()->sole();
        $this->assertSame('DIWALI-25', $coupon->code);
        $this->assertSame(50000, $coupon->value);
        $this->assertSame(['growth', 'business'], $coupon->plans);
        $this->assertTrue($coupon->starts_at->equalTo(Carbon::parse('2026-10-20 00:00', 'Asia/Kolkata')));
        $this->assertTrue($coupon->ends_at->equalTo(Carbon::parse('2026-11-05 23:59:59', 'Asia/Kolkata')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.coupon_created']);

        $this->get($this->adminUrl('/billing/coupons'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing/Coupons')
                ->where('coupons.0.code', 'DIWALI-25')
                ->where('coupons.0.starts_on', '2026-10-20')
                ->where('coupons.0.ends_on', '2026-11-05')
                ->where('coupons.0.redemptions', 0)
                ->where('coupons.0.live', false)
                ->has('plans', 3)
                ->has('periods', 2));

        $invalid = fn (array $overrides) => $this->post($this->adminUrl('/billing/coupons'), [
            'code' => 'NEWCODE', 'type' => 'percent', 'value' => 10, 'once_per_business' => true, 'first_payment_only' => false, 'is_active' => true, ...$overrides,
        ]);
        $invalid(['code' => 'diwali-25'])->assertSessionHasErrors('code');
        $invalid(['code' => 'A!'])->assertSessionHasErrors('code');
        $invalid(['value' => 101])->assertSessionHasErrors('value');
        $invalid(['value' => 12.5])->assertSessionHasErrors('value');
        $invalid(['plans' => ['trial']])->assertSessionHasErrors('plans.0');
        $invalid(['starts_on' => '2026-11-01', 'ends_on' => '2026-10-01'])->assertSessionHasErrors('ends_on');
        $this->assertSame(1, BillingCoupon::query()->count());

        $this->put($this->adminUrl("/billing/coupons/{$coupon->id}"), [
            'code' => 'DIWALI-25', 'type' => 'percent', 'value' => 15, 'once_per_business' => true, 'first_payment_only' => true, 'is_active' => false,
        ])->assertSessionHasNoErrors();
        $coupon->refresh();
        $this->assertSame(15, $coupon->value);
        $this->assertNull($coupon->plans);
        $this->assertFalse($coupon->is_active);

        $this->delete($this->adminUrl("/billing/coupons/{$coupon->id}"))->assertSessionHas('success');
        $this->assertSame(0, BillingCoupon::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.coupon_deleted']);
    }

    public function test_a_used_coupon_can_be_switched_off_but_not_deleted(): void
    {
        $coupon = $this->coupon();
        $this->owner();
        $this->pay(['coupon' => 'LAUNCH20'])->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->platformAdmin()->create());
        $this->get($this->adminUrl('/billing/coupons'))->assertInertia(fn (Assert $page) => $page->where('coupons.0.redemptions', 1));
        $this->delete($this->adminUrl("/billing/coupons/{$coupon->id}"))->assertSessionHas('error');
        $this->assertTrue(BillingCoupon::query()->whereKey($coupon->id)->exists());
    }

    public function test_only_super_admin_manages_coupons(): void
    {
        $coupon = $this->coupon();
        $this->owner();

        $this->get($this->adminUrl('/billing/coupons'))->assertForbidden();
        $this->post($this->adminUrl('/billing/coupons'), ['code' => 'HACK', 'type' => 'percent', 'value' => 100])->assertForbidden();
        $this->delete($this->adminUrl("/billing/coupons/{$coupon->id}"))->assertForbidden();
        $this->assertSame(1, BillingCoupon::query()->count());
    }

    public function test_invoices_download_as_pdf_for_the_owner_and_super_admin_only(): void
    {
        [$tenant, $owner] = $this->owner();
        $this->pay()->assertSessionHasNoErrors();
        $payment = BillingPayment::withoutTenantScope()->sole();
        $this->approve($payment);
        $invoice = BillingInvoice::withoutTenantScope()->sole();

        $response = $this->get($this->appUrl("/settings/billing/invoices/{$invoice->id}/pdf"))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('AW-2026-27-0001.pdf', (string) $response->headers->get('Content-Disposition'));

        $this->actingAs($this->ownerOf($this->createTenant('Other Salon')));
        $this->get($this->appUrl("/settings/billing/invoices/{$invoice->id}/pdf"))->assertNotFound();

        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->actingAs($staff);
        $this->get($this->appUrl("/settings/billing/invoices/{$invoice->id}/pdf"))->assertForbidden();

        $this->actingAs(User::factory()->platformAdmin()->create());
        $this->get($this->adminUrl("/billing/invoices/{$invoice->id}/pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($owner);
        $this->get($this->adminUrl("/billing/invoices/{$invoice->id}/pdf"))->assertForbidden();
    }

    public function test_the_approval_email_attaches_the_invoice_pdf(): void
    {
        [, $owner] = $this->owner();
        $this->pay()->assertSessionHasNoErrors();
        $payment = BillingPayment::withoutTenantScope()->sole();
        $this->approve($payment);

        $mail = (new PaymentReviewed($payment->id))->toMail($owner);

        $this->assertCount(1, $mail->rawAttachments);
        $this->assertSame('AW-2026-27-0001.pdf', $mail->rawAttachments[0]['name']);
        $this->assertSame('application/pdf', $mail->rawAttachments[0]['options']['mime']);
        $this->assertStringStartsWith('%PDF', $mail->rawAttachments[0]['data']);
    }
}
