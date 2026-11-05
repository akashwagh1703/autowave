<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Module\Services\ModuleManager;
use App\Domain\Website\Models\WebsiteConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class UpdateTenantModulesTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_settings_lists_active_modules_with_dependency_metadata(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/settings'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/Settings')
            ->has('modules')
            ->where('canUpdate', true)
            ->where('modules.0.code', fn ($code) => is_string($code))
            ->has('modules.0.depends_on')
            ->has('modules.0.required')
            ->has('modules.0.recommended'));
    }

    public function test_owner_can_enable_a_module_skipped_at_onboarding(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe', options: [
            'modules' => ['crm', 'customers', 'messaging', 'offers', 'automation', 'website', 'ai'],
        ]);

        $this->assertFalse($this->inTenant($tenant, fn () => app(ModuleManager::class)->isEnabled($tenant, 'loyalty')));

        $this->actingAs($this->ownerOf($tenant));

        $enabled = app(ModuleManager::class)->enabledCodes($tenant);
        $this->put($this->appUrl('/settings/modules'), [
            'modules' => [...$enabled, 'loyalty'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(app(ModuleManager::class)->isEnabled($tenant, 'loyalty'));
        $this->assertTrue(app(ModuleManager::class)->isEnabled($tenant, 'customers'));
    }

    public function test_enabling_a_module_pulls_in_its_dependencies(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe', options: [
            'modules' => ['customers', 'messaging', 'offers', 'automation', 'website', 'ai'],
        ]);

        $this->assertFalse(app(ModuleManager::class)->isEnabled($tenant, 'crm'));
        $this->assertFalse(app(ModuleManager::class)->isEnabled($tenant, 'leads'));

        $this->actingAs($this->ownerOf($tenant));
        $enabled = app(ModuleManager::class)->enabledCodes($tenant);

        $this->put($this->appUrl('/settings/modules'), [
            'modules' => [...$enabled, 'leads'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(app(ModuleManager::class)->isEnabled($tenant, 'leads'));
        $this->assertTrue(app(ModuleManager::class)->isEnabled($tenant, 'customers'));
    }

    public function test_owner_can_disable_an_optional_module(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $this->assertTrue(app(ModuleManager::class)->isEnabled($tenant, 'loyalty'));

        $this->actingAs($this->ownerOf($tenant));
        $withoutLoyalty = array_values(array_filter(
            app(ModuleManager::class)->enabledCodes($tenant),
            fn (string $code) => $code !== 'loyalty',
        ));

        $this->put($this->appUrl('/settings/modules'), [
            'modules' => $withoutLoyalty,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse(app(ModuleManager::class)->isEnabled($tenant, 'loyalty'));
    }

    public function test_engine_required_modules_stay_on_even_if_omitted(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $this->actingAs($this->ownerOf($tenant));

        // Cafe engines (food + commerce) require customers (and more). Omitting them is ignored.
        $withoutCustomers = array_values(array_filter(
            app(ModuleManager::class)->enabledCodes($tenant),
            fn (string $code) => $code !== 'customers',
        ));

        $this->put($this->appUrl('/settings/modules'), [
            'modules' => $withoutCustomers,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(app(ModuleManager::class)->isEnabled($tenant, 'customers'));
    }

    public function test_draft_modules_cannot_be_enabled(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $this->actingAs($this->ownerOf($tenant));

        $this->put($this->appUrl('/settings/modules'), [
            'modules' => [...app(ModuleManager::class)->enabledCodes($tenant), 'forms'],
        ])->assertSessionHasErrors('modules');
    }

    public function test_manager_cannot_change_modules(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $manager = User::factory()->create();
        $this->addMember($tenant, $manager, 'manager');

        $this->actingAs($manager)
            ->put($this->appUrl('/settings/modules'), ['modules' => ['customers']])
            ->assertForbidden();
    }

    public function test_enabling_website_provisions_defaults_when_missing(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe', options: [
            'modules' => ['crm', 'customers', 'messaging', 'offers', 'automation', 'ai', 'loyalty'],
        ]);

        $this->inTenant($tenant, function () {
            WebsiteConfig::query()->delete();
        });

        $this->assertFalse(app(ModuleManager::class)->isEnabled($tenant, 'website'));
        $this->assertNull($this->inTenant($tenant, fn () => WebsiteConfig::query()->first()));

        $this->actingAs($this->ownerOf($tenant));
        $enabled = app(ModuleManager::class)->enabledCodes($tenant);

        $this->put($this->appUrl('/settings/modules'), [
            'modules' => [...$enabled, 'website'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(app(ModuleManager::class)->isEnabled($tenant, 'website'));
        $this->assertNotNull($this->inTenant($tenant, fn () => WebsiteConfig::query()->first()));
    }
}
