<?php

namespace App\Http\Controllers\Admin;

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
