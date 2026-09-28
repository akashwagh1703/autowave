<?php

namespace App\Domain\Service\Actions;

use App\Domain\Business\Models\BusinessType;
use App\Domain\Service\Models\ServiceCategory;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use LogicException;

/**
 * Creates the business type's default service categories (`configuration.service_categories`)
 * for tenants with the service engine. Idempotent: only runs while the tenant has no categories.
 * Must run inside TenantContext::run() for the tenant.
 */
class ProvisionServiceCatalog
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Tenant $tenant, ?BusinessType $type): void
    {
        if ($this->context->id() !== $tenant->id) {
            throw new LogicException('ProvisionServiceCatalog must run inside the tenant context.');
        }

        if (! $this->context->hasEngine('service') || ServiceCategory::query()->exists()) {
            return;
        }

        foreach (array_values($type?->configuration['service_categories'] ?? []) as $index => $name) {
            ServiceCategory::query()->create(['name' => $name, 'sort_order' => ($index + 1) * 10]);
        }
    }

    /** Backfill for tenants created before the service engine existed. */
    public function ensureFor(Tenant $tenant): void
    {
        $this->context->run($tenant, fn (Tenant $tenant) => $this->handle($tenant, $tenant->businessType));
    }
}
