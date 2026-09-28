<?php

namespace Tests\Feature\Automation;

use App\Domain\Automation\Actions\DeleteAutomation;
use App\Domain\Automation\Actions\ProvisionAutomations;
use App\Domain\Automation\Models\Automation;
use App\Domain\Business\Models\BusinessType;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Tenant\Models\TenantSetting;
use Database\Seeders\TenantBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AutomationProvisioningTest extends TestCase
{
    use CreatesAutomations, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_a_new_salon_gets_the_default_automations_with_messages_paused(): void
    {
        $tenant = $this->createTenant();

        $automations = $this->inTenant($tenant, fn () => Automation::query()->with('nodes')->get()->keyBy('template_key'));

        $this->assertEqualsCanonicalizing([
            'new_lead_welcome', 'new_lead_followup', 'appointment_confirmation', 'appointment_reminder', 'no_show_followup', 'thank_you', 'new_online_order_alert', 'order_ready',
        ], $automations->keys()->all());
        $this->assertSame(['new_lead_followup', 'new_online_order_alert', 'no_show_followup'], $automations->where('is_active', true)->keys()->sort()->values()->all());
        $this->assertSame(['wait', 'condition', 'action'], $automations['new_lead_followup']->nodes->pluck('type.value')->all());

        // Catalogue-only key: not copied into tenant settings.
        $this->assertFalse($this->inTenant($tenant, fn () => TenantSetting::query()->where('key', 'automation_templates')->exists()));
    }

    public function test_templates_the_business_cannot_use_are_skipped(): void
    {
        $tenant = $this->createTenant('Bright Classes', 'coaching');

        $automations = $this->inTenant($tenant, fn () => Automation::query()->get()->keyBy('template_key'));

        // No booking, commerce or food engine: only the lead and education templates apply.
        $this->assertSame(
            ['admission_welcome', 'demo_class_confirmation', 'fee_due_reminder', 'fee_overdue_followup', 'new_lead_followup', 'new_lead_welcome'],
            $automations->keys()->sort()->values()->all(),
        );
        $this->assertSame(['fee_overdue_followup', 'new_lead_followup'], $automations->where('is_active', true)->keys()->sort()->values()->all());
    }

    public function test_a_cafe_gets_the_order_and_reservation_templates(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');

        $automations = $this->inTenant($tenant, fn () => Automation::query()->get()->keyBy('template_key'));

        // No leads module: the lead templates do not apply.
        $this->assertSame(
            ['new_online_order_alert', 'new_reservation_alert', 'order_ready', 'reservation_confirmation'],
            $automations->keys()->sort()->values()->all(),
        );
        $this->assertSame(['new_online_order_alert', 'new_reservation_alert'], $automations->where('is_active', true)->keys()->sort()->values()->all());
    }

    public function test_a_business_type_can_choose_its_templates(): void
    {
        $type = BusinessType::latestActive('beauty_salon');
        $type->update(['configuration' => [...$type->configuration, 'automation_templates' => ['thank_you']]]);

        $tenant = $this->createTenant();

        $this->assertSame(['thank_you'], $this->inTenant($tenant, fn () => Automation::query()->pluck('template_key')->all()));
    }

    public function test_provisioning_is_idempotent_and_never_brings_back_a_deleted_default(): void
    {
        $tenant = $this->createTenant();
        $followup = $this->template($tenant, 'new_lead_followup');
        $this->inTenant($tenant, fn () => app(DeleteAutomation::class)->handle($followup));

        $created = app(ProvisionAutomations::class)->ensureFor($tenant);

        $this->assertSame([], $created);
        $this->assertSame(7, $this->inTenant($tenant, fn () => Automation::query()->count()));
    }

    public function test_the_backfill_provisions_tenants_that_had_no_automations(): void
    {
        $tenant = $this->createTenant();
        Automation::withoutTenantScope()->where('tenant_id', $tenant->id)->forceDelete();

        $this->seed(TenantBackfillSeeder::class);

        $this->assertSame(8, $this->inTenant($tenant, fn () => Automation::query()->count()));
    }

    public function test_no_automations_without_the_module(): void
    {
        $tenant = $this->createTenant(options: ['modules' => ['crm', 'leads', 'customers']]);

        $this->assertSame(0, $this->inTenant($tenant, fn () => Automation::query()->count()));

        // Without the messaging module only the templates that send nothing apply.
        app(ModuleManager::class)->enable($tenant, 'automation');
        $this->assertEqualsCanonicalizing(['new_lead_followup', 'no_show_followup', 'new_online_order_alert'], app(ProvisionAutomations::class)->ensureFor($tenant));
    }
}
