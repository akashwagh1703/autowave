<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Support\BookingSettings;
use App\Domain\RBAC\Actions\ProvisionTenantRoles;
use App\Domain\RBAC\Models\Permission;
use App\Domain\RBAC\Models\Role;
use App\Domain\Service\Actions\ProvisionServiceCatalog;
use App\Domain\Service\Models\ServiceCategory;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use Database\Seeders\TenantBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingProvisioningTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_new_tenants_get_service_categories_for_their_business_type(): void
    {
        $salon = $this->createTenant();
        $clinic = $this->createTenant('City Clinic', 'clinic');
        $turf = $this->createTenant('Green Turf', 'turf');

        $this->assertSame(config('catalog.business_types.beauty_salon.configuration.service_categories'), $this->categories($salon));
        $this->assertSame(config('catalog.business_types.clinic.configuration.service_categories'), $this->categories($clinic));
        // No service engine, no categories.
        $this->assertSame([], $this->categories($turf));

        $this->inTenant($salon, fn () => $this->assertFalse(TenantSetting::query()->where('key', 'service_categories')->exists()));
    }

    public function test_business_type_booking_settings_become_tenant_settings(): void
    {
        $turf = $this->createTenant('Green Turf', 'turf');

        $this->inTenant($turf, function () {
            $this->assertSame(60, TenantSetting::query()->where('key', BookingSettings::KEY)->sole()->value['slot_interval']);
            $this->assertSame('Turf', TenantSetting::query()->where('key', BookingSettings::LABEL_KEY)->sole()->value);
        });
    }

    public function test_service_catalog_provisioning_is_idempotent(): void
    {
        $tenant = $this->createTenant();
        $this->inTenant($tenant, fn () => ServiceCategory::query()->where('name', 'Hair')->update(['name' => 'Hair & Styling']));

        app(ProvisionServiceCatalog::class)->ensureFor($tenant);

        $this->assertSame(5, count($this->categories($tenant)));
        $this->assertContains('Hair & Styling', $this->categories($tenant));
    }

    public function test_provisioning_outside_the_tenant_context_is_refused(): void
    {
        $tenant = $this->createTenant();

        $this->expectException(LogicException::class);
        app(ProvisionServiceCatalog::class)->handle($tenant, $tenant->businessType);
    }

    public function test_the_backfill_brings_older_tenants_up_to_date(): void
    {
        $tenant = $this->createTenant('Green Turf', 'turf');
        $salon = $this->createTenant();

        // Simulate tenants created before Phase 4.
        TenantSetting::withoutTenantScope()->whereIn('key', [BookingSettings::KEY, BookingSettings::LABEL_KEY, ProvisionTenantRoles::BACKFILLED_GROUPS_KEY])->delete();
        $this->inTenant($salon, fn () => ServiceCategory::query()->delete());
        $groupPermissions = Permission::query()->whereIn('group', TenantBackfillSeeder::NEW_PERMISSION_GROUPS)->pluck('id');
        foreach ([$tenant, $salon] as $each) {
            Role::withoutTenantScope()->where('tenant_id', $each->id)->get()->each(fn (Role $role) => $role->permissions()->detach($groupPermissions));
        }

        $this->assertFalse($this->roleCan($salon, 'receptionist', 'services.view'));

        $this->seed(TenantBackfillSeeder::class);

        $this->inTenant($tenant, fn () => $this->assertSame(60, app(BookingSettings::class)->slotInterval()));
        $this->inTenant($tenant, fn () => $this->assertSame('Turf', app(BookingSettings::class)->resourceLabel()));
        $this->assertCount(5, $this->categories($salon));
        $this->assertTrue($this->roleCan($salon, 'receptionist', 'services.view'));
        $this->assertTrue($this->roleCan($salon, 'manager', 'resources.manage'));
        $this->assertFalse($this->roleCan($salon, 'receptionist', 'services.manage'));
        $this->assertTrue($this->roleCan($tenant, 'staff', 'resources.view'));

        // A permission the tenant removes later is not granted back by the next deploy.
        $this->revoke($salon, 'receptionist', 'services.view');
        $this->seed(TenantBackfillSeeder::class);
        $this->assertFalse($this->roleCan($salon, 'receptionist', 'services.view'));
    }

    public function test_the_backfill_leaves_edited_settings_alone(): void
    {
        $tenant = $this->createTenant('Green Turf', 'turf');
        $this->inTenant($tenant, fn () => app(BookingSettings::class)->update(['slot_interval' => 30], 'Court'));

        $this->seed(TenantBackfillSeeder::class);

        $this->inTenant($tenant, function () {
            $this->assertSame(30, app(BookingSettings::class)->slotInterval());
            $this->assertSame('Court', app(BookingSettings::class)->resourceLabel());
        });
    }

    /** @return list<string> */
    private function categories(Tenant $tenant): array
    {
        return $this->inTenant($tenant, fn () => ServiceCategory::query()->ordered()->pluck('name')->all());
    }

    private function roleCan(Tenant $tenant, string $slug, string $permission): bool
    {
        return Role::withoutTenantScope()->where('tenant_id', $tenant->id)->where('slug', $slug)->sole()
            ->permissions()->where('key', $permission)->exists();
    }

    private function revoke(Tenant $tenant, string $slug, string $permission): void
    {
        Role::withoutTenantScope()->where('tenant_id', $tenant->id)->where('slug', $slug)->sole()
            ->permissions()->detach(Permission::query()->where('key', $permission)->value('id'));
    }
}
