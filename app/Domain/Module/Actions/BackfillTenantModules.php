<?php

namespace App\Domain\Module\Actions;

use App\Domain\Module\Models\Module;
use App\Domain\Module\Models\TenantModule;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;

/**
 * Turns on modules added to the catalogue after a tenant was created, once per module: a module the
 * owner later switches off stays off (tenant setting `modules_backfilled` remembers what was done).
 *
 * Exception: if a code was marked backfilled but the tenant never got a `tenant_modules` row (common
 * when CreateTenant used to mark draft catalogue keys as done), it is still offered once when the
 * business type includes it — so flipping a module from draft → active can reach existing businesses.
 */
class BackfillTenantModules
{
    public const KEY = 'modules_backfilled';

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  list<string>  $codes
     * @return list<string> the modules enabled by this call
     */
    public function handle(Tenant $tenant, array $codes): array
    {
        $done = TenantSetting::withoutTenantScope()->where('tenant_id', $tenant->getKey())->where('key', self::KEY)->first()?->value ?? [];
        $offered = $tenant->businessType?->modules()->pluck('modules.code')->all() ?? [];

        $pending = array_values(array_diff($codes, $done));
        $ghosts = $this->neverProvisioned($tenant, array_values(array_intersect($codes, $offered, $done)));
        $candidates = array_values(array_unique([...$pending, ...$ghosts]));

        if ($candidates === []) {
            return [];
        }

        $enabled = $this->modules->enableWithDependencies($tenant, array_values(array_intersect($candidates, $offered)));

        TenantSetting::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'key' => self::KEY],
            ['value' => array_values(array_unique([...$done, ...$candidates]))],
        );

        return $enabled;
    }

    /**
     * Codes marked backfilled that still have no tenant_modules row (never enabled or disabled).
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function neverProvisioned(Tenant $tenant, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $moduleIds = Module::query()->whereIn('code', $codes)->pluck('id', 'code');
        $existingIds = TenantModule::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('module_id', $moduleIds->values())
            ->pluck('module_id')
            ->all();

        return $moduleIds
            ->filter(fn (int $id) => ! in_array($id, $existingIds, true))
            ->keys()
            ->values()
            ->all();
    }
}
