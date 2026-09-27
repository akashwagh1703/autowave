<?php

namespace App\Domain\Module\Services;

use App\Domain\Engine\Models\Engine;
use App\Domain\Engine\Models\TenantEngine;
use App\Domain\Module\Exceptions\CatalogItemUnavailable;
use App\Domain\Module\Exceptions\ModuleDependencyException;
use App\Domain\Module\Models\Module;
use App\Domain\Module\Models\TenantModule;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Enables and disables modules for a tenant while enforcing dependencies (ADR-006).
 */
class ModuleManager
{
    /** @return list<string> */
    public function enabledCodes(Tenant $tenant): array
    {
        return TenantModule::query()
            ->join('modules', 'modules.id', '=', 'tenant_modules.module_id')
            ->where('tenant_modules.tenant_id', $tenant->getKey())
            ->where('tenant_modules.enabled', true)
            ->orderBy('modules.sort_order')
            ->pluck('modules.code')
            ->all();
    }

    public function isEnabled(Tenant $tenant, string $code): bool
    {
        return in_array($code, $this->enabledCodes($tenant), true);
    }

    /**
     * Enable one module. All of its dependencies must already be enabled.
     *
     * @throws ModuleDependencyException
     * @throws CatalogItemUnavailable
     */
    public function enable(Tenant $tenant, string $code): TenantModule
    {
        $module = $this->findActive($code);
        $missing = array_values(array_diff(
            $module->dependencies->pluck('code')->all(),
            $this->enabledCodes($tenant),
        ));

        if ($missing !== []) {
            throw ModuleDependencyException::missing($code, $missing);
        }

        $tenantModule = TenantModule::query()->firstOrNew([
            'tenant_id' => $tenant->getKey(),
            'module_id' => $module->getKey(),
        ]);

        $tenantModule->enabled = true;
        $tenantModule->version ??= $module->version;
        $tenantModule->save();

        return $tenantModule;
    }

    /**
     * Enable modules together with everything they depend on, in dependency order.
     *
     * @param  list<string>  $codes
     * @return list<string> codes that were enabled by this call
     */
    public function enableWithDependencies(Tenant $tenant, array $codes): array
    {
        return DB::transaction(function () use ($tenant, $codes) {
            $alreadyEnabled = $this->enabledCodes($tenant);
            $enabled = [];

            foreach ($this->resolveOrder($codes) as $code) {
                if (! in_array($code, $alreadyEnabled, true)) {
                    $this->enable($tenant, $code);
                    $enabled[] = $code;
                }
            }

            return $enabled;
        });
    }

    /**
     * @throws ModuleDependencyException when an enabled module or engine still needs this module
     */
    public function disable(Tenant $tenant, string $code): void
    {
        $module = Module::query()->where('code', $code)->firstOrFail();
        $enabled = $this->enabledCodes($tenant);

        $dependents = array_values(array_intersect($module->dependents->pluck('code')->all(), $enabled));

        $engineDependents = Engine::query()
            ->whereIn('id', TenantEngine::query()->where('tenant_id', $tenant->getKey())->where('enabled', true)->select('engine_id'))
            ->get()
            ->filter(fn (Engine $engine) => in_array($code, $engine->requiredModuleCodes(), true))
            ->map(fn (Engine $engine) => "{$engine->code} (engine)")
            ->all();

        if ($dependents !== [] || $engineDependents !== []) {
            throw ModuleDependencyException::requiredBy($code, [...$dependents, ...array_values($engineDependents)]);
        }

        TenantModule::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('module_id', $module->getKey())
            ->update(['enabled' => false]);
    }

    private function findActive(string $code): Module
    {
        $module = Module::query()->with('dependencies:id,code')->where('code', $code)->first();

        if (! $module || ! $module->isActive()) {
            throw CatalogItemUnavailable::module($code);
        }

        return $module;
    }

    /**
     * Depth-first topological order: dependencies before dependents.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function resolveOrder(array $codes): array
    {
        $modules = Module::query()->with('dependencies:id,code')->get()->keyBy('code');
        $ordered = [];
        $visiting = [];

        $visit = function (string $code, array $path) use (&$visit, &$ordered, &$visiting, $modules): void {
            if (in_array($code, $ordered, true)) {
                return;
            }

            if (isset($visiting[$code])) {
                throw ModuleDependencyException::circular([...$path, $code]);
            }

            $module = $modules->get($code);
            if (! $module || ! $module->isActive()) {
                throw CatalogItemUnavailable::module($code);
            }

            $visiting[$code] = true;
            foreach ($module->dependencies as $dependency) {
                $visit($dependency->code, [...$path, $code]);
            }
            unset($visiting[$code]);

            $ordered[] = $code;
        };

        foreach (array_unique($codes) as $code) {
            $visit($code, []);
        }

        return $ordered;
    }
}
