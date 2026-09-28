<?php

namespace App\Domain\Lead\Actions;

use App\Domain\Business\Models\BusinessType;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use LogicException;

/**
 * Copies the default pipeline stages and lead sources into a tenant (config/crm.php, or the
 * business type's `configuration.lead_stages` / `lead_sources`). Idempotent: each list is only
 * seeded while the tenant has none, so tenant edits are never overwritten.
 * Must run inside TenantContext::run() for the tenant.
 */
class ProvisionCrm
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Tenant $tenant, ?BusinessType $type): void
    {
        if ($this->context->id() !== $tenant->id) {
            throw new LogicException('ProvisionCrm must run inside the tenant context.');
        }

        $configuration = $type?->configuration ?? [];

        if (! LeadStage::query()->exists()) {
            foreach (array_values($configuration['lead_stages'] ?? config('crm.lead_stages')) as $index => $stage) {
                LeadStage::query()->create([
                    'code' => $stage['code'],
                    'name' => $stage['name'],
                    'color' => $stage['color'] ?? '#6366f1',
                    'outcome' => $stage['outcome'] ?? 'open',
                    'sort_order' => ($index + 1) * 10,
                ]);
            }
        }

        if (! LeadSource::query()->exists()) {
            foreach (array_values($configuration['lead_sources'] ?? config('crm.lead_sources')) as $index => $source) {
                LeadSource::query()->create([
                    'code' => $source['code'],
                    'name' => $source['name'],
                    'sort_order' => ($index + 1) * 10,
                ]);
            }
        }
    }

    /** Backfill for tenants created before the CRM existed. */
    public function ensureFor(Tenant $tenant): void
    {
        $this->context->run($tenant, fn (Tenant $tenant) => $this->handle($tenant, $tenant->businessType));
    }
}
