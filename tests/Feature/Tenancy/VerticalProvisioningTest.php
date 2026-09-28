<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Business\Models\BusinessType;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Module\Actions\BackfillTenantModules;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\RBAC\Actions\ProvisionTenantRoles;
use App\Domain\RBAC\Models\Permission;
use App\Domain\RBAC\Models\Role;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteSection;
use Database\Seeders\TenantBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class VerticalProvisioningTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** @return array{engines: list<string>, modules: list<string>, sections: list<string>} */
    private function workspace(Tenant $tenant): array
    {
        return $this->context()->run($tenant, fn () => [
            'engines' => $this->context()->enabledEngines(),
            'modules' => $this->context()->enabledModules(),
            'sections' => WebsiteSection::query()->orderBy('sort_order')->pluck('type')->all(),
        ]);
    }

    public function test_a_coaching_centre_gets_the_education_engine_and_admission_pipeline(): void
    {
        $tenant = $this->createTenant('Bright Classes', 'coaching');
        $workspace = $this->workspace($tenant);

        $this->assertSame(['education'], $workspace['engines']);
        $this->assertContains('customers', $workspace['modules']);
        $this->assertContains('courses', $workspace['sections']);

        $this->inTenant($tenant, function () {
            $this->assertSame(['new', 'contacted', 'demo_scheduled', 'follow_up', 'converted', 'lost'], LeadStage::query()->ordered()->pluck('code')->all());
            $this->assertFalse(TenantSetting::query()->where('key', 'education')->exists(), 'Education settings use config defaults until saved.');
        });

        $this->assertTrue($this->roleCan($tenant, 'staff', 'students.attendance'));
        $this->assertFalse($this->roleCan($tenant, 'staff', 'fees.manage'));

        $this->actingAs($this->ownerOf($tenant))->get($this->appUrl('/dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.students.value', 0)
                ->where('metrics.admissions.value', 0));
    }

    public function test_a_cafe_gets_food_and_commerce_with_reservations_on_the_website(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $workspace = $this->workspace($tenant);

        $this->assertEqualsCanonicalizing(['food', 'commerce'], $workspace['engines']);
        $this->assertContains('offers', $workspace['modules']);
        $this->assertContains('reservation', $workspace['sections']);
        $this->assertContains('products', $workspace['sections']);

        $this->assertTrue($this->roleCan($tenant, 'staff', 'reservations.view'));

        $this->actingAs($this->ownerOf($tenant))->get($this->appUrl('/dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.reservations_today.value', 0)
                ->where('metrics.kitchen_queue.value', 0));

        $this->get($this->siteUrl('abc-cafe.autowave.test'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sections', fn ($sections) => collect($sections)->pluck('type')->contains('reservation')));
    }

    public function test_local_commerce_is_the_renamed_local_store_with_offers(): void
    {
        $this->assertSame('Local Commerce', BusinessType::latestActive('local_store')->name);
        $this->assertSame(1, BusinessType::query()->where('code', 'local_store')->count(), 'The rename updates the existing version in place.');

        $tenant = $this->createTenant('ABC Store', 'local_store');
        $workspace = $this->workspace($tenant);

        $this->assertSame(['commerce'], $workspace['engines']);
        $this->assertContains('offers', $workspace['modules']);
        $this->assertContains('inventory', $workspace['modules']);
    }

    public function test_the_backfill_switches_on_new_modules_once(): void
    {
        $store = $this->createTenant('ABC Store', 'local_store');
        $salon = $this->createTenant();
        $modules = app(ModuleManager::class);

        // A store created before coupons existed.
        $modules->disable($store, 'offers');
        TenantSetting::withoutTenantScope()->where('tenant_id', $store->id)->where('key', BackfillTenantModules::KEY)->update(['value' => ['ai']]);

        $this->seed(TenantBackfillSeeder::class);

        $this->assertTrue($modules->isEnabled($store, 'offers'));
        // Not offered to salons, so not switched on.
        $this->assertFalse($modules->isEnabled($salon, 'offers'));

        // An owner who switches it off afterwards keeps it off.
        $modules->disable($store, 'offers');
        $this->seed(TenantBackfillSeeder::class);
        $this->assertFalse($modules->isEnabled($store, 'offers'));
    }

    public function test_the_backfill_grants_the_new_permission_groups_to_existing_roles(): void
    {
        $tenant = $this->createTenant('Bright Classes', 'coaching');
        $groupPermissions = Permission::query()->whereIn('group', ['courses', 'students', 'fees'])->pluck('id');
        Role::withoutTenantScope()->where('tenant_id', $tenant->id)->get()
            ->each(fn (Role $role) => $role->permissions()->detach($groupPermissions));
        TenantSetting::withoutTenantScope()->where('tenant_id', $tenant->id)->where('key', ProvisionTenantRoles::BACKFILLED_GROUPS_KEY)->delete();

        $this->assertFalse($this->roleCan($tenant, 'staff', 'students.attendance'));

        $this->seed(TenantBackfillSeeder::class);

        $this->assertTrue($this->roleCan($tenant, 'staff', 'students.attendance'));
        $this->assertTrue($this->roleCan($tenant, 'manager', 'fees.manage'));
    }

    private function roleCan(Tenant $tenant, string $slug, string $permission): bool
    {
        return Role::withoutTenantScope()->where('tenant_id', $tenant->id)->where('slug', $slug)->sole()
            ->permissions()->where('key', $permission)->exists();
    }
}
