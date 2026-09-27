<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Domain\Services\DomainResolver;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DomainResolver $domains,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
        ]);

        $tenants = Tenant::query()
            ->with(['businessType:id,name', 'primaryDomain'])
            ->withCount('memberships')
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('slug', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'business_type' => $tenant->businessType?->name,
                'status' => $tenant->status->value,
                'is_internal' => $tenant->is_internal,
                'domain' => $tenant->primaryDomain?->domain,
                'members' => $tenant->memberships_count,
                'created_at' => $tenant->created_at?->toDateString(),
            ]);

        return Inertia::render('admin/tenants/Index', [
            'tenants' => $tenants,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    public function suspend(Tenant $tenant): RedirectResponse
    {
        if ($tenant->is_internal) {
            return back()->with('error', __('The internal AutoWave tenant cannot be suspended.'));
        }

        return $this->changeStatus($tenant, TenantStatus::Suspended, 'tenant.suspended');
    }

    public function activate(Tenant $tenant): RedirectResponse
    {
        return $this->changeStatus($tenant, TenantStatus::Active, 'tenant.activated');
    }

    private function changeStatus(Tenant $tenant, TenantStatus $status, string $action): RedirectResponse
    {
        $previous = $tenant->status;

        if ($previous !== $status) {
            $tenant->update(['status' => $status]);
            $this->domains->forgetTenant($tenant);
            $this->audit->log($action, $tenant, ['from' => $previous->value, 'to' => $status->value], $tenant->id);
        }

        return back()->with('success', __(':name is now :status.', ['name' => $tenant->name, 'status' => $status->value]));
    }
}
