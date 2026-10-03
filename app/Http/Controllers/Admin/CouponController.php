<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Billing\Models\BillingCoupon;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BillingPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin → Coupons: discount codes for plans. A used coupon can be switched off but not deleted, so
 * past payments keep pointing at it. Dates are whole days in India time.
 */
class CouponController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $redemptions = BillingPayment::withoutTenantScope()
            ->whereNotNull('coupon_id')
            ->whereIn('status', BillingCoupon::REDEEMING)
            ->selectRaw('coupon_id, count(*) as total')
            ->groupBy('coupon_id')
            ->pluck('total', 'coupon_id');

        return Inertia::render('admin/billing/Coupons', [
            'coupons' => BillingCoupon::query()->latest('id')->get()
                ->map(fn (BillingCoupon $coupon) => [...BillingPresenter::coupon($coupon), 'redemptions' => (int) ($redemptions[$coupon->id] ?? 0)])
                ->values(),
            'plans' => Plan::query()->where('code', '!=', config('billing.trial.plan'))->orderBy('sort_order')->get(['code', 'name'])->map(fn (Plan $plan) => ['value' => $plan->code, 'label' => $plan->name])->values(),
            'periods' => collect(config('billing.periods'))->map(fn (array $period, string $key) => ['value' => $key, 'label' => $period['label']])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $coupon = BillingCoupon::query()->create([...$this->validated($request), 'created_by_user_id' => $request->user()->getKey()]);
        $this->audit->log('billing.coupon_created', $coupon, ['code' => $coupon->code, 'type' => $coupon->type, 'value' => $coupon->value]);

        return back()->with('success', __('Coupon :code created.', ['code' => $coupon->code]));
    }

    public function update(Request $request, BillingCoupon $coupon): RedirectResponse
    {
        $before = $coupon->only(['code', 'type', 'value', 'plans', 'periods', 'max_redemptions', 'starts_at', 'ends_at', 'is_active']);
        $coupon->update($this->validated($request, $coupon));
        $this->audit->log('billing.coupon_updated', $coupon, ['before' => $before, 'after' => $coupon->only(array_keys($before))]);

        return back()->with('success', __('Coupon :code saved.', ['code' => $coupon->code]));
    }

    public function destroy(BillingCoupon $coupon): RedirectResponse
    {
        if (BillingPayment::withoutTenantScope()->where('coupon_id', $coupon->id)->exists()) {
            return back()->with('error', __('Coupon :code has been used, so it can only be switched off.', ['code' => $coupon->code]));
        }

        $coupon->delete();
        $this->audit->log('billing.coupon_deleted', null, ['code' => $coupon->code]);

        return back()->with('success', __('Coupon :code deleted.', ['code' => $coupon->code]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BillingCoupon $coupon = null): array
    {
        $request->merge(['code' => BillingCoupon::normalizeCode($request->input('code'))]);
        $paidPlans = Plan::query()->where('code', '!=', config('billing.trial.plan'))->pluck('code')->all();

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:'.BillingCoupon::CODE_PATTERN, Rule::unique('billing_coupons', 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in([BillingCoupon::PERCENT, BillingCoupon::FIXED])],
            'value' => ['required', $request->input('type') === BillingCoupon::PERCENT ? 'integer' : 'numeric', 'min:1', $request->input('type') === BillingCoupon::PERCENT ? 'max:100' : 'max:100000'],
            'plans' => ['nullable', 'array'],
            'plans.*' => ['string', Rule::in($paidPlans)],
            'periods' => ['nullable', 'array'],
            'periods.*' => ['string', Rule::in(array_keys(config('billing.periods')))],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'once_per_business' => ['required', 'boolean'],
            'first_payment_only' => ['required', 'boolean'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['required', 'boolean'],
        ], [
            'code.regex' => __('Use 3 to 30 letters, numbers and dashes, e.g. DIWALI25.'),
            'value.max' => $request->input('type') === BillingCoupon::PERCENT ? __('A percentage can be at most 100.') : __('A fixed discount can be at most ₹1,00,000.'),
        ]);

        $percent = $validated['type'] === BillingCoupon::PERCENT;

        return [
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'value' => $percent ? (int) $validated['value'] : (int) round($validated['value'] * 100),
            'plans' => array_values(array_unique($validated['plans'] ?? [])) ?: null,
            'periods' => array_values(array_unique($validated['periods'] ?? [])) ?: null,
            'max_redemptions' => $validated['max_redemptions'] ?? null,
            'once_per_business' => (bool) $validated['once_per_business'],
            'first_payment_only' => (bool) $validated['first_payment_only'],
            'starts_at' => isset($validated['starts_on']) ? Carbon::parse($validated['starts_on'], 'Asia/Kolkata')->startOfDay()->utc() : null,
            'ends_at' => isset($validated['ends_on']) ? Carbon::parse($validated['ends_on'], 'Asia/Kolkata')->endOfDay()->utc() : null,
            'is_active' => (bool) $validated['is_active'],
        ];
    }
}
