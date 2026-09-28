<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Booking\Support\ResourceRates;
use App\Domain\Booking\Support\WeeklyHours;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a bookable resource with its services and weekly working hours.
 * New resources start with the tenant's default hours unless hours are given.
 * Changing hours never moves or cancels existing appointments.
 */
class SaveBookingResource
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BookingSettings $settings,
    ) {}

    /**
     * @param  array{name: string, description?: ?string, color?: ?string, is_active?: bool, tenant_user_id?: ?int, service_ids?: ?list<int>, working_hours?: ?list<array{weekday: int, starts_at: string, ends_at: string}>, hourly_rate?: numeric-string|float|null, rates?: ?list<array<string, mixed>>}  $data
     */
    public function handle(array $data, ?BookingResource $resource = null): BookingResource
    {
        $memberId = $data['tenant_user_id'] ?? null;

        if (array_key_exists('tenant_user_id', $data) && $memberId) {
            $this->ensureMemberIsLinkable((int) $memberId, $resource);
        }

        $serviceIds = null;

        if (isset($data['service_ids'])) {
            $serviceIds = array_values(array_unique(array_map('intval', $data['service_ids'])));

            if (Service::query()->whereKey($serviceIds)->count() !== count($serviceIds)) {
                throw ValidationException::withMessages(['service_ids' => __('Choose valid services.')]);
            }
        }

        $hours = isset($data['working_hours'])
            ? WeeklyHours::normalize($data['working_hours'])
            : ($resource ? null : WeeklyHours::normalize($this->settings->defaultHours()));

        $rates = array_key_exists('rates', $data) ? ResourceRates::normalize($data['rates'] ?? []) : null;

        return DB::transaction(function () use ($data, $resource, $memberId, $serviceIds, $hours, $rates) {
            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'color' => $data['color'] ?? $resource?->color ?? '#6366f1',
                'is_active' => $data['is_active'] ?? true,
            ];

            if (array_key_exists('hourly_rate', $data)) {
                $attributes['hourly_rate'] = $data['hourly_rate'] === null || $data['hourly_rate'] === '' ? null : number_format((float) $data['hourly_rate'], 2, '.', '');
            }

            if ($rates !== null) {
                $attributes['rates'] = $rates ?: null;
            }

            if (array_key_exists('tenant_user_id', $data)) {
                $attributes['tenant_user_id'] = $memberId ? (int) $memberId : null;
            }

            if ($resource) {
                $resource->update($attributes);
            } else {
                $resource = BookingResource::query()->create([
                    ...$attributes,
                    'sort_order' => (int) BookingResource::query()->max('sort_order') + 10,
                ]);
            }

            if ($serviceIds !== null) {
                $resource->services()->sync(array_fill_keys($serviceIds, ['tenant_id' => $resource->tenant_id]));
            }

            if ($hours !== null) {
                $resource->workingHours()->delete();
                $resource->workingHours()->createMany($hours);
                $resource->unsetRelation('workingHours');
            }

            return $resource;
        });
    }

    private function ensureMemberIsLinkable(int $memberId, ?BookingResource $resource): void
    {
        $isMember = TenantUser::query()
            ->where('tenant_id', $this->context->tenant()->id)
            ->where('status', MembershipStatus::Active)
            ->whereKey($memberId)
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages(['tenant_user_id' => __('Choose an active team member.')]);
        }

        $linked = BookingResource::query()
            ->where('tenant_user_id', $memberId)
            ->when($resource, fn ($query) => $query->whereKeyNot($resource->id))
            ->exists();

        if ($linked) {
            throw ValidationException::withMessages(['tenant_user_id' => __('This team member already has a calendar.')]);
        }
    }
}
