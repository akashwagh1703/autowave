<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Notifications\PaymentReviewed;
use App\Domain\Billing\Notifications\PaymentSubmitted;
use App\Domain\Billing\Support\BillingRecipients;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Billing\Support\PriceCalculator;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Payments for plans: an owner reports a manual payment (UPI or bank transfer, with the UTR and an optional
 * screenshot), a platform admin approves or rejects it, or records one received another way. The amount
 * always comes from PriceCalculator; only approval changes the subscription.
 */
class ManagePayments
{
    public const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    private const PROOF_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly IssueInvoice $invoices,
        private readonly BillingSettings $settings,
        private readonly Entitlements $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  plan, period, method, reference, paid_on, buyer_gstin
     *
     * @throws ValidationException
     */
    public function submit(Tenant $tenant, User $actor, array $input, ?UploadedFile $proof = null): BillingPayment
    {
        if (! $this->settings->manualEnabled()) {
            throw ValidationException::withMessages(['method' => __('Paying by UPI or bank transfer is switched off. Choose another way to pay.')]);
        }

        $input['reference'] = BillingPayment::normalizeReference($input['reference'] ?? null);
        $input['buyer_gstin'] = isset($input['buyer_gstin']) ? strtoupper(trim((string) $input['buyer_gstin'])) ?: null : null;
        $proofLimits = config('billing.proof');

        $data = Validator::make([...$input, 'proof' => $proof], [
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('is_public', true)->where('is_active', true)],
            'period' => ['required', Rule::in(array_keys(config('billing.periods')))],
            'method' => ['required', Rule::in(config('billing.owner_methods'))],
            'reference' => ['required', 'string', 'regex:/^[A-Z0-9]{6,40}$/'],
            'paid_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(60)->toDateString()],
            'buyer_gstin' => ['nullable', 'regex:'.self::GSTIN_PATTERN],
            'proof' => ['nullable', 'file', 'max:'.$proofLimits['max_kb'], 'mimetypes:'.implode(',', $proofLimits['mimetypes']), 'mimes:'.implode(',', $proofLimits['mimes'])],
        ], [
            'reference.required' => __('Enter the UTR or transaction ID from your payment app or bank.'),
            'reference.regex' => __('The UTR or transaction ID should be 6 to 40 letters and numbers.'),
            'buyer_gstin.regex' => __('Enter a valid 15-character GSTIN, or leave it empty.'),
            'proof.max' => __('The screenshot must be :max MB or smaller.', ['max' => $proofLimits['max_kb'] / 1024]),
            'proof.mimetypes' => __('Upload a JPG, PNG or WebP screenshot, or a PDF.'),
            'proof.mimes' => __('Upload a JPG, PNG or WebP screenshot, or a PDF.'),
        ])->validate();

        $this->ensureNoPending($tenant);
        $this->ensureReferenceUnused($data['reference']);

        $plan = Plan::query()->where('code', $data['plan'])->firstOrFail();
        $quote = $this->prices->quote($tenant, $plan, $data['period'], $data['buyer_gstin']);
        $stored = $proof ? $this->storeProof($tenant, $proof) : null;

        try {
            $payment = DB::transaction(fn () => BillingPayment::withoutTenantScope()->create([
                'tenant_id' => $tenant->getKey(),
                'plan_id' => $plan->id,
                'period' => $quote->period,
                'kind' => $quote->kind,
                'credit' => $quote->credit,
                'amount' => $quote->amount,
                'tax_amount' => $quote->taxAmount,
                'total' => $quote->total,
                'tax' => $quote->tax ?: null,
                'method' => $data['method'],
                'status' => BillingPayment::PENDING,
                'reference' => $data['reference'],
                'paid_on' => $data['paid_on'],
                'proof_disk' => $stored['disk'] ?? null,
                'proof_path' => $stored['path'] ?? null,
                'proof_mime' => $stored['mime'] ?? null,
                'buyer_gstin' => $data['buyer_gstin'],
                'submitted_by_user_id' => $actor->getKey(),
            ]));
        } catch (UniqueConstraintViolationException) {
            if ($stored) {
                Storage::disk($stored['disk'])->delete($stored['path']);
            }

            throw ValidationException::withMessages(['reference' => __('This payment was already submitted. Wait for it to be checked, or contact AutoWave support.')]);
        }

        $this->entitlements->forget($tenant);
        $this->audit->log('billing.payment_submitted', $payment, ['plan' => $plan->code, 'period' => $quote->period, 'total' => $quote->total, 'method' => $data['method']], $tenant->getKey());
        Notification::send(BillingRecipients::platformAdmins(), new PaymentSubmitted($payment->id, $tenant->name));

