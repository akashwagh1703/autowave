<?php

namespace Tests\Feature\Billing;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\OnlineCheckout;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Notifications\PaymentReviewed;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class OnlineCheckoutTest extends TestCase
{
    use CreatesTenants, ManagesBilling, RefreshDatabase;

    private const KEY_ID = 'key_public_dummy';

    private const KEY_SECRET = 'dummy-key-secret';

    private const WEBHOOK_SECRET = 'dummy-webhook-secret';

    private const API = 'https://api.razorpay.com/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Kolkata'));
        config([
            'billing.gateway' => 'razorpay',
            'billing.gateways.razorpay.key_id' => self::KEY_ID,
            'billing.gateways.razorpay.key_secret' => self::KEY_SECRET,
            'billing.gateways.razorpay.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        $this->configureBilling(['online_enabled' => true]);
    }

    /** Razorpay answers: the order, then the payment as `$status` for `$amount`. */
    private function fakeRazorpay(string $status = 'captured', int $amount = 149900, string $orderId = 'order_DUMMY1'): void
    {
        $orders = 0;

        Http::fake([
            self::API.'/orders' => function () use (&$orders) {
                $orders++;

                return Http::response(['id' => 'order_DUMMY'.$orders, 'currency' => 'INR', 'status' => 'created']);
            },
            self::API.'/payments/pay_DUMMY1/capture' => Http::response(['id' => 'pay_DUMMY1', 'order_id' => $orderId, 'status' => 'captured', 'amount' => $amount, 'currency' => 'INR', 'method' => 'upi']),
            self::API.'/payments/*' => Http::response(['id' => 'pay_DUMMY1', 'order_id' => $orderId, 'status' => $status, 'amount' => $amount, 'currency' => 'INR', 'method' => 'upi']),
        ]);
    }

    private function startCheckout(array $overrides = []): TestResponse
    {
        return $this->postJson($this->appUrl('/settings/billing/checkout'), ['plan' => 'growth', 'period' => 'monthly', ...$overrides]);
    }

    private function confirm(BillingPayment $payment, ?string $signature = null, string $orderId = 'order_DUMMY1'): TestResponse
    {
        return $this->postJson($this->appUrl("/settings/billing/checkout/{$payment->id}/confirm"), [
            'order_id' => $orderId,
            'payment_id' => 'pay_DUMMY1',
            'signature' => $signature ?? hash_hmac('sha256', $orderId.'|pay_DUMMY1', self::KEY_SECRET),
        ]);
    }

    private function postWebhook(array $payload, ?string $secret = self::WEBHOOK_SECRET): TestResponse
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($secret !== null) {
            $headers['HTTP_X_RAZORPAY_SIGNATURE'] = hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', $this->appUrl('/webhooks/billing/razorpay'), [], [], [], $headers, $body);
    }

    private function paidEvent(string $event = 'payment.captured', string $orderId = 'order_DUMMY1'): array
    {
        return ['event' => $event, 'payload' => ['payment' => ['entity' => ['id' => 'pay_DUMMY1', 'order_id' => $orderId, 'amount' => 149900, 'currency' => 'INR', 'status' => 'captured']]]];
    }

    /** @return array{Tenant, User, BillingPayment} */
    private function startedCheckout(): array
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $this->actingAs($owner);
        $this->startCheckout()->assertOk();

        return [$tenant, $owner, BillingPayment::withoutTenantScope()->sole()];
    }

    public function test_starting_a_checkout_creates_an_order_for_the_server_amount_without_leaking_secrets(): void
    {
        $this->fakeRazorpay();
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $response = $this->startCheckout(['amount' => 100, 'total' => 100])
            ->assertOk()
            ->assertJsonPath('checkout.gateway', 'razorpay')
            ->assertJsonPath('checkout.key', self::KEY_ID)
            ->assertJsonPath('checkout.order_id', 'order_DUMMY1')
            ->assertJsonPath('checkout.amount', 149900)
            ->assertJsonPath('checkout.currency', 'INR');

        $this->assertStringNotContainsString(self::KEY_SECRET, $response->getContent());
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $response->getContent());

        $payment = BillingPayment::withoutTenantScope()->sole();
        $this->assertSame(BillingPayment::INITIATED, $payment->status);
        $this->assertSame('online', $payment->method);
        $this->assertSame('razorpay', $payment->gateway);
        $this->assertSame('order_DUMMY1', $payment->gateway_order_id);
        $this->assertSame(149900, $payment->total);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.checkout_started', 'tenant_id' => $tenant->id]);

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/orders'
            && $request['amount'] === 149900
            && $request['currency'] === 'INR'
            && $request['receipt'] === 'AW-PAY-'.$payment->id
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::KEY_ID.':'.self::KEY_SECRET)));

        // An unfinished checkout doesn't block paying another way, and isn't listed to the owner.
        $this->get($this->appUrl('/settings/billing'))->assertInertia(fn (Assert $page) => $page->where('pending', null)->has('payments', 0));
    }

    public function test_the_checkout_callback_with_a_valid_signature_activates_the_plan_and_issues_the_invoice(): void
    {
        $this->fakeRazorpay();
        [$tenant, $owner, $payment] = $this->startedCheckout();
        $trialEnd = $this->subscriptionOf($tenant)->ends_at;

        $this->confirm($payment)->assertOk()->assertJsonPath('message', fn (string $message) => str_contains($message, 'Payment received'));

        $payment->refresh();
        $this->assertSame(BillingPayment::APPROVED, $payment->status);
        $this->assertSame('pay_DUMMY1', $payment->gateway_payment_id);
        $this->assertSame('2026-10-05', $payment->paid_on->toDateString());
        $this->assertTrue($this->subscriptionOf($tenant)->ends_at->equalTo($trialEnd->copy()->addMonthNoOverflow()));

        $invoice = BillingInvoice::withoutTenantScope()->sole();
        $this->assertSame(149900, $invoice->total);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.online_payment_received', 'tenant_id' => $tenant->id]);
        Notification::assertSentTo($owner, PaymentReviewed::class);

        // The webhook arriving afterwards changes nothing.
        $this->postWebhook($this->paidEvent())->assertOk();
        $this->assertSame(1, BillingInvoice::withoutTenantScope()->count());
        $this->assertTrue($this->subscriptionOf($tenant)->ends_at->equalTo($trialEnd->copy()->addMonthNoOverflow()));
        Notification::assertSentToTimes($owner, PaymentReviewed::class, 1);
    }

    public function test_a_forged_checkout_callback_is_refused(): void
    {
        $this->fakeRazorpay();
        [$tenant, , $payment] = $this->startedCheckout();

        $this->confirm($payment, 'forged-signature')->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->confirm($payment, hash_hmac('sha256', 'order_OTHER|pay_DUMMY1', self::KEY_SECRET), 'order_OTHER')->assertUnprocessable();

        $this->assertSame(BillingPayment::INITIATED, $payment->refresh()->status);
        $this->assertSame(0, BillingInvoice::withoutTenantScope()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.checkout_unverified', 'tenant_id' => $tenant->id]);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payments/'));
    }

    public function test_another_business_cannot_confirm_someone_elses_checkout(): void
    {
        $this->fakeRazorpay();
        [, , $payment] = $this->startedCheckout();

        $this->actingAs($this->ownerOf($this->createTenant('Other Salon')));
        $this->confirm($payment)->assertNotFound();
        $this->assertSame(BillingPayment::INITIATED, $payment->refresh()->status);
    }

    public function test_a_signed_webhook_completes_the_payment_once(): void
    {
        $this->fakeRazorpay();
        [$tenant, $owner] = $this->startedCheckout();

        $this->postWebhook($this->paidEvent())->assertOk();
        $this->postWebhook($this->paidEvent('order.paid'))->assertOk();

        $payment = BillingPayment::withoutTenantScope()->sole();
        $this->assertSame(BillingPayment::APPROVED, $payment->status);
        $this->assertSame(1, BillingInvoice::withoutTenantScope()->count());
        Notification::assertSentToTimes($owner, PaymentReviewed::class, 1);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'billing.online_payment_received')->where('tenant_id', $tenant->id)->count());
    }

    public function test_webhooks_need_a_valid_signature_and_the_active_gateway(): void
    {
        $this->fakeRazorpay();
        $this->startedCheckout();

        $this->postWebhook($this->paidEvent(), null)->assertForbidden();
        $this->postWebhook($this->paidEvent(), 'wrong-secret')->assertForbidden();
        $this->call('POST', $this->appUrl('/webhooks/billing/razorpay'), [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', 'not json', self::WEBHOOK_SECRET)], 'not json')->assertStatus(400);
        $this->postWebhook(['event' => 'refund.created', 'payload' => []])->assertOk();
        $this->assertSame(BillingPayment::INITIATED, BillingPayment::withoutTenantScope()->sole()->status);

        $this->call('POST', $this->appUrl('/webhooks/billing/stripe'), [], [], [], [], '{}')->assertNotFound();

        config(['billing.gateway' => 'none']);
        $this->postWebhook($this->paidEvent())->assertNotFound();
        $this->assertSame(BillingPayment::INITIATED, BillingPayment::withoutTenantScope()->sole()->status);
    }

    public function test_a_payment_for_the_wrong_amount_is_not_applied(): void
    {
        $this->fakeRazorpay(amount: 100);
        $this->startedCheckout();

        $this->postWebhook($this->paidEvent())->assertOk();

        $this->assertSame(BillingPayment::INITIATED, BillingPayment::withoutTenantScope()->sole()->status);
        $this->assertSame(0, BillingInvoice::withoutTenantScope()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.online_payment_mismatch']);
    }

    public function test_an_authorized_payment_is_captured_before_it_is_applied(): void
    {
        $this->fakeRazorpay('authorized');
        [, , $payment] = $this->startedCheckout();

        $this->confirm($payment)->assertOk();

        $this->assertSame(BillingPayment::APPROVED, $payment->refresh()->status);
        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/payments/pay_DUMMY1/capture' && $request['amount'] === 149900);
    }

    public function test_a_failed_attempt_is_noted_and_a_later_payment_still_counts_after_expiry(): void
    {
        $this->fakeRazorpay();
        [$tenant] = $this->startedCheckout();
        $trialEnd = $this->subscriptionOf($tenant)->ends_at;

        $failed = $this->paidEvent('payment.failed');
        $failed['payload']['payment']['entity']['error_description'] = 'Card declined by bank';
        $this->postWebhook($failed)->assertOk();
        $payment = BillingPayment::withoutTenantScope()->sole();
        $this->assertSame(BillingPayment::INITIATED, $payment->status);
        $this->assertStringContainsString('Card declined by bank', $payment->note);

        $this->travel(25)->hours();
        $this->assertSame(1, app(OnlineCheckout::class)->expireStale());
        $this->assertSame(BillingPayment::EXPIRED, $payment->refresh()->status);

        // The money arrived late: it still counts.
        $this->postWebhook($this->paidEvent())->assertOk();
        $this->assertSame(BillingPayment::APPROVED, $payment->refresh()->status);
        $this->assertTrue($this->subscriptionOf($tenant)->ends_at->equalTo($trialEnd->copy()->addMonthNoOverflow()));
    }

    public function test_the_billing_sweep_expires_stale_checkouts(): void
    {
        $this->fakeRazorpay();
        $this->startedCheckout();
        $this->travel(25)->hours();

        $this->artisan('billing:sweep')->assertSuccessful();

        $this->assertSame(BillingPayment::EXPIRED, BillingPayment::withoutTenantScope()->sole()->status);
    }

    public function test_starting_another_payment_expires_the_open_checkout(): void
    {
        $this->fakeRazorpay();
        [$tenant, , $first] = $this->startedCheckout();

        $this->startCheckout(['plan' => 'starter'])->assertOk();

        $this->assertSame(BillingPayment::EXPIRED, $first->refresh()->status);
        $this->assertSame(1, BillingPayment::withoutTenantScope()->where('tenant_id', $tenant->id)->where('status', BillingPayment::INITIATED)->count());
    }

    public function test_online_payment_is_refused_when_switched_off_or_not_configured(): void
    {
        $this->fakeRazorpay();
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        app(BillingSettings::class)->update(['online_enabled' => false]);
        $this->startCheckout()->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->getJson($this->appUrl('/settings/billing/quote?plan=growth&period=monthly'))->assertJsonPath('online_available', false);

        app(BillingSettings::class)->update(['online_enabled' => true]);
        config(['billing.gateways.razorpay.webhook_secret' => null]);
        $this->startCheckout()->assertUnprocessable()->assertJsonValidationErrors('method');

        config(['billing.gateways.razorpay.webhook_secret' => self::WEBHOOK_SECRET, 'billing.gateway' => 'none']);
        $this->startCheckout()->assertUnprocessable()->assertJsonValidationErrors('method');

        $this->assertSame(0, BillingPayment::withoutTenantScope()->count());
        Http::assertNothingSent();
    }

    public function test_a_gateway_outage_leaves_no_open_checkout(): void
    {
        Http::fake([self::API.'/orders' => Http::response(['error' => ['description' => 'Server error']], 500)]);
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->startCheckout()->assertUnprocessable()->assertJsonValidationErrors('method');

        $this->assertSame(BillingPayment::EXPIRED, BillingPayment::withoutTenantScope()->sole()->status);
    }

    public function test_only_owners_can_pay_online(): void
    {
        $this->fakeRazorpay();
        $tenant = $this->createTenant();
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->actingAs($staff);

        $this->startCheckout()->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_admin_settings_show_the_webhook_url(): void
    {
        $this->actingAs(User::factory()->platformAdmin()->create());

        $this->get($this->adminUrl('/settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('billing.gateway.webhook_url', fn (string $url) => str_ends_with($url, '/webhooks/billing/razorpay')));
    }
}
