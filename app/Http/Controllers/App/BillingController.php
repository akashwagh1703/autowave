<?php

namespace App\Http\Controllers\App;

use App\Domain\AI\Support\AIUsageMeter;
use App\Domain\Automation\Models\Automation;
use App\Domain\Billing\Actions\ManagePayments;
use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Billing\Support\PriceCalculator;
use App\Domain\Billing\Support\QrCode;
use App\Domain\Files\Support\StorageAllowance;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BillingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'owner_methods' => collect(config('billing.owner_methods'))->map(fn (string $method) => ['value' => $method, 'label' => config("billing.methods.{$method}")])->values(),
            ],
            'gst' => $this->settings->gstEnabled(),
            'reference' => BillingSettings::paymentReference($tenant->id),
            'pending' => $pending ? BillingPresenter::payment($pending) : null,
            'payments' => BillingPayment::query()->with(['plan', 'invoice'])->latest('id')->limit(30)->get()
                ->map(fn (BillingPayment $payment) => BillingPresenter::payment($payment))->values(),
            'proof' => ['max_kb' => config('billing.proof.max_kb'), 'accept' => implode(',', config('billing.proof.mimetypes'))],
            'canManage' => $request->user()->can('billing.manage'),
        ]);
    }

    /** The amount for a plan and period, with a UPI link and QR code for exactly that amount. */
    public function quote(Request $request, PriceCalculator $prices): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('is_public', true)->where('is_active', true)],
            'period' => ['required', Rule::in(array_keys(config('billing.periods')))],
            'buyer_gstin' => ['nullable', 'string', 'max:15'],
        ]);

        $tenant = $this->context->tenant();
        $gstin = strtoupper(trim((string) ($validated['buyer_gstin'] ?? '')));
        $gstin = preg_match(ManagePayments::GSTIN_PATTERN, $gstin) ? $gstin : null;
        $quote = $prices->quote($tenant, Plan::query()->where('code', $validated['plan'])->firstOrFail(), $validated['period'], $gstin);
        $reference = BillingSettings::paymentReference($tenant->id);
        $upiLink = $this->settings->manualEnabled() && $quote->total > 0
            ? $this->settings->upiLink($quote->total, $reference.' '.$quote->plan->name)
            : null;

        return response()->json([
            ...$quote->toArray(),
            'upi_link' => $upiLink,
            'upi_qr' => $upiLink ? QrCode::dataUri($upiLink) : null,
        ]);
    }

    public function pay(Request $request, ManagePayments $payments): RedirectResponse
    {
        $payments->submit(
            $this->context->tenant(),
            $request->user(),
            $request->only(['plan', 'period', 'method', 'reference', 'paid_on', 'buyer_gstin']),
            $request->file('proof'),
        );

        return back()->with('success', __('Thanks! We will check your payment and confirm it by email, usually within a few hours.'));
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
