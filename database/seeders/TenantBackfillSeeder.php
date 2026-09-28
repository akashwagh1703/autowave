<?php

namespace Database\Seeders;

use App\Domain\Lead\Actions\ProvisionCrm;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Actions\ProvisionWebsite;
use Illuminate\Database\Seeder;

/**
 * Gives tenants created before a feature existed that feature's defaults (website, CRM
 * pipeline). Idempotent; safe to run on every deploy.
 */
class TenantBackfillSeeder extends Seeder
{
    public function run(ProvisionWebsite $provisionWebsite, ProvisionCrm $provisionCrm): void
    {
        Tenant::query()->with('businessType')->orderBy('id')->each(function (Tenant $tenant) use ($provisionWebsite, $provisionCrm) {
            $provisionWebsite->ensureFor($tenant);
            $provisionCrm->ensureFor($tenant);
        });
    }
}
