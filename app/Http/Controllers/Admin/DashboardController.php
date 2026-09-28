<?php

namespace App\Http\Controllers\Admin;

use App\Domain\AI\Models\AIUsage;
use App\Domain\AI\Support\AIUsageMeter;
use App\Domain\Business\Models\BusinessType;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'tenants' => Tenant::query()->count(),
                'active_tenants' => Tenant::query()->where('status', TenantStatus::Active)->count(),
                'suspended_tenants' => Tenant::query()->where('status', TenantStatus::Suspended)->count(),
                'users' => User::query()->count(),
            ],
            'ai' => (function () {
                $month = AIUsage::withoutTenantScope()->where('created_at', '>=', AIUsageMeter::monthStart())
                    ->selectRaw('count(*) as requests, coalesce(sum(total_tokens), 0) as tokens, coalesce(sum(cost), 0) as cost')
                    ->first();

                return [
                    'requests' => (int) $month?->getAttribute('requests'),
                    'tokens' => (int) $month?->getAttribute('tokens'),
                    'cost' => round((float) $month?->getAttribute('cost'), 4),
                ];
            })(),
            'businessTypes' => BusinessType::query()
                ->withCount('tenants')
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name', 'version', 'status', 'is_public'])
                ->map(fn (BusinessType $type) => [
                    'code' => $type->code,
                    'name' => $type->name,
                    'version' => $type->version,
                    'status' => $type->status->value,
                    'is_public' => $type->is_public,
                    'tenants_count' => $type->tenants_count,
                ]),
        ]);
    }
}
