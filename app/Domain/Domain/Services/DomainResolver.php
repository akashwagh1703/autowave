<?php

namespace App\Domain\Domain\Services;

use App\Domain\Domain\Enums\DomainStatus;
use App\Domain\Domain\Models\Domain;
use App\Domain\Domain\Support\Hostname;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Maps a request host to a tenant (ADR-007).
 *
 * Only active domains of active tenants resolve. Lookups (including misses) are
 * cached; Domain model events and tenant status changes flush the entry.
 */
class DomainResolver
{
    private const MISS = 0;

    public function resolve(?string $host): ?Tenant
    {
        $host = Hostname::normalize($host);

        if ($host === '' || Hostname::isPlatformHost($host)) {
            return null;
        }

        $tenantId = Cache::remember(
            $this->cacheKey($host),
            config('autowave.domain_cache_ttl'),
            fn () => $this->lookup($host) ?? self::MISS,
        );

        if ($tenantId === self::MISS) {
            return null;
        }

        $tenant = Tenant::query()->find($tenantId);

        return $tenant?->isActive() ? $tenant : null;
    }

    public function forget(?string $host): void
    {
        $host = Hostname::normalize($host);

        if ($host !== '') {
            Cache::forget($this->cacheKey($host));
        }
    }

    public function forgetTenant(Tenant $tenant): void
    {
        Domain::query()->where('tenant_id', $tenant->getKey())->pluck('domain')->each($this->forget(...));
    }

    private function lookup(string $host): ?int
    {
        return Domain::query()
            ->join('tenants', 'tenants.id', '=', 'domains.tenant_id')
            ->where('domains.domain', $host)
            ->where('domains.status', DomainStatus::Active)
            ->where('tenants.status', TenantStatus::Active)
            ->value('domains.tenant_id');
    }

    private function cacheKey(string $host): string
    {
        return 'domain-resolver:'.$host;
    }
}
