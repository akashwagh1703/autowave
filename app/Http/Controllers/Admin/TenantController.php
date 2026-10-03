<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Domain\Services\DomainResolver;
use App\Domain\Files\Models\Attachment;
use App\Domain\Files\Support\StorageAllowance;
use App\Domain\Media\Models\Media;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
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
            ->withQueryString();

        $ids = $tenants->getCollection()->modelKeys();
        $used = fn (string $model) => $model::withoutTenantScope()->whereIn('tenant_id', $ids)->groupBy('tenant_id')->selectRaw('tenant_id, sum(size_bytes) as bytes')->pluck('bytes', 'tenant_id');
        $mediaBytes = $used(Media::class);
        $attachmentBytes = $used(Attachment::class);
        $caps = TenantSetting::withoutTenantScope()->whereIn('tenant_id', $ids)->where('key', StorageAllowance::QUOTA_KEY)->pluck('value', 'tenant_id');

        $tenants->through(fn (Tenant $tenant) => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'business_type' => $tenant->businessType?->name,
            'status' => $tenant->status->value,
            'is_internal' => $tenant->is_internal,
            'domain' => $tenant->primaryDomain?->domain,
            'members' => $tenant->memberships_count,
            'created_at' => $tenant->created_at?->toDateString(),
            'storage' => [
                'used_bytes' => (int) ($mediaBytes[$tenant->id] ?? 0) + (int) ($attachmentBytes[$tenant->id] ?? 0),
                'cap_mb' => is_array($caps[$tenant->id] ?? null) && isset($caps[$tenant->id]['mb']) ? (int) $caps[$tenant->id]['mb'] : (int) config('files.quota_mb'),
                'custom' => isset($caps[$tenant->id]),
            ],
        ]);

        return Inertia::render('admin/tenants/Index', [
            'tenants' => $tenants,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'defaultStorageMb' => (int) config('files.quota_mb'),
        ]);
    }

    public function updateStorageLimit(Request $request, Tenant $tenant, StorageAllowance $allowance): RedirectResponse
    {
        $validated = $request->validate([
            'mb' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $mb = isset($validated['mb']) ? (int) $validated['mb'] : null;
        $allowance->setCap($tenant, $mb);
        $this->audit->log('storage.limit_updated', $tenant, ['mb' => $mb], $tenant->id);

        return back()->with('success', $mb === null
            ? __(':name now uses the default storage allowance.', ['name' => $tenant->name])
            : __('Storage allowance for :name set to :mb MB.', ['name' => $tenant->name, 'mb' => number_format($mb)]));
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
