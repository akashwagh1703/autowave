<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Billing\Actions\ManagePayments;
use App\Domain\Billing\Actions\SubscriptionLifecycle;
use App\Domain\Billing\Models\BillingCoupon;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\InvoicePdf;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Scopes\TenantScope;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BillingPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super Admin → Payments: check reported payments against the bank or UPI app and approve or reject them,
 * record payments received another way, and adjust a business's subscription. Platform admins only.
 */
class BillingController extends Controller
{
    private const STATUSES = [BillingPayment::PENDING, BillingPayment::APPROVED, BillingPayment::REJECTED, BillingPayment::CANCELLED, BillingPayment::INITIATED, BillingPayment::EXPIRED];

    public function __construct(private readonly ManagePayments $payments) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', ...self::STATUSES])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? BillingPayment::PENDING;

        $payments = BillingPayment::withoutTenantScope()
            ->with(['plan', 'tenant:id,name,slug', 'submitter:id,name,email', 'reviewer:id,name', 'invoice' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)])
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'like', '%'.BillingPayment::normalizeReference($search).'%')
                ->orWhere('gateway_payment_id', trim($search))
                ->orWhere('gateway_order_id', trim($search))
                ->orWhere('coupon_code', BillingCoupon::normalizeCode($search))
                ->orWhereHas('tenant', fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('slug', "%{$search}%"))))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $payments->through(fn (BillingPayment $payment) => [
            ...BillingPresenter::payment($payment),
            'tenant' => $payment->tenant ? ['id' => $payment->tenant->id, 'name' => $payment->tenant->name, 'slug' => $payment->tenant->slug] : null,
            'submitted_by' => $payment->submitter ? ['name' => $payment->submitter->name, 'email' => $payment->submitter->email] : null,
            'reviewed_by' => $payment->reviewer?->name,
        ]);

        return Inertia::render('admin/billing/Payments', [
            'payments' => $payments,
            'filters' => ['status' => $status, 'search' => $filters['search'] ?? ''],
            'counts' => BillingPayment::withoutTenantScope()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function approve(Request $request, int $paymentId): RedirectResponse
    {
        $payment = $this->payments->approve($paymentId, $request->user());

        return back()->with('success', __('Payment approved. The business is paid until :date.', ['date' => $payment->covers_until?->timezone('Asia/Kolkata')->format('j M Y')]));
    }

    public function reject(Request $request, int $paymentId): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:250']]);
        $this->payments->reject($paymentId, $request->user(), $validated['reason']);

        return back()->with('success', __('Payment rejected. The owner was emailed the reason.'));
    }

    /** The screenshot or PDF the owner attached. */
    public function proof(int $paymentId): StreamedResponse
    {
        $payment = BillingPayment::withoutTenantScope()->findOrFail($paymentId);
        abort_unless($payment->proof_path && in_array($payment->proof_disk, array_keys(config('filesystems.disks')), true), 404);
        abort_unless(Storage::disk($payment->proof_disk)->exists($payment->proof_path), 404);

        return Storage::disk($payment->proof_disk)->response($payment->proof_path, 'payment-'.$payment->id, [
            'Content-Type' => $payment->proof_mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function invoice(int $invoiceId): Response
    {
        $invoice = BillingInvoice::withoutTenantScope()->with(['payment' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)])->findOrFail($invoiceId);

        return Inertia::render('admin/billing/Invoice', ['invoice' => BillingPresenter::invoice($invoice)]);
    }

    public function invoicePdf(int $invoiceId, InvoicePdf $pdf): HttpResponse
    {
        return $pdf->download(BillingInvoice::withoutTenantScope()->findOrFail($invoiceId));
    }

    /** A payment received another way (cash, cheque, a transfer without a request), or a free period. */
    public function record(Request $request, Tenant $tenant): RedirectResponse
    {
        if ($tenant->is_internal) {
            return back()->with('error', __('The internal AutoWave business does not pay.'));
        }

        $validated = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'code')->where('is_active', true)],
            'period' => ['required', Rule::in(array_keys(config('billing.periods')))],
            'method' => ['required', Rule::in(array_values(array_diff(array_keys(config('billing.methods')), ['online', 'coupon'])))],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'reference' => ['nullable', 'string', 'max:40'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['plan'] === config('billing.trial.plan')) {
            return back()->withErrors(['plan' => __('Choose a paid plan. Use "Change plan" to extend a trial.')]);
        }

        $payment = $this->payments->record($tenant, $request->user(), [
            ...$validated,
            'amount' => isset($validated['amount']) ? (int) round($validated['amount'] * 100) : null,
        ]);

        return back()->with('success', __(':name is paid until :date.', ['name' => $tenant->name, 'date' => $payment->covers_until?->timezone('Asia/Kolkata')->format('j M Y')]));
    }

    /** Corrections: set the plan and end date directly. */
    public function adjust(Request $request, Tenant $tenant, SubscriptionLifecycle $lifecycle, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'code')],
            'period' => ['nullable', Rule::in(array_keys(config('billing.periods')))],
            'ends_at' => ['nullable', 'date', 'after:today'],
            'never_ends' => ['boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:250'],
        ]);

        $neverEnds = (bool) ($validated['never_ends'] ?? false);

        if (! $neverEnds && empty($validated['ends_at'])) {
            return back()->withErrors(['ends_at' => __('Choose an end date, or tick "Never ends".')]);
        }

        $plan = Plan::query()->where('code', $validated['plan'])->firstOrFail();
        $endsAt = $neverEnds ? null : Carbon::parse($validated['ends_at'], 'Asia/Kolkata')->endOfDay()->utc();
        $lifecycle->adjust($tenant, $plan, $endsAt, $validated['period'] ?? null);
        $audit->log('billing.subscription_adjusted', $tenant, [
            'plan' => $plan->code,
            'period' => $validated['period'] ?? null,
            'ends_at' => $endsAt?->toIso8601String(),
            'reason' => $validated['reason'],
        ], $tenant->id);

        return back()->with('success', __('Subscription for :name updated.', ['name' => $tenant->name]));
    }
}