        return $payment;
    }

    /** The owner withdraws a payment still waiting for approval (to pay another way). */
    public function cancel(BillingPayment $payment): void
    {
        $this->review($payment->id, function (BillingPayment $payment) {
            $payment->update(['status' => BillingPayment::CANCELLED]);
            $this->audit->log('billing.payment_cancelled', $payment, [], $payment->tenant_id);
        });
    }

    /** Platform admin: the money arrived. Extends the subscription and issues the invoice. */
    public function approve(int $paymentId, User $admin): BillingPayment
    {
        $payment = $this->review($paymentId, function (BillingPayment $payment) use ($admin) {
            [$from, $until] = $this->lifecycle->apply($payment);
            $payment->update([
                'status' => BillingPayment::APPROVED,
                'reviewed_by_user_id' => $admin->getKey(),
                'reviewed_at' => now(),
                'covers_from' => $from,
                'covers_until' => $until,
            ]);

            if ($payment->total > 0) {
                $this->invoices->handle($payment);
            }

            $this->audit->log('billing.payment_approved', $payment, ['total' => $payment->total, 'until' => $until->toIso8601String()], $payment->tenant_id);
        });

        $this->notifyOwners($payment);

        return $payment;
    }

    /** Platform admin: the money did not arrive or the details are wrong. */
    public function reject(int $paymentId, User $admin, string $reason): BillingPayment
    {
        $payment = $this->review($paymentId, function (BillingPayment $payment) use ($admin, $reason) {
            $payment->update([
                'status' => BillingPayment::REJECTED,
                'reviewed_by_user_id' => $admin->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => Str::limit(trim($reason), 250, ''),
            ]);
            $this->audit->log('billing.payment_rejected', $payment, ['reason' => $payment->rejection_reason], $payment->tenant_id);
        });

        $this->notifyOwners($payment);

        return $payment;
    }

    /**
     * Platform admin: a payment received without a request (cash, cheque, a transfer), or a complimentary
     * period. `amount` (paise) overrides the quote, e.g. for a discount; complimentary is always free.
     *
     * @param  array{plan: string, period: string, method: string, reference?: ?string, paid_on?: ?string, amount?: ?int, note?: ?string}  $input
     */
    public function record(Tenant $tenant, User $admin, array $input): BillingPayment
    {
        $plan = Plan::query()->where('code', $input['plan'])->where('is_active', true)->firstOrFail();
        $reference = BillingPayment::normalizeReference($input['reference'] ?? null);

        if ($reference) {
            $this->ensureReferenceUnused($reference);
        }

        $quote = $this->prices->quote($tenant, $plan, $input['period']);
        $amount = $input['method'] === 'complimentary' ? 0 : ($input['amount'] ?? $quote->amount);
        $tax = $amount === $quote->amount ? $quote->tax : $this->prices->tax($amount);
        $taxAmount = array_sum(array_column($tax, 'amount'));

        try {
            $payment = DB::transaction(function () use ($tenant, $admin, $input, $plan, $quote, $amount, $tax, $taxAmount, $reference) {
                $payment = BillingPayment::withoutTenantScope()->create([
                    'tenant_id' => $tenant->getKey(),
                    'plan_id' => $plan->id,
                    'period' => $quote->period,
                    'kind' => $quote->kind,
                    'credit' => $amount === $quote->amount ? $quote->credit : 0,
                    'amount' => $amount,
                    'tax_amount' => $taxAmount,
                    'total' => $amount + $taxAmount,
                    'tax' => $tax ?: null,
                    'method' => $input['method'],
                    'status' => BillingPayment::APPROVED,
                    'reference' => $reference,
                    'paid_on' => $input['paid_on'] ?? now()->toDateString(),
                    'note' => $input['note'] ?? null,
                    'submitted_by_user_id' => $admin->getKey(),
                    'reviewed_by_user_id' => $admin->getKey(),
                    'reviewed_at' => now(),
                ]);

                [$from, $until] = $this->lifecycle->apply($payment);
                $payment->update(['covers_from' => $from, 'covers_until' => $until]);

                if ($payment->total > 0) {
                    $this->invoices->handle($payment);
                }

                $this->audit->log('billing.payment_recorded', $payment, ['plan' => $plan->code, 'method' => $input['method'], 'total' => $payment->total, 'until' => $until->toIso8601String()], $tenant->getKey());

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => __('This reference was already used for another payment.')]);
        }

        $this->entitlements->forget($tenant);
        $this->notifyOwners($payment);

        return $payment;
    }

    /** Locks the payment, checks it is still pending, and runs $change in one transaction. */
    private function review(int $paymentId, callable $change): BillingPayment
    {
        return DB::transaction(function () use ($paymentId, $change) {
            $payment = BillingPayment::withoutTenantScope()->lockForUpdate()->findOrFail($paymentId);

            if (! $payment->isPending()) {
                throw ValidationException::withMessages(['payment' => __('This payment was already :status.', ['status' => $payment->status])]);
            }

            $change($payment);

            return $payment;
        });
    }

    private function ensureNoPending(Tenant $tenant): void
    {
        if (BillingPayment::withoutTenantScope()->where('tenant_id', $tenant->getKey())->where('status', BillingPayment::PENDING)->exists()) {
            throw ValidationException::withMessages(['plan' => __('A payment is already waiting to be checked. Cancel it first to pay another way.')]);
        }
    }

    private function ensureReferenceUnused(string $reference): void
    {
        if (BillingPayment::withoutTenantScope()->where('reference', $reference)->whereIn('status', [BillingPayment::PENDING, BillingPayment::APPROVED])->exists()) {
            throw ValidationException::withMessages(['reference' => __('This UTR or transaction ID was already used for another payment.')]);
        }
    }

    /** @return array{disk: string, path: string, mime: string} */
    private function storeProof(Tenant $tenant, UploadedFile $proof): array
    {
        $mime = (string) $proof->getMimeType();
        $disk = (string) config('files.disks.private');
        $path = Storage::disk($disk)->putFileAs('billing/proofs/'.$tenant->getKey(), $proof, Str::lower((string) Str::ulid()).'.'.self::PROOF_EXTENSIONS[$mime]);

        if ($path === false) {
            throw ValidationException::withMessages(['proof' => __('The screenshot could not be saved. Try again, or submit without it.')]);
        }

        return ['disk' => $disk, 'path' => $path, 'mime' => $mime];
    }

    private function notifyOwners(BillingPayment $payment): void
    {
        $tenant = Tenant::query()->find($payment->tenant_id);

        if ($tenant) {
            $this->entitlements->forget($tenant);
            Notification::send(BillingRecipients::owners($tenant), new PaymentReviewed($payment->id));
        }
    }
}
