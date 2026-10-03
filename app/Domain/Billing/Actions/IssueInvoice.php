<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The invoice for an approved payment, numbered {prefix}/{financial year}/{0001} without gaps (an advisory
 * lock serialises numbering). Seller, buyer and amounts are copied in and never change afterwards. A tax
 * invoice when GST was charged on the payment. Call inside the approving transaction.
 */
class IssueInvoice
{
    public function __construct(private readonly BillingSettings $settings) {}

    public function handle(BillingPayment $payment, ?Carbon $at = null): BillingInvoice
    {
        $at ??= now();
        $year = self::financialYear($at);

        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('billing_invoices')]);
        $sequence = (int) BillingInvoice::withoutTenantScope()->where('financial_year', $year)->max('sequence') + 1;

        $tenant = Tenant::query()->findOrFail($payment->tenant_id);
        $gst = ! empty($payment->tax);
        $payment->loadMissing('plan');

        $lines = [[
            'description' => __(':plan plan, :period (:from – :until)', [
                'plan' => $payment->plan->name,
                'period' => strtolower(config("billing.periods.{$payment->period}.label")),
                'from' => $payment->covers_from?->timezone('Asia/Kolkata')->format('j M Y'),
                'until' => $payment->covers_until?->timezone('Asia/Kolkata')->format('j M Y'),
            ]),
            'amount' => $payment->amount + $payment->credit + $payment->discount,
        ]];

        if ($payment->credit > 0) {
            $lines[] = ['description' => __('Credit for unused days of the previous plan'), 'amount' => -$payment->credit];
        }

        if ($payment->discount > 0) {
            $lines[] = ['description' => __('Discount (coupon :code)', ['code' => $payment->coupon_code]), 'amount' => -$payment->discount];
        }

        $invoice = new BillingInvoice([
            'tenant_id' => $payment->tenant_id,
            'billing_payment_id' => $payment->id,
            'number' => sprintf('%s/%s/%04d', config('billing.invoice_prefix'), $year, $sequence),
            'financial_year' => $year,
            'sequence' => $sequence,
            'type' => $gst ? BillingInvoice::TAX_INVOICE : BillingInvoice::INVOICE,
            'issued_at' => $at,
            'seller' => [
                ...$this->settings->get('seller'),
                'gstin' => $gst ? $this->settings->get('gst.gstin') : null,
            ],
            'buyer' => [
                'name' => $tenant->name,
                'reference' => BillingSettings::paymentReference($tenant->id),
                'gstin' => $payment->buyer_gstin,
            ],
            'lines' => $lines,
            'subtotal' => $payment->amount,
            'tax_amount' => $payment->tax_amount,
            'total' => $payment->total,
            'tax' => $payment->tax ?: null,
        ]);
        $invoice->save();

        return $invoice;
    }

    /** April–March, e.g. "2026-27", in Indian time. */
    public static function financialYear(Carbon $at): string
    {
        $local = $at->copy()->timezone('Asia/Kolkata');
        $start = $local->month >= 4 ? $local->year : $local->year - 1;

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }
}
