<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Tenant\Scopes\TenantScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * An issued invoice as an A4 PDF, rendered from the copy stored on the invoice (dompdf; remote files and
 * PHP in templates stay disabled, so nothing outside the invoice is fetched or run).
 */
class InvoicePdf
{
    public function render(BillingInvoice $invoice): string
    {
        $invoice->loadMissing(['payment' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)]);

        return Pdf::loadView('billing.invoice-pdf', [
            'invoice' => $invoice,
            'title' => $invoice->type === BillingInvoice::TAX_INVOICE ? __('Tax Invoice') : __('Invoice'),
            'method' => $invoice->payment ? config("billing.methods.{$invoice->payment->method}", $invoice->payment->method) : null,
            'reference' => $invoice->payment?->reference ?? $invoice->payment?->gateway_payment_id,
        ])
            ->setPaper('a4')
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->output();
    }

    public function download(BillingInvoice $invoice): Response
    {
        return response($this->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.self::filename($invoice).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function filename(BillingInvoice $invoice): string
    {
        return preg_replace('/[^A-Za-z0-9\-]+/', '-', $invoice->number).'.pdf';
    }

    /** "₹1,499.00" from paise, Indian digit grouping. */
    public static function money(int $paise): string
    {
        $negative = $paise < 0;
        $rupees = intdiv(abs($paise), 100);
        $digits = (string) $rupees;
        $grouped = strlen($digits) > 3
            ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($digits, 0, -3)).','.substr($digits, -3)
            : $digits;

        return ($negative ? '−' : '').'₹'.$grouped.'.'.str_pad((string) (abs($paise) % 100), 2, '0', STR_PAD_LEFT);
    }
}
