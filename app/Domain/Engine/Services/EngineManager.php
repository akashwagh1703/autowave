<?php

namespace App\Domain\Engine\Services;

use App\Domain\Engine\Models\Engine;
use App\Domain\Engine\Models\TenantEngine;
use App\Domain\Module\Exceptions\CatalogItemUnavailable;
use App\Domain\Module\Exceptions\ModuleDependencyException;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Tenant\Models\Tenant;

/**
 * Enables and disables engines for a tenant (ADR-005). An engine can only be
 * enabled when the modules it requires are enabled.
 */
class EngineManager
{
    public function __construct(private readonly ModuleManager $modules) {}

    /** @return list<string> */
    public function enabledCodes(Tenant $tenant): array
    {
        return TenantEngine::query()
            ->join('engines', 'engines.id', '=', 'tenant_engines.engine_id')
            ->where('tenant_engines.tenant_id', $tenant->getKey())
            ->where('tenant_engines.enabled', true)
            ->orderBy('engines.sort_order')
            ->pluck('engines.code')
            ->all();
    }

    /**
     * @throws ModuleDependencyException
     * @throws CatalogItemUnavailable
     */
    public function enable(Tenant $tenant, string $code): TenantEngine
    {
        $engine = Engine::query()->where('code', $code)->first();

        if (! $engine || ! $engine->isActive()) {
            throw CatalogItemUnavailable::engine($code);
        }

        $missing = array_values(array_diff($engine->requiredModuleCodes(), $this->modules->enabledCodes($tenant)));

        if ($missing !== []) {
            throw ModuleDependencyException::engineRequires($code, $missing);
        }

        return TenantEngine::query()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'engine_id' => $engine->getKey()],
            ['enabled' => true],
        );
    }

    public function disable(Tenant $tenant, string $code): void
    {
        $engine = Engine::query()->where('code', $code)->firstOrFail();

        TenantEngine::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('engine_id', $engine->getKey())
            ->update(['enabled' => false]);
    }
}
