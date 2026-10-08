<?php

namespace Tests\Feature\Catalog;

use App\Domain\Engine\Services\EngineManager;
use App\Domain\Module\Exceptions\CatalogItemUnavailable;
use App\Domain\Module\Exceptions\ModuleDependencyException;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleManagerTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $modules;

    private EngineManager $engines;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modules = app(ModuleManager::class);
        $this->engines = app(EngineManager::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_a_module_cannot_be_enabled_before_its_dependencies(): void
    {
        $this->expectException(ModuleDependencyException::class);

        $this->modules->enable($this->tenant, 'leads');
    }

    public function test_dependencies_are_enabled_in_order(): void
    {
        $enabled = $this->modules->enableWithDependencies($this->tenant, ['leads']);

        $this->assertSame(['customers', 'leads'], $enabled);
        $this->assertTrue($this->modules->isEnabled($this->tenant, 'leads'));
    }

    public function test_draft_modules_cannot_be_enabled(): void
    {
        $this->expectException(CatalogItemUnavailable::class);

        $this->modules->enableWithDependencies($this->tenant, ['forms']);
    }

    public function test_enabling_is_idempotent(): void
    {
        $this->modules->enableWithDependencies($this->tenant, ['crm']);

        $this->assertSame([], $this->modules->enableWithDependencies($this->tenant, ['crm', 'customers']));
    }

    public function test_a_module_cannot_be_disabled_while_another_depends_on_it(): void
    {
        $this->modules->enableWithDependencies($this->tenant, ['leads']);

        $this->expectException(ModuleDependencyException::class);

        $this->modules->disable($this->tenant, 'customers');
    }

    public function test_a_module_cannot_be_disabled_while_an_engine_requires_it(): void
    {
        $this->modules->enableWithDependencies($this->tenant, ['customers']);
        $this->engines->enable($this->tenant, 'booking');

        $this->expectException(ModuleDependencyException::class);

        $this->modules->disable($this->tenant, 'customers');
    }

    public function test_disabling_a_leaf_module_works(): void
    {
        $this->modules->enableWithDependencies($this->tenant, ['leads']);

        $this->modules->disable($this->tenant, 'leads');

        $this->assertSame(['customers'], $this->modules->enabledCodes($this->tenant));
    }

    public function test_an_engine_requires_its_modules(): void
    {
        $this->expectException(ModuleDependencyException::class);

        $this->engines->enable($this->tenant, 'commerce');
    }

    public function test_unknown_modules_are_rejected(): void
    {
        $this->expectException(CatalogItemUnavailable::class);

        $this->modules->enableWithDependencies($this->tenant, ['teleportation']);
    }
}
