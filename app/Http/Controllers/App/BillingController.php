<?php

namespace App\Http\Controllers\App;

use App\Domain\AI\Support\AIUsageMeter;
use App\Domain\Automation\Models\Automation;
use App\Domain\Billing\Actions\ManagePayments;
use App\Domain\Billing\Actions\OnlineCheckout;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Billing\Support\InvoicePdf;
use App\Domain\Billing\Support\QrCode;
use App\Domain\Files\Support\StorageAllowance;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BillingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Billing: the plan, usage, how to pay, payment history and invoices (owners only by default:
 * billing.view / billing.manage). Always reachable, even when the business is read-only or locked.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Entitlements $entitlements,
        private readonly BillingSettings $settings,
    ) {}

    public function show(Request $request, StorageAllowance $storage, AIUsageMeter $ai): Response
    {
        $tenant = $this->context->tenant();
        $plan = $this->entitlements->plan($tenant);
        $pending = BillingPayment::query()->with('plan')->where('status', BillingPayment::PENDING)->first();

        return Inertia::render('business/billing/Index', [
            'subscription' => BillingPresenter::subscription($tenant, $this->entitlements),
            'plans' => Plan::query()->purchasable()->get()->map(fn (Plan $plan) => BillingPresenter::plan($plan))->values(),
            'periods' => collect(config('billing.periods'))->map(fn (array $period, string $key) => ['value' => $key, 'label' => $period['label']])->values(),
            'usage' => [
                'members' => ['used' => $tenant->memberships()->where('status', MembershipStatus::Active)->count(), 'limit' => $plan?->limit('members')],
                'storage' => ['used_bytes' => $storage->usedBytes($tenant), 'cap_bytes' => $storage->capMb($tenant) * 1024 * 1024],
                'ai' => ['used' => $ai->used($tenant), 'cap' => $ai->cap($tenant)],
                'automations' => ['used' => Automation::query()->where('is_active', true)->count(), 'limit' => $plan?->limit('automations')],
                'instagram' => $plan?->limit('instagram') !== false,
            ],
            'methods' => [
                'manual' => $this->settings->manualDetails(),
                'online' => $this->settings->onlineEnabled(),
                'online_minimum' => (int) config('billing.online_minimum'),
                'owner_methods' => collect(config('billing.owner_methods'))->map(fn (string $method) => ['value' => $method, 'label' => config("billing.methods.{$method}")])->values(),
            ],
            'gst' => $this->settings->gstEnabled(),
            'reference' => BillingSettings::paymentReference($tenant->id),
            'pending' => $pending ? BillingPresenter::payment($pending) : null,
            'payments' => BillingPayment::query()->with(['plan', 'invoice'])->whereNotIn('status', [BillingPayment::INITIATED, BillingPayment::EXPIRED])->latest('id')->limit(30)->get()
                ->map(fn (BillingPayment $payment) => BillingPresenter::payment($payment))->values(),
            'proof' => ['max_kb' => config('billing.proof.max_kb'), 'accept' => implode(',', config('billing.proof.mimetypes'))],
            'canManage' => $request->user()->can('billing.manage'),
        ]);
    }

    /**
     * The amount for a plan and period (with a coupon, if given), with a UPI link and QR code for exactly
     * that amount. A coupon that can't be used is a 422 on `coupon`.
     */
    public function quote(Request $request, ManagePayments $payments): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('is_public', true)->where('is_active', true)],
            'period' => ['required', Rule::in(array_keys(config('billing.periods')))],
            'buyer_gstin' => ['nullable', 'string', 'max:15'],
            'coupon' => ['nullable', 'string', 'max:30'],
        ]);

        $tenant = $this->context->tenant();
        $gstin = ManagePayments::normalizeGstin($validated['buyer_gstin'] ?? null);
        $gstin = $gstin && preg_match(ManagePayments::GSTIN_PATTERN, $gstin) ? $gstin : null;
        $plan = Plan::query()->where('code', $validated['plan'])->firstOrFail();
        $quote = $payments->quoteForPayment($tenant, $plan, $validated['period'], $gstin, $validated['coupon'] ?? null);
        $reference = BillingSettings::paymentReference($tenant->id);
        $upiLink = $this->settings->manualEnabled() && $quote->total > 0
            ? $this->settings->upiLink($quote->total, $reference.' '.$quote->plan->name)
            : null;

        return response()->json([
            ...$quote->toArray(),
            'online_available' => $this->settings->onlineEnabled() && $quote->total >= (int) config('billing.online_minimum'),
            'upi_link' => $upiLink,
            'upi_qr' => $upiLink ? QrCode::dataUri($upiLink) : null,
        ]);
    }

    public function pay(Request $request, ManagePayments $payments): RedirectResponse
    {
        $payments->submit(
            $this->context->tenant(),
            $request->user(),
            $request->only(['plan', 'period', 'method', 'reference', 'paid_on', 'buyer_gstin', 'coupon']),
            $request->file('proof'),
        );

        return back()->with('success', __('Thanks! We will check your payment and confirm it by email, usually within a few hours.'));
    }

    /** Opens an online checkout: the browser gets the gateway order to pay (amount set by the server). */
    public function checkout(Request $request, OnlineCheckout $checkout): JsonResponse
    {
        $started = $checkout->start($this->context->tenant(), $request->user(), $request->only(['plan', 'period', 'buyer_gstin', 'coupon']));

        return response()->json(['checkout' => $started['checkout']]);
    }

    /** The gateway checkout's success callback. The payment is applied only after the server verifies it. */
    public function confirm(Request $request, BillingPayment $billingPayment, OnlineCheckout $checkout): JsonResponse
    {
        abort_unless($billingPayment->method === 'online', 404);

        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'payment_id' => ['required', 'string', 'max:100'],
            'signature' => ['required', 'string', 'max:200'],
        ]);

        $payment = $checkout->confirm($billingPayment, $validated['order_id'], $validated['payment_id'], $validated['signature']);

        return response()->json([
            'message' => __('Payment received. Your plan is active until :date. The invoice is on its way to your email.', [
                'date' => $payment->covers_until?->timezone('Asia/Kolkata')->format('j M Y'),
            ]),
        ]);
    }

    /** A coupon that covers the whole price: the plan starts without a payment. */
    public function activate(Request $request, ManagePayments $payments): RedirectResponse
    {
        $payment = $payments->activateWithCoupon($this->context->tenant(), $request->user(), $request->only(['plan', 'period', 'coupon']));

        return back()->with('success', __('Coupon applied. Your plan is active until :date.', [
            'date' => $payment->covers_until?->timezone('Asia/Kolkata')->format('j M Y'),
        ]));
    }

    public function cancel(BillingPayment $billingPayment, ManagePayments $payments): RedirectResponse
    {
        $payments->cancel($billingPayment);

        return back()->with('success', __('The payment was withdrawn. You can pay another way now.'));
    }

    public function invoice(BillingInvoice $invoice): Response
    {
        return Inertia::render('business/billing/Invoice', [
            'invoice' => BillingPresenter::invoice($invoice->load('payment')),
        ]);
    }

    public function invoicePdf(BillingInvoice $invoice, InvoicePdf $pdf): HttpResponse
    {
        return $pdf->download($invoice);
    }

    /** The UPI QR image uploaded by AutoWave (the business pays to it). */
    public function qr(): StreamedResponse
    {
        $qr = $this->settings->qr();
        abort_unless($qr && $this->settings->manualEnabled() && Storage::disk($qr['disk'])->exists($qr['path']), 404);

        return Storage::disk($qr['disk'])->response($qr['path'], null, [
            'Content-Type' => $qr['mime'],
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
