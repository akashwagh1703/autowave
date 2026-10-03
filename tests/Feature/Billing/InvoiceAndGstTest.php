<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\ManagePayments;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class InvoiceAndGstTest extends TestCase
{
    use CreatesTenants, ManagesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Kolkata'));
        $this->configureBilling();
    }

    private function submitAndApprove(Tenant $tenant, string $reference, ?string $gstin = null): BillingPayment
    {
        $this->app->forgetScopedInstances();
        $payments = app(ManagePayments::class);
        $payment = $payments->submit($tenant, $this->ownerOf($tenant), [
            'plan' => 'starter', 'period' => 'monthly', 'method' => 'upi', 'reference' => $reference, 'paid_on' => now()->toDateString(), 'buyer_gstin' => $gstin,
        ]);

        return $payments->approve($payment->id, User::factory()->platformAdmin()->create());
    }

    public function test_without_gst_the_document_is_an_invoice_with_no_tax(): void
    {
        $this->submitAndApprove($this->createTenant(), 'UTR000000001');

        $invoice = BillingInvoice::withoutTenantScope()->sole();
        $this->assertSame(BillingInvoice::INVOICE, $invoice->type);
        $this->assertSame(49900, $invoice->total);
        $this->assertSame(0, $invoice->tax_amount);
        $this->assertNull($invoice->seller['gstin']);
    }

    public function test_with_gst_inside_the_state_cgst_and_sgst_are_added(): void
    {
        $this->enableGst('27ABCDE1234F1Z5');
        $this->submitAndApprove($this->createTenant(), 'UTR000000002');

        $invoice = BillingInvoice::withoutTenantScope()->sole();
        $this->assertSame(BillingInvoice::TAX_INVOICE, $invoice->type);
        $this->assertSame(['CGST', 'SGST'], array_column($invoice->tax, 'label'));
        $this->assertSame(8982, $invoice->tax_amount);
        $this->assertSame(49900 + 8982, $invoice->total);
        $this->assertSame('27ABCDE1234F1Z5', $invoice->seller['gstin']);
    }

    public function test_a_buyer_in_another_state_pays_igst(): void
    {
        $this->enableGst('27ABCDE1234F1Z5');
        $this->submitAndApprove($this->createTenant(), 'UTR000000003', '29AAAAA0000A1Z5');

        $invoice = BillingInvoice::withoutTenantScope()->sole();
        $this->assertEquals([['label' => 'IGST', 'rate' => 18, 'amount' => 8982]], $invoice->tax);
        $this->assertSame('29AAAAA0000A1Z5', $invoice->buyer['gstin']);
    }

    public function test_gst_needs_a_gstin_and_a_quote_keeps_its_tax_after_gst_changes(): void
    {
        $tenant = $this->createTenant();
        $this->configureBilling(['gst' => ['enabled' => true, 'gstin' => null]]);
        $payment = app(ManagePayments::class)->submit($tenant, $this->ownerOf($tenant), [
            'plan' => 'starter', 'period' => 'monthly', 'method' => 'upi', 'reference' => 'UTR000000004', 'paid_on' => now()->toDateString(),
        ]);
        $this->assertSame(49900, $payment->total, 'GST switched on without a GSTIN adds no tax.');

        $this->enableGst();
        app(ManagePayments::class)->approve($payment->id, User::factory()->platformAdmin()->create());

        $invoice = BillingInvoice::withoutTenantScope()->sole();
        $this->assertSame(BillingInvoice::INVOICE, $invoice->type);
        $this->assertSame(49900, $invoice->total);
    }

    public function test_invoice_numbers_have_no_gaps_and_restart_each_financial_year(): void
    {
        $this->submitAndApprove($this->createTenant('One'), 'UTR000000011');
        $this->submitAndApprove($this->createTenant('Two'), 'UTR000000012');

        $this->travelTo(Carbon::parse('2027-04-01 09:00', 'Asia/Kolkata'));
        $this->submitAndApprove($this->createTenant('Three'), 'UTR000000013');

        $this->assertSame(
            ['AW/2026-27/0001', 'AW/2026-27/0002', 'AW/2027-28/0001'],
            BillingInvoice::withoutTenantScope()->orderBy('id')->pluck('number')->all(),
        );
    }
}
