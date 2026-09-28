<?php

namespace App\Http\Presenters;

use App\Domain\Booking\Models\BookingResource;
use App\Domain\Service\Models\Service;
use App\Domain\Service\Models\ServiceCategory;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;

/**
 * Option lists for service and booking forms in the current tenant.
 */
class BookingOptions
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return list<array<string, mixed>> active services, or [] when the service engine is off */
    public function services(?int $includeId = null): array
    {
        if (! $this->context->hasEngine('service')) {
            return [];
        }

        return Service::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($includeId, fn ($q) => $q->orWhere('id', $includeId)))
            ->with(['category', 'resources:id'])
            ->ordered()
            ->get()
            ->map(fn (Service $service) => BookingPresenter::service($service))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return ServiceCategory::query()
            ->ordered()
            ->get()
            ->map(fn (ServiceCategory $category) => BookingPresenter::category($category))
            ->all();
    }

    /** @return list<array<string, mixed>> active resources (plus one inactive id still in use) */
    public function resources(?int $includeId = null): array
    {
        return BookingResource::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($includeId, fn ($q) => $q->orWhere('id', $includeId)))
            ->with('services:id')
            ->ordered()
            ->get()
            ->map(fn (BookingResource $resource) => BookingPresenter::resource($resource))
            ->all();
    }

    /**
     * Active team members that can be linked to a resource, with the resource they already have.
     *
     * @return list<array{id: int, name: ?string, email: ?string, resource_id: ?int}>
     */
    public function members(): array
    {
        $linked = BookingResource::query()->whereNotNull('tenant_user_id')->pluck('id', 'tenant_user_id');

        return TenantUser::query()
            ->where('tenant_id', $this->context->tenant()->id)
            ->where('status', MembershipStatus::Active)
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get()
            ->map(fn (TenantUser $member) => [
                'id' => $member->id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'resource_id' => $linked[$member->id] ?? null,
            ])
            ->all();
    }
}
