<?php

namespace App\Domain\Service\Actions;

use App\Domain\Booking\Models\BookingResource;
use App\Domain\Commerce\Models\Product;
use App\Domain\Service\Models\PackageItem;
use App\Domain\Service\Models\Service;
use App\Domain\Service\Models\ServiceCategory;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a service or package. Names are unique per tenant (case-insensitive, live services).
 * Category, resource and package item ids are resolved inside the current tenant.
 */
class SaveService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{
     *     name: string,
     *     description?: ?string,
     *     service_category_id?: ?int,
     *     duration_minutes: int,
     *     price?: numeric-string|float|int|null,
     *     is_active?: bool,
     *     is_package?: bool,
     *     resource_ids?: ?list<int>,
     *     included_service_ids?: ?list<int>,
     *     product_ids?: ?list<int>,
     * }  $data
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

        $isPackage = (bool) ($data['is_package'] ?? $service?->is_package ?? false);
        $includedServiceIds = array_key_exists('included_service_ids', $data)
            ? array_values(array_unique(array_map('intval', $data['included_service_ids'] ?? [])))
            : null;
        $productIds = array_key_exists('product_ids', $data)
            ? array_values(array_unique(array_map('intval', $data['product_ids'] ?? [])))
            : null;

        if ($isPackage && ($includedServiceIds !== null || $productIds !== null)) {
            $this->assertPackageItems($includedServiceIds ?? [], $productIds ?? [], $service?->id);
        }

        return DB::transaction(function () use ($data, $service, $actor, $categoryId, $resourceIds, $isPackage, $includedServiceIds, $productIds) {
            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'service_category_id' => $categoryId,
                'duration_minutes' => (int) $data['duration_minutes'],
                'price' => $data['price'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'is_package' => $isPackage,
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

            if ($isPackage && ($includedServiceIds !== null || $productIds !== null)) {
                $this->syncPackageItems($service, $includedServiceIds ?? [], $productIds ?? []);
            } elseif (! $isPackage) {
                PackageItem::query()->where('package_service_id', $service->id)->delete();
            }

            return $service->fresh(['category', 'resources', 'packageItems.includedService', 'packageItems.product']);
        });
    }

    /** @param  list<int>  $serviceIds @param  list<int>  $productIds */
    private function assertPackageItems(array $serviceIds, array $productIds, ?int $packageId): void
    {
        if ($serviceIds === [] && $productIds === []) {
            return;
        }

        if ($packageId && in_array($packageId, $serviceIds, true)) {
            throw ValidationException::withMessages(['included_service_ids' => __('A package cannot include itself.')]);
        }

        if ($serviceIds !== []) {
            $count = Service::query()->standalone()->whereKey($serviceIds)->count();
            if ($count !== count($serviceIds)) {
                throw ValidationException::withMessages(['included_service_ids' => __('Choose valid services that are not packages.')]);
            }
        }

        if ($productIds !== []) {
            if (! $this->context->hasEngine('commerce')) {
                throw ValidationException::withMessages(['product_ids' => __('Products are not available for this business.')]);
            }

            $count = Product::query()->whereKey($productIds)->count();
            if ($count !== count($productIds)) {
                throw ValidationException::withMessages(['product_ids' => __('Choose valid products.')]);
            }
        }
    }

    /** @param  list<int>  $serviceIds @param  list<int>  $productIds */
    private function syncPackageItems(Service $package, array $serviceIds, array $productIds): void
    {
        PackageItem::query()->where('package_service_id', $package->id)->delete();

        $sort = 0;

        foreach ($serviceIds as $serviceId) {
            PackageItem::query()->create([
                'tenant_id' => $package->tenant_id,
                'package_service_id' => $package->id,
                'included_service_id' => $serviceId,
                'sort_order' => $sort += 10,
            ]);
        }

        foreach ($productIds as $productId) {
            PackageItem::query()->create([
                'tenant_id' => $package->tenant_id,
                'package_service_id' => $package->id,
                'product_id' => $productId,
                'sort_order' => $sort += 10,
            ]);
        }
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
