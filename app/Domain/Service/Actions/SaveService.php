<?php

namespace App\Domain\Service\Actions;

use App\Domain\Booking\Models\BookingResource;
use App\Domain\Service\Models\Service;
use App\Domain\Service\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a service. Names are unique per tenant (case-insensitive, live services).
 * Category and resource ids are resolved inside the current tenant, so foreign ids are rejected.
 */
class SaveService
{
    /**
     * @param  array{name: string, description?: ?string, service_category_id?: ?int, duration_minutes: int, price?: numeric-string|float|int|null, is_active?: bool, resource_ids?: ?list<int>}  $data
     */
    public function handle(array $data, ?Service $service = null, ?User $actor = null): Service
    {
        $this->ensureNameIsFree($data['name'], $service?->id);

        $categoryId = $data['service_category_id'] ?? null;

        if ($categoryId && ! ServiceCategory::query()->whereKey($categoryId)->exists()) {
            throw ValidationException::withMessages(['service_category_id' => __('Choose a valid category.')]);
        }

        $resourceIds = null;

        if (array_key_exists('resource_ids', $data) && $data['resource_ids'] !== null) {
            $resourceIds = array_values(array_unique(array_map('intval', $data['resource_ids'])));

            if (BookingResource::query()->whereKey($resourceIds)->count() !== count($resourceIds)) {
                throw ValidationException::withMessages(['resource_ids' => __('Choose valid team members or resources.')]);
            }
        }

        return DB::transaction(function () use ($data, $service, $actor, $categoryId, $resourceIds) {
            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'service_category_id' => $categoryId,
                'duration_minutes' => (int) $data['duration_minutes'],
                'price' => $data['price'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ];

            if ($service) {
                $service->update($attributes);
            } else {
                $service = Service::query()->create([
                    ...$attributes,
                    'sort_order' => (int) Service::query()->max('sort_order') + 10,
                    'created_by_user_id' => $actor?->id,
                ]);
            }

            if ($resourceIds !== null) {
                $service->resources()->sync(array_fill_keys($resourceIds, ['tenant_id' => $service->tenant_id]));
            }

            return $service;
        });
    }

    private function ensureNameIsFree(string $name, ?int $ignoreId): void
    {
        $taken = Service::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('A service with this name already exists.')]);
        }
    }
}
