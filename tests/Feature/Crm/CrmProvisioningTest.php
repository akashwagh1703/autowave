<?php

namespace Tests\Feature\Crm;

use App\Domain\Lead\Actions\ProvisionCrm;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\TenantSetting;
use Database\Seeders\TenantBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CrmProvisioningTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_new_tenants_get_the_default_pipeline_and_sources(): void
    {
        $tenant = $this->createTenant();

        $this->inTenant($tenant, function () {
            $this->assertSame(array_column(config('crm.lead_stages'), 'code'), LeadStage::query()->ordered()->pluck('code')->all());
            $this->assertSame(array_column(config('crm.lead_sources'), 'code'), LeadSource::query()->ordered()->pluck('code')->all());
        });
    }

    public function test_a_business_type_can_override_the_stages(): void
    {
        $tenant = $this->createTenant('Bright Minds', 'coaching');

        $this->inTenant($tenant, function () {
            $this->assertSame(
                array_column(config('catalog.business_types.coaching.configuration.lead_stages'), 'name'),
                LeadStage::query()->ordered()->pluck('name')->all(),
            );
            $this->assertSame('Admitted', LeadStage::query()->where('outcome', 'won')->value('name'));

            // Catalogue-only keys never become tenant settings.
            $this->assertFalse(TenantSetting::query()->whereIn('key', ['lead_stages', 'lead_sources'])->exists());
        });
    }

    public function test_provisioning_is_idempotent_and_backfills_older_tenants(): void
    {
        $tenant = $this->createTenant();
        $this->inTenant($tenant, fn () => LeadStage::query()->where('code', 'new')->update(['name' => 'Custom name']));

        app(ProvisionCrm::class)->ensureFor($tenant);
        $this->inTenant($tenant, fn () => $this->assertSame('Custom name', LeadStage::query()->where('code', 'new')->value('name')));

        // A tenant created before the CRM existed.
        $this->inTenant($tenant, function () {
            LeadStage::query()->delete();
            LeadSource::query()->delete();
        });

        $this->seed(TenantBackfillSeeder::class);

        $this->inTenant($tenant, function () {
            $this->assertSame(6, LeadStage::query()->count());
            $this->assertSame(count(config('crm.lead_sources')), LeadSource::query()->count());
        });
    }

    public function test_provisioning_outside_the_tenant_context_is_refused(): void
    {
        $tenant = $this->createTenant();

        $this->expectException(LogicException::class);
        app(ProvisionCrm::class)->handle($tenant, $tenant->businessType);
    }
}
