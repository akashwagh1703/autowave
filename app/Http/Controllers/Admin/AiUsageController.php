<?php

namespace App\Http\Controllers\Admin;

use App\Domain\AI\Models\AIUsage;
use App\Domain\AI\Support\AIUsageMeter;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Super Admin → AI usage: tokens, requests and cost per business per month, and per-business caps (ADR-019). */
class AiUsageController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $start = isset($filters['month']) ? Carbon::createFromFormat('Y-m-d', $filters['month'].'-01', 'UTC')->startOfMonth() : AIUsageMeter::monthStart();
        $end = $start->copy()->addMonth();
        $current = $start->equalTo(AIUsageMeter::monthStart());

        $usage = fn () => AIUsage::withoutTenantScope()->where('created_at', '>=', $start)->where('created_at', '<', $end);

        $perTenant = $usage()
            ->selectRaw("tenant_id, count(*) as requests, coalesce(sum(total_tokens), 0) as tokens, coalesce(sum(cost), 0) as cost, sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->groupBy('tenant_id');

        $tenants = Tenant::query()
            ->leftJoinSub($perTenant, 'u', 'u.tenant_id', '=', 'tenants.id')
            ->select(['tenants.id', 'tenants.name', 'tenants.slug', 'u.requests', 'u.tokens', 'u.cost', 'u.failed'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->whereLike('tenants.name', "%{$search}%")
                ->orWhereLike('tenants.slug', "%{$search}%")))
            ->orderByRaw('coalesce(u.tokens, 0) desc')
            ->orderBy('tenants.id')
            ->paginate(25)
            ->withQueryString();

        $caps = TenantSetting::withoutTenantScope()
            ->where('key', AIUsageMeter::QUOTA_KEY)
            ->whereIn('tenant_id', $tenants->getCollection()->modelKeys())
            ->pluck('value', 'tenant_id');
        $default = (int) config('ai.limits.monthly_tokens');
        $meter = app(AIUsageMeter::class);

        $tenants->through(function (Tenant $tenant) use ($caps, $meter, $current) {
            $custom = $caps->get($tenant->id);
            $planCap = $meter->defaultCap($tenant);
            $cap = is_array($custom) && isset($custom['monthly_tokens']) ? (int) $custom['monthly_tokens'] : $planCap;
            $tokens = (int) $tenant->getAttribute('tokens');

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'requests' => (int) $tenant->getAttribute('requests'),
                'failed' => (int) $tenant->getAttribute('failed'),
                'tokens' => $tokens,
                'cost' => round((float) $tenant->getAttribute('cost'), 4),
                'cap' => $cap,
                'default_cap' => $planCap,
                'custom_cap' => $custom !== null,
                'percent' => $current ? ($cap > 0 ? min(100, (int) floor($tokens * 100 / $cap)) : 100) : null,
            ];
        });

        $totals = $usage()
            ->selectRaw("count(*) as requests, coalesce(sum(total_tokens), 0) as tokens, coalesce(sum(cost), 0) as cost, sum(case when status = 'failed' then 1 else 0 end) as failed, count(distinct tenant_id) as businesses")
            ->first();

        $features = $usage()
            ->selectRaw('feature, count(*) as requests, coalesce(sum(total_tokens), 0) as tokens')
            ->groupBy('feature')
            ->get()
            ->map(fn (AIUsage $row) => [
                'feature' => $row->feature,
                'label' => config("ai.features.{$row->feature}.label", $row->feature),
                'requests' => (int) $row->getAttribute('requests'),
                'tokens' => (int) $row->getAttribute('tokens'),
            ])
            ->sortByDesc('tokens')->values()->all();

        return Inertia::render('admin/ai/Usage', [
            'tenants' => $tenants,
            'totals' => [
                'requests' => (int) $totals?->getAttribute('requests'),
                'tokens' => (int) $totals?->getAttribute('tokens'),
                'cost' => round((float) $totals?->getAttribute('cost'), 4),
                'failed' => (int) $totals?->getAttribute('failed'),
                'businesses' => (int) $totals?->getAttribute('businesses'),
            ],
            'features' => $features,
            'filters' => ['month' => $start->format('Y-m'), 'search' => $filters['search'] ?? ''],
            'current' => $current,
            'defaultCap' => $default,
            'provider' => [
                'name' => config('ai.providers.'.config('ai.provider').'.label', config('ai.provider')),
                'model' => config('ai.provider') === 'openrouter' ? config('ai.openrouter.model') : null,
                'configured' => app(config('ai.providers.'.config('ai.provider').'.class'))->configured(),
            ],
        ]);
    }

    public function updateLimit(Request $request, Tenant $tenant, AIUsageMeter $meter, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'monthly_tokens' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $tokens = isset($validated['monthly_tokens']) ? (int) $validated['monthly_tokens'] : null;
        $meter->setCap($tenant, $tokens);
        $audit->log('ai.limit_updated', $tenant, ['monthly_tokens' => $tokens], $tenant->id);

        return back()->with('success', $tokens === null
            ? __(':name now uses the default AI allowance.', ['name' => $tenant->name])
            : __('AI allowance for :name set to :tokens tokens a month.', ['name' => $tenant->name, 'tokens' => number_format($tokens)]));
    }
}
