<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BillingPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin → Plans: names, prices and limits. A new price applies to the next payment; periods already
 * paid keep what was paid. Plans are never deleted, only hidden.
 */
class PlanController extends Controller
{
    public function index(): Response
    {
        $subscribers = Subscription::withoutTenantScope()->selectRaw('plan_id, count(*) as total')->groupBy('plan_id')->pluck('total', 'plan_id');

        return Inertia::render('admin/billing/Plans', [
            'plans' => Plan::query()->orderBy('sort_order')->get()->map(fn (Plan $plan) => [
                ...BillingPresenter::plan($plan),
                'is_trial' => $plan->isTrial(),
                'subscribers' => (int) ($subscribers[$plan->id] ?? 0),
            ]),
        ]);
    }

    public function update(Request $request, Plan $plan, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:300'],
            'price_monthly' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'price_yearly' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'is_public' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'limits.members' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'limits.storage_mb' => ['required', 'integer', 'min:0', 'max:10000000'],
            'limits.ai_tokens' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'limits.automations' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'limits.instagram' => ['required', 'boolean'],
        ]);

        if ($plan->isTrial()) {
            $validated['price_monthly'] = $validated['price_yearly'] = 0;
            $validated['is_public'] = false;
        } elseif ($validated['is_public'] && ($validated['price_monthly'] <= 0 || $validated['price_yearly'] <= 0)) {
            return back()->withErrors(['price_monthly' => __('A plan businesses can choose needs a price above zero.')]);
        }

        $before = $plan->only(['name', 'price_monthly', 'price_yearly', 'limits', 'is_public', 'is_active']);
        $plan->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price_monthly' => (int) round($validated['price_monthly'] * 100),
            'price_yearly' => (int) round($validated['price_yearly'] * 100),
            'is_public' => (bool) $validated['is_public'],
            'is_active' => (bool) $validated['is_active'],
            'limits' => [
                'members' => isset($validated['limits']['members']) ? (int) $validated['limits']['members'] : null,
                'storage_mb' => (int) $validated['limits']['storage_mb'],
                'ai_tokens' => (int) $validated['limits']['ai_tokens'],
                'automations' => isset($validated['limits']['automations']) ? (int) $validated['limits']['automations'] : null,
                'instagram' => (bool) $validated['limits']['instagram'],
            ],
        ]);
        $audit->log('billing.plan_updated', $plan, ['before' => $before, 'after' => $plan->only(['name', 'price_monthly', 'price_yearly', 'limits', 'is_public', 'is_active'])]);

        return back()->with('success', __(':plan plan saved.', ['plan' => $plan->name]));
    }
}
