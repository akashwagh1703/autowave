<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Notifications\PaymentReviewed;
use App\Domain\Billing\Notifications\PaymentSubmitted;
use App\Domain\Billing\Support\BillingSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class ManualPaymentTest extends TestCase
{
    use CreatesTenants, ManagesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        config(['files.disks.private' => 'local']);
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Kolkata'));
        $this->configureBilling();
    }

    private function pay(array $overrides = []): TestResponse
    {
        return $this->post($this->appUrl('/settings/billing/payments'), [
            'plan' => 'growth',
            'period' => 'monthly',
            'method' => 'upi',
            'reference' => '4123 5678 9012',
            'paid_on' => '2026-10-05',
            ...$overrides,
        ]);
    }

    public function test_the_owner_sees_plans_usage_and_how_to_pay(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/settings/billing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/billing/Index')
                ->where('subscription.state', 'trial')
                ->where('subscription.days_left', 14)
                ->has('plans', 3)
                ->where('plans.0.code', 'starter')
                ->where('methods.manual.upi_id', 'autowave@okaxis')
                ->where('methods.manual.bank.ifsc', 'HDFC0001234')
                ->where('reference', 'AW-'.$tenant->id)
                ->where('gst', false)
                ->where('canManage', true));
    }

    public function test_the_quote_comes_from_the_server_with_a_upi_link_and_qr(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $response = $this->getJson($this->appUrl('/settings/billing/quote?plan=growth&period=yearly'))
            ->assertOk()
            ->assertJsonPath('total', 1499000)
            ->assertJsonPath('tax', [])
            ->assertJsonPath('kind', 'renewal');

        $this->assertStringStartsWith('upi://pay?pa=autowave%40okaxis', $response->json('upi_link'));
        $this->assertStringContainsString('am=14990.00', $response->json('upi_link'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('upi_qr'));

        $this->getJson($this->appUrl('/settings/billing/quote?plan=trial&period=monthly'))->assertUnprocessable();
    }

    public function test_an_owner_reports_a_payment_and_the_amount_is_set_by_the_server(): void
    {
        $tenant = $this->createTenant();
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($this->ownerOf($tenant));

        $this->pay(['total' => 100, 'amount' => 100, 'proof' => UploadedFile::fake()->image('paid.png')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $payment = BillingPayment::withoutTenantScope()->sole();
        $this->assertSame(BillingPayment::PENDING, $payment->status);
        $this->assertSame(149900, $payment->total);
        $this->assertSame('412356789012', $payment->reference);
        $this->assertSame('image/png', $payment->proof_mime);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->assertStringStartsWith("billing/proofs/{$tenant->id}/", $payment->proof_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.payment_submitted', 'tenant_id' => $tenant->id]);
        Notification::assertSentTo($admin, PaymentSubmitted::class);

        $this->get($this->appUrl('/settings/billing'))->assertInertia(fn (Assert $page) => $page->where('pending.total', 149900));
    }

    public function test_only_one_payment_can_wait_and_a_utr_cannot_be_reused(): void
    {
        $tenant = $this->createTenant();
        $other = $this->createTenant('Other Salon');
        $this->actingAs($this->ownerOf($tenant));
        $this->pay()->assertSessionHasNoErrors();

        $this->pay(['reference' => 'ANOTHER123'])->assertSessionHasErrors('plan');

        $this->actingAs($this->ownerOf($other));
        $this->pay(['reference' => '412356789012'])->assertSessionHasErrors('reference');
        $this->assertSame(1, BillingPayment::withoutTenantScope()->count());
    }

    public function test_payment_details_are_validated(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->pay(['reference' => '12'])->assertSessionHasErrors('reference');
        $this->pay(['paid_on' => '2026-10-09'])->assertSessionHasErrors('paid_on');
        $this->pay(['method' => 'cash'])->assertSessionHasErrors('method');
        $this->pay(['plan' => 'trial'])->assertSessionHasErrors('plan');
        $this->pay(['proof' => UploadedFile::fake()->create('paid.html', 10, 'text/html')])->assertSessionHasErrors('proof');
        $this->assertSame(0, BillingPayment::withoutTenantScope()->count());
    }

    public function test_only_owners_can_pay(): void
    {
        $tenant = $this->createTenant();
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->actingAs($staff);

        $this->get($this->appUrl('/settings/billing'))->assertForbidden();
        $this->pay()->assertForbidden();
        $this->getJson($this->appUrl('/settings/billing/quote?plan=growth&period=monthly'))->assertForbidden();
    }

    public function test_an_admin_approves_a_payment_which_extends_the_plan_and_issues_an_invoice(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $trialEnd = $this->subscriptionOf($tenant)->ends_at;
        $this->actingAs($owner);
        $this->pay(['proof' => UploadedFile::fake()->image('paid.png')]);
        $payment = BillingPayment::withoutTenantScope()->sole();

        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);
        $this->get($this->adminUrl('/billing/payments'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/billing/Payments')->where('payments.data.0.id', $payment->id)->where('payments.data.0.has_proof', true));
        $this->get($this->adminUrl("/billing/payments/{$payment->id}/proof"))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->post($this->adminUrl("/billing/payments/{$payment->id}/approve"))->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame(BillingPayment::APPROVED, $payment->status);
        $this->assertSame($admin->id, $payment->reviewed_by_user_id);
        $this->assertTrue($payment->covers_from->equalTo($trialEnd));
        $this->assertTrue($this->subscriptionOf($tenant)->ends_at->equalTo($trialEnd->copy()->addMonthNoOverflow()));

        $invoice = BillingInvoice::withoutTenantScope()->sole();
        $this->assertSame('AW/2026-27/0001', $invoice->number);
        $this->assertSame(BillingInvoice::INVOICE, $invoice->type);
        $this->assertSame(149900, $invoice->total);
        $this->assertSame('AW-'.$tenant->id, $invoice->buyer['reference']);
        Notification::assertSentTo($owner, PaymentReviewed::class);

        $this->post($this->adminUrl("/billing/payments/{$payment->id}/approve"))->assertSessionHasErrors('payment');
        $this->assertSame(1, BillingInvoice::withoutTenantScope()->count());

        $this->actingAs($owner);
        $this->get($this->appUrl("/settings/billing/invoices/{$invoice->id}"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('business/billing/Invoice')->where('invoice.number', 'AW/2026-27/0001')->where('invoice.title', 'Invoice'));
    }

    public function test_a_rejected_payment_frees_the_utr_and_tells_the_owner_why(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $this->actingAs($owner);
        $this->pay();
        $payment = BillingPayment::withoutTenantScope()->sole();

        $this->actingAs(User::factory()->platformAdmin()->create());
        $this->post($this->adminUrl("/billing/payments/{$payment->id}/reject"), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post($this->adminUrl("/billing/payments/{$payment->id}/reject"), ['reason' => 'No such UTR in our account.'])->assertSessionHas('success');

        $this->assertSame(BillingPayment::REJECTED, $payment->fresh()->status);
        $this->assertTrue($this->subscriptionOf($tenant)->is_trial);
        Notification::assertSentTo($owner, PaymentReviewed::class);

        $this->actingAs($owner);
        $this->pay()->assertSessionHasNoErrors();
    }

    public function test_the_owner_can_withdraw_a_waiting_payment_but_not_another_business(): void
    {
        $tenant = $this->createTenant();
        $other = $this->createTenant('Other Salon');
        $this->actingAs($this->ownerOf($tenant));
        $this->pay();
        $payment = BillingPayment::withoutTenantScope()->sole();

        $this->actingAs($this->ownerOf($other));
        $this->post($this->appUrl("/settings/billing/payments/{$payment->id}/cancel"))->assertNotFound();

        $this->actingAs($this->ownerOf($tenant));
        $this->post($this->appUrl("/settings/billing/payments/{$payment->id}/cancel"))->assertSessionHas('success');
        $this->assertSame(BillingPayment::CANCELLED, $payment->fresh()->status);
    }

    public function test_switching_manual_payment_off_stops_new_reports_but_keeps_reviews_working(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $this->pay();
        $payment = BillingPayment::withoutTenantScope()->sole();

        app(BillingSettings::class)->update(['manual_enabled' => false]);

        $this->get($this->appUrl('/settings/billing'))->assertInertia(fn (Assert $page) => $page->where('methods.manual', null));
        $this->post($this->appUrl("/settings/billing/payments/{$payment->id}/cancel"));
        $this->pay(['reference' => 'NEWUTR12345'])->assertSessionHasErrors('method');

        app(BillingSettings::class)->update(['manual_enabled' => true]);
        $this->pay(['reference' => 'NEWUTR12345'])->assertSessionHasNoErrors();

        app(BillingSettings::class)->update(['manual_enabled' => false]);
        $this->actingAs(User::factory()->platformAdmin()->create());
        $this->post($this->adminUrl('/billing/payments/'.BillingPayment::withoutTenantScope()->where('status', 'pending')->value('id').'/approve'))->assertSessionHas('success');
    }

    public function test_admins_can_record_a_payment_and_a_free_period(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs(User::factory()->platformAdmin()->create());

        $this->post($this->adminUrl("/tenants/{$tenant->id}/payments"), ['plan' => 'starter', 'period' => 'yearly', 'method' => 'cash', 'paid_on' => '2026-10-04', 'amount' => 4000])
            ->assertSessionHas('success');

        $payment = BillingPayment::withoutTenantScope()->sole();
        $this->assertSame(BillingPayment::APPROVED, $payment->status);
        $this->assertSame(400000, $payment->total);
        $this->assertSame('AW/2026-27/0001', BillingInvoice::withoutTenantScope()->sole()->number);

        $this->post($this->adminUrl("/tenants/{$tenant->id}/payments"), ['plan' => 'starter', 'period' => 'monthly', 'method' => 'complimentary', 'paid_on' => '2026-10-05'])
            ->assertSessionHasErrors('plan');
        $this->post($this->adminUrl("/tenants/{$tenant->id}/payments"), ['plan' => 'starter', 'period' => 'yearly', 'method' => 'complimentary', 'paid_on' => '2026-10-05'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, BillingPayment::withoutTenantScope()->latest('id')->first()->total);
        $this->assertSame(1, BillingInvoice::withoutTenantScope()->count(), 'A free period has no invoice.');
    }
}
