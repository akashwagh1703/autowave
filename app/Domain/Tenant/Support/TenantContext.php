<?php

namespace App\Domain\Tenant\Support;

use App\Domain\Engine\Services\EngineManager;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Tenant\Exceptions\MissingTenantContext;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Context;

/**
 * The single source of "which tenant is this request/job for" (ADR-002).
 *
 * Registered as a scoped singleton: it is reset for every request and queued job.
 * Set only by trusted server-side code (domain resolution, membership middleware,
 * job middleware) — never from client input.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /** @var array<string, list<string>> */
    private array $memo = [];

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->memo = [];

        Context::add('tenant_id', $tenant->getKey());
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->memo = [];

        Context::forget('tenant_id');
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw MissingTenantContext::make();
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Run a callback as the given tenant, restoring the previous context afterwards.
     *
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback($tenant);
        } finally {
            $previous ? $this->set($previous) : $this->forget();
        }
    }

    /** @return list<string> */
    public function enabledModules(): array
    {
        return $this->memo['modules'] ??= app(ModuleManager::class)->enabledCodes($this->tenant());
    }

    /** @return list<string> */
    public function enabledEngines(): array
    {
        return $this->memo['engines'] ??= app(EngineManager::class)->enabledCodes($this->tenant());
    }

    public function hasModule(string $code): bool
    {
        return in_array($code, $this->enabledModules(), true);
    }

    public function hasEngine(string $code): bool
    {
        return in_array($code, $this->enabledEngines(), true);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $setting = $this->tenant()->settings()->where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }
}
