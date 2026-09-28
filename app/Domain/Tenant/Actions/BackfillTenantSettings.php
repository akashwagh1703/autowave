<?php

namespace App\Domain\Tenant\Actions;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;

/**
 * Adds business type configuration keys introduced after the tenant was created (for example
 * `booking`) to its settings. Existing settings are never touched.
 */
class BackfillTenantSettings
{
    /** @return list<string> keys that were added */
    public function handle(Tenant $tenant): array
    {
        $configuration = array_diff_key($tenant->businessType?->configuration ?? [], array_flip(CreateTenant::CATALOGUE_ONLY_KEYS));

        if ($configuration === []) {
            return [];
        }

        $existing = TenantSetting::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('key')->all();
        $missing = array_diff_key($configuration, array_flip($existing));

        foreach ($missing as $key => $value) {
            TenantSetting::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'key' => $key, 'value' => $value]);
        }

        return array_keys($missing);
    }
}
