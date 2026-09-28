<?php

namespace Tests\Feature\Crm;

use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CrmSettingsTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function stageRows(Tenant $tenant): array
    {
        return $this->inTenant($tenant, fn () => LeadStage::query()->ordered()->get()
            ->map(fn (LeadStage $stage) => ['id' => $stage->id, 'name' => $stage->name, 'color' => $stage->color, 'outcome' => $stage->outcome->value, 'is_active' => $stage->is_active])
            ->all());
    }

    public function test_settings_page_lists_stages_with_lead_counts(): void
    {
        $tenant = $this->createTenant();
        $this->makeLead($tenant);

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/settings/crm'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/settings/Crm')
                ->has('stages', 6)
                ->where('stages.0.leads_count', 1)
                ->has('sources', count(config('crm.lead_sources')))
                ->where('autoAssign', false));
    }

    public function test_stages_can_be_renamed_reordered_added_and_deactivated(): void
    {
        $tenant = $this->createTenant();
        $rows = $this->stageRows($tenant);
        $rows[0]['name'] = 'Fresh enquiry';
        [$rows[1], $rows[2]] = [$rows[2], $rows[1]];
        $rows[3]['is_active'] = false;
        $rows[] = ['id' => null, 'name' => 'Trial booked', 'color' => '#8b5cf6', 'outcome' => 'open', 'is_active' => true];

        $this->actingAs($this->ownerOf($tenant))
            ->put($this->appUrl('/settings/crm/stages'), ['stages' => $rows])
            ->assertSessionHasNoErrors();

        $this->inTenant($tenant, function () {
            $stages = LeadStage::query()->ordered()->get();
            $this->assertSame(['Fresh enquiry', 'Qualified', 'Contacted', 'Follow-up', 'Converted', 'Lost', 'Trial booked'], $stages->pluck('name')->all());
            $this->assertFalse($stages[3]->is_active);
            $this->assertSame('trial_booked', $stages->last()->code);
        });
    }

    public function test_every_outcome_keeps_an_active_stage(): void
    {
        $tenant = $this->createTenant();
        $rows = array_map(fn (array $row) => $row['outcome'] === 'won' ? [...$row, 'is_active' => false] : $row, $this->stageRows($tenant));

        $this->actingAs($this->ownerOf($tenant))
            ->put($this->appUrl('/settings/crm/stages'), ['stages' => $rows])
            ->assertSessionHasErrors('stages');
    }

    public function test_stages_cannot_be_removed_or_smuggled_in_from_another_tenant(): void
    {
        $tenant = $this->createTenant();
        $other = $this->createTenant('Other');
        $rows = $this->stageRows($tenant);

        $this->actingAs($this->ownerOf($tenant));

        $this->put($this->appUrl('/settings/crm/stages'), ['stages' => array_slice($rows, 0, 5)])->assertSessionHasErrors('stages');

        $foreign = $this->stageRows($other)[0];
        $this->put($this->appUrl('/settings/crm/stages'), ['stages' => [...$rows, $foreign]])->assertSessionHasErrors('stages');

        $this->inTenant($other, fn () => $this->assertSame('New', LeadStage::query()->ordered()->first()->name));
    }

    public function test_a_stage_with_leads_cannot_change_outcome(): void
    {
        $tenant = $this->createTenant();
        $this->makeLead($tenant);
        $rows = $this->stageRows($tenant);
        $rows[0]['outcome'] = 'lost';

        $this->actingAs($this->ownerOf($tenant))
            ->put($this->appUrl('/settings/crm/stages'), ['stages' => $rows])
            ->assertSessionHasErrors('stages.0.outcome');
    }

    public function test_sources_can_be_managed_but_one_must_stay_active(): void
    {
        $tenant = $this->createTenant();
        $rows = $this->inTenant($tenant, fn () => LeadSource::query()->ordered()->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'is_active' => true])->all());

        $this->actingAs($this->ownerOf($tenant));

        $this->put($this->appUrl('/settings/crm/sources'), ['sources' => [...$rows, ['id' => null, 'name' => 'Justdial', 'is_active' => true]]])->assertSessionHasNoErrors();
        $this->inTenant($tenant, fn () => $this->assertTrue(LeadSource::query()->where('code', 'justdial')->exists()));

        $all = $this->inTenant($tenant, fn () => LeadSource::query()->ordered()->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'is_active' => false])->all());
        $this->put($this->appUrl('/settings/crm/sources'), ['sources' => $all])->assertSessionHasErrors('sources');

        $duplicate = $rows;
        $duplicate[1]['name'] = $duplicate[0]['name'];
        $this->put($this->appUrl('/settings/crm/sources'), ['sources' => [...$duplicate, ['id' => null, 'name' => 'X1', 'is_active' => true]]])->assertSessionHasErrors('sources');
    }

    public function test_auto_assign_can_be_toggled(): void
    {
        $tenant = $this->createTenant();

        $this->actingAs($this->ownerOf($tenant))
            ->put($this->appUrl('/settings/crm/assignment'), ['auto_assign' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->inTenant($tenant, fn () => app(TenantContext::class)->setting('crm')['auto_assign']));
    }

    public function test_only_settings_updaters_can_change_the_pipeline(): void
    {
        $tenant = $this->createTenant();
        $manager = User::factory()->create();
        $this->addMember($tenant, $manager, 'manager');

        $this->actingAs($manager);

        $this->get($this->appUrl('/settings/crm'))->assertOk();
        $this->put($this->appUrl('/settings/crm/stages'), ['stages' => $this->stageRows($tenant)])->assertForbidden();
        $this->put($this->appUrl('/settings/crm/assignment'), ['auto_assign' => true])->assertForbidden();
    }
}
