<?php

namespace App\Domain\Domain\Actions;

use App\Domain\Domain\Enums\DomainStatus;
use App\Domain\Domain\Enums\DomainType;
use App\Domain\Domain\Enums\SslStatus;
use App\Domain\Domain\Models\Domain;
use App\Domain\Domain\Support\Hostname;
use App\Domain\Tenant\Models\Tenant;

/**
 * Gives a tenant its platform subdomain ({slug}.{root_domain}) as primary domain.
 * The platform owns the wildcard DNS and certificate, so it is active immediately.
 */
class AssignDefaultDomain
{
    public function handle(Tenant $tenant): Domain
    {
        return Domain::query()->firstOrCreate(
            ['domain' => Hostname::subdomainFor($tenant->slug)],
            [
                'tenant_id' => $tenant->getKey(),
                'type' => DomainType::Subdomain,
                'is_primary' => ! $tenant->domains()->where('is_primary', true)->exists(),
                'status' => DomainStatus::Active,
                'verified_at' => now(),
                'ssl_status' => SslStatus::Active,
            ],
        );
    }
}
