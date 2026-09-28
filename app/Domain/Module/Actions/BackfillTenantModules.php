<?php

namespace App\Domain\Module\Actions;

use App\Domain\Module\Services\ModuleManager;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;

/**
 * Turns on modules added to the catalogue after a tenant was created, once per module: a module the
 * owner later switches off stays off (tenant setting `modules_backfilled` remembers what was done).
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
        $pending = array_values(array_diff($codes, $done));

        if ($pending === []) {
            return [];
        }

        $offered = $tenant->businessType?->modules()->pluck('modules.code')->all() ?? [];
        $enabled = $this->modules->enableWithDependencies($tenant, array_values(array_intersect($pending, $offered)));

        TenantSetting::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'key' => self::KEY],
            ['value' => array_values(array_unique([...$done, ...$pending]))],
        );

        return $enabled;
    }
}
