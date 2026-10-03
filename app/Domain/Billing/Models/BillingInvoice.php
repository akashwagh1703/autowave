<?php

namespace App\Domain\Billing\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The invoice for an approved payment. Seller, buyer and amounts are copied in when it is issued, so later
 * changes (GST registration, a new address, new prices) never alter it. `type` is `invoice` while GST is
 * off and `tax_invoice` once it is on.
 */
#[Fillable(['tenant_id', 'billing_payment_id', 'number', 'financial_year', 'sequence', 'type', 'issued_at', 'seller', 'buyer', 'lines', 'subtotal', 'tax_amount', 'total', 'tax'])]
class BillingInvoice extends Model
{
    use BelongsToTenant;

    public const INVOICE = 'invoice';

    public const TAX_INVOICE = 'tax_invoice';

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'seller' => 'array',
            'buyer' => 'array',
            'lines' => 'array',
            'tax' => 'array',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'sequence' => 'integer',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'billing_payment_id');
    }
}
