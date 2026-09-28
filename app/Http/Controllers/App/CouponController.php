<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Actions\SaveCoupon;
use App\Domain\Commerce\Models\Coupon;
use App\Http\Controllers\Controller;
use App\Support\TenantTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Offers: coupon codes customers (or the team) apply to orders. Edited in dialogs on one page. */
class CouponController extends Controller
{
    public function index(): Response
    {
        $coupons = Coupon::query()
            ->withCount(['orders as orders_count' => fn ($query) => $query->where('status', '!=', 'cancelled')])
            ->withSum(['orders as discount_given' => fn ($query) => $query->where('status', '!=', 'cancelled')], 'discount')
            ->orderByDesc('is_active')->orderBy('code')
            ->get();

        return Inertia::render('business/offers/Index', [
            'coupons' => $coupons->map(fn (Coupon $coupon) => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'description' => $coupon->description,
                'type' => $coupon->type,
                'value' => (string) $coupon->value,
                'summary' => $coupon->summary(),
                'min_subtotal' => $coupon->min_subtotal !== null ? (string) $coupon->min_subtotal : null,
                'max_discount' => $coupon->max_discount !== null ? (string) $coupon->max_discount : null,
                'starts_at' => $coupon->starts_at?->toIso8601String(),
                'ends_at' => $coupon->ends_at?->toIso8601String(),
                'usage_limit' => $coupon->usage_limit,
                'times_used' => $coupon->times_used,
                'online' => $coupon->online,
                'is_active' => $coupon->is_active,
                'is_live' => $coupon->is_active
                    && ! $coupon->starts_at?->isFuture()
                    && ! $coupon->ends_at?->isPast()
                    && ($coupon->usage_limit === null || $coupon->times_used < $coupon->usage_limit),
                'orders_count' => (int) $coupon->orders_count,
                'discount_given' => number_format((float) ($coupon->discount_given ?? 0), 2, '.', ''),
            ]),
        ]);
    }

    public function store(Request $request, SaveCoupon $saveCoupon): RedirectResponse
    {
        $coupon = $saveCoupon->handle($this->validated($request));

        return back()->with('success', __('Coupon :code created.', ['code' => $coupon->code]));
    }

    public function update(Request $request, Coupon $coupon, SaveCoupon $saveCoupon): RedirectResponse
    {
        $saveCoupon->handle($this->validated($request), $coupon);

        return back()->with('success', __('Coupon updated.'));
    }

    public function destroy(Coupon $coupon, AuditLogger $audit): RedirectResponse
    {
        $coupon->delete();
        $audit->log('coupon.deleted', $coupon, ['code' => $coupon->code]);

        return back()->with('success', __('Coupon deleted. Past orders keep their code.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:150'],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'max_discount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999.99'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'online' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        return [
            ...$validated,
            'starts_at' => TenantTime::parse($validated['starts_at'] ?? null),
            'ends_at' => TenantTime::parse($validated['ends_at'] ?? null),
        ];
    }
}
