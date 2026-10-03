<?php

namespace App\Domain\Billing\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A payment for a plan period, whatever the method (UPI, bank transfer, cash, online). Amounts are in paise
 * and always come from PriceCalculator. Only an approved payment changes the subscription.
 */
#[Fillable([
    'tenant_id', 'plan_id', 'period', 'kind', 'credit', 'amount', 'tax_amount', 'total', 'tax', 'method', 'status', 'reference',
    'paid_on', 'proof_disk', 'proof_path', 'proof_mime', 'buyer_gstin', 'note', 'submitted_by_user_id', 'reviewed_by_user_id',
    'reviewed_at', 'rejection_reason', 'covers_from', 'covers_until', 'gateway', 'gateway_payment_id', 'gateway_order_id',
    'coupon_id', 'coupon_code', 'discount',
])]
class BillingPayment extends Model
{
    use BelongsToTenant;

    /** Online checkout started; becomes approved when the gateway confirms the money. */
    public const INITIATED = 'initiated';

    /** An online checkout that was abandoned or replaced. A late gateway confirmation still completes it. */
    public const EXPIRED = 'expired';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Starts now and replaces the current plan (upgrade, or nothing paid is running). */
    public const KIND_NOW = 'now';

    /** Starts when the paid period ends (renewal, or a switch to a cheaper plan or other period). */
    public const KIND_RENEWAL = 'renewal';

    protected function casts(): array
    {
        return [
            'credit' => 'integer',
            'discount' => 'integer',
            'amount' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'tax' => 'array',
            'paid_on' => 'date',
            'reviewed_at' => 'datetime',
            'covers_from' => 'datetime',
            'covers_until' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(BillingCoupon::class, 'coupon_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(BillingInvoice::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** The UTR / reference as stored: upper case, no spaces or dashes. */
    public static function normalizeReference(?string $reference): ?string
    {
        $value = strtoupper((string) preg_replace('/[\s\-]+/', '', (string) $reference));

        return $value === '' ? null : $value;
    }
}
