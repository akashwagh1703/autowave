<?php

namespace Database\Seeders;

use App\Domain\Automation\Actions\ProvisionAutomations;
use App\Domain\Lead\Actions\ProvisionCrm;
use App\Domain\RBAC\Actions\ProvisionTenantRoles;
use App\Domain\Service\Actions\ProvisionServiceCatalog;
use App\Domain\Tenant\Actions\BackfillTenantSettings;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Actions\ProvisionWebsite;
use Illuminate\Database\Seeder;

/**
 * Gives tenants created before a feature existed that feature's defaults (settings, website, CRM
 * pipeline, service categories, default automations, new permission groups). Idempotent; safe to
 * run on every deploy.
 */
class TenantBackfillSeeder extends Seeder
{
    /** Permission groups added after tenants already existed (config/rbac.php). */
    public const NEW_PERMISSION_GROUPS = ['services', 'resources', 'website'];

    public function run(
        BackfillTenantSettings $backfillSettings,
        ProvisionWebsite $provisionWebsite,
        ProvisionCrm $provisionCrm,
        ProvisionServiceCatalog $provisionServiceCatalog,
        ProvisionAutomations $provisionAutomations,
        ProvisionTenantRoles $provisionRoles,
    ): void {
        Tenant::query()->with('businessType')->orderBy('id')->each(function (Tenant $tenant) use ($backfillSettings, $provisionWebsite, $provisionCrm, $provisionServiceCatalog, $provisionAutomations, $provisionRoles) {
            $backfillSettings->handle($tenant);
            $provisionWebsite->ensureFor($tenant);
            $provisionCrm->ensureFor($tenant);
            $provisionServiceCatalog->ensureFor($tenant);
            $provisionAutomations->ensureFor($tenant);
            $provisionRoles->grantNewPermissionGroups($tenant, self::NEW_PERMISSION_GROUPS);
        });
    }
}
