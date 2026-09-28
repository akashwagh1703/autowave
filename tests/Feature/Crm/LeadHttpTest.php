<?php

namespace Tests\Feature\Crm;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Lead\Actions\ChangeLeadStage;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Module\Services\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LeadHttpTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_owner_can_add_a_lead_with_a_follow_up_in_the_tenant_timezone(): void
    {
        $tenant = $this->createTenant(options: ['timezone' => 'Asia/Kolkata']);
        $source = $this->inTenant($tenant, fn () => LeadSource::query()->where('code', 'instagram')->sole());

        $response = $this->actingAs($this->ownerOf($tenant))->post($this->appUrl('/leads'), [
            'name' => '  Priya   Sharma ',
            'phone' => '+91 98765 43210',
            'interest' => 'Bridal package',
            'estimated_value' => '25000',
            'lead_source_id' => $source->id,
            'next_followup_at' => '2026-10-01T10:30',
            'notes' => 'Called from Instagram ad',
        ]);

        $lead = Lead::withoutTenantScope()->sole();
        $response->assertRedirect(route('leads.show', $lead));

        $this->assertSame('Priya Sharma', $lead->name);
        $this->assertSame('2026-10-01 05:00:00', $lead->next_followup_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($source->id, $lead->lead_source_id);
        $this->inTenant($tenant, fn () => $this->assertSame(['created', 'note'], $lead->activities()->orderBy('id')->pluck('type')->all()));
    }

    public function test_a_lead_needs_a_name_and_a_phone_or_email(): void
    {
        $tenant = $this->createTenant();

        $this->actingAs($this->ownerOf($tenant))
            ->post($this->appUrl('/leads'), ['name' => '', 'phone' => '', 'email' => ''])
            ->assertSessionHasErrors(['name', 'phone']);

        $this->post($this->appUrl('/leads'), ['name' => 'Email Only', 'email' => 'only@example.com'])
            ->assertSessionHasNoErrors();
    }

    public function test_duplicate_open_leads_are_reported_as_a_validation_error(): void
    {
        $tenant = $this->createTenant();
        $this->makeLead($tenant, ['name' => 'Existing', 'phone' => '9876543210']);

        $this->actingAs($this->ownerOf($tenant))
            ->post($this->appUrl('/leads'), ['name' => 'Again', 'phone' => '+91 98765 43210'])
            ->assertSessionHasErrors('phone');
    }

    public function test_index_lists_leads_with_pipeline_counts_and_filters(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $mine = $this->makeLead($tenant, ['name' => 'Asha Mine', 'assigned_tenant_user_id' => $this->membershipOf($tenant, $owner)->id]);
        $this->makeLead($tenant, ['name' => 'Bina Other', 'email' => 'bina@example.com']);
        $lost = $this->makeLead($tenant, ['name' => 'Chitra Lost']);
        $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->handle($lost, $this->stage($tenant, 'lost')));

        $this->actingAs($owner);

        $this->get($this->appUrl('/leads'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/leads/Index')
                ->where('leads.meta.total', 2)
                ->where('filters.view', 'open')
                ->where('counts.by_stage.'.$this->stage($tenant, 'new')->id, 2)
                ->where('counts.by_stage.'.$this->stage($tenant, 'lost')->id, 1)
                ->has('stages', 6)
                ->has('members', 1)
                ->missing('leads.data.0.phone_normalized'));

        $this->get($this->appUrl('/leads?view=closed'))->assertInertia(fn (Assert $page) => $page->where('leads.data.0.name', 'Chitra Lost')->where('leads.meta.total', 1));
        $this->get($this->appUrl('/leads?assignee=me'))->assertInertia(fn (Assert $page) => $page->where('leads.meta.total', 1)->where('leads.data.0.id', $mine->id));
        $this->get($this->appUrl('/leads?assignee=unassigned'))->assertInertia(fn (Assert $page) => $page->where('leads.meta.total', 1));
        $this->get($this->appUrl('/leads?search=bina@'))->assertInertia(fn (Assert $page) => $page->where('leads.meta.total', 1));
        $this->get($this->appUrl('/leads?search=100%25'))->assertInertia(fn (Assert $page) => $page->where('leads.meta.total', 0));
        $this->get($this->appUrl('/leads?sort=name&view=all'))->assertInertia(fn (Assert $page) => $page->where('leads.data.0.name', 'Asha Mine'));
        $this->get($this->appUrl('/leads?view=bogus&sort=bogus&assignee=x'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.view', 'open')->where('filters.sort', 'newest')->where('filters.assignee', null));
    }

    public function test_search_matches_phone_numbers_regardless_of_formatting(): void
    {
        $tenant = $this->createTenant();
        $this->makeLead($tenant, ['name' => 'Formatted', 'phone' => '+91 98765-43210']);

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/leads?search=9876543210'))
            ->assertInertia(fn (Assert $page) => $page->where('leads.meta.total', 1));
    }

    public function test_show_renders_the_timeline_and_options(): void
    {
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant, ['name' => 'Priya']);

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl("/leads/{$lead->id}"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/leads/Show')
                ->where('lead.name', 'Priya')
                ->where('lead.stage.code', 'new')
                ->has('activities', 1)
                ->where('activities.0.type', 'created')
                ->has('activityTypes', count(config('crm.loggable_activities'))));
    }

    public function test_logging_a_call_updates_last_contacted_and_the_follow_up(): void
    {
        $tenant = $this->createTenant(options: ['timezone' => 'Asia/Kolkata']);
        $lead = $this->makeLead($tenant, ['next_followup_at' => now()->addHour()]);

        $this->actingAs($this->ownerOf($tenant))
            ->post($this->appUrl("/leads/{$lead->id}/activities"), [
                'type' => 'call',
                'body' => 'Asked for price list',
                'update_followup' => true,
                'next_followup_at' => '2026-10-05T09:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $lead->fresh();
        $this->assertNotNull($fresh->last_contacted_at);
        $this->assertSame('2026-10-05 03:30:00', $fresh->next_followup_at->utc()->format('Y-m-d H:i:s'));

        // A note does not count as contact, and leaving the follow-up out keeps it.
        $this->post($this->appUrl("/leads/{$lead->id}/activities"), ['type' => 'note', 'body' => 'Prefers mornings']);
        $this->assertSame('2026-10-05 03:30:00', $lead->fresh()->next_followup_at->utc()->format('Y-m-d H:i:s'));

        $this->post($this->appUrl("/leads/{$lead->id}/activities"), ['type' => 'note', 'body' => ''])->assertSessionHasErrors('body');
        $this->post($this->appUrl("/leads/{$lead->id}/activities"), ['type' => 'converted'])->assertSessionHasErrors('type');
    }

    public function test_stage_change_through_the_app_converts_the_lead(): void
    {
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant, ['name' => 'Neha', 'phone' => '9876501111']);

        $this->actingAs($this->ownerOf($tenant))
            ->patch($this->appUrl("/leads/{$lead->id}/stage"), ['lead_stage_id' => $this->stage($tenant, 'converted')->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($lead->fresh()->customer_id);
    }

    public function test_bulk_actions_apply_to_every_selected_lead_and_are_audited(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $leads = collect([$this->makeLead($tenant), $this->makeLead($tenant), $this->makeLead($tenant)]);
        $ids = $leads->pluck('id')->all();

        $this->actingAs($owner);

        $this->post($this->appUrl('/leads/bulk'), ['action' => 'stage', 'ids' => $ids, 'lead_stage_id' => $this->stage($tenant, 'contacted')->id])->assertSessionHasNoErrors();
        $this->post($this->appUrl('/leads/bulk'), ['action' => 'assign', 'ids' => $ids, 'assigned_tenant_user_id' => $this->membershipOf($tenant, $owner)->id])->assertSessionHasNoErrors();

        $this->inTenant($tenant, function () use ($ids, $owner, $tenant) {
            $this->assertSame(3, Lead::query()->whereKey($ids)->where('lead_stage_id', $this->stage($tenant, 'contacted')->id)->count());
            $this->assertSame(3, Lead::query()->whereKey($ids)->where('assigned_tenant_user_id', $this->membershipOf($tenant, $owner)->id)->count());
        });

        $this->post($this->appUrl('/leads/bulk'), ['action' => 'delete', 'ids' => $ids])->assertSessionHasNoErrors();
        $this->assertSame(0, Lead::withoutTenantScope()->whereKey($ids)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'leads.bulk_delete')->where('tenant_id', $tenant->id)->exists());

        $this->post($this->appUrl('/leads/bulk'), ['action' => 'explode', 'ids' => $ids])->assertSessionHasErrors('action');
        $this->post($this->appUrl('/leads/bulk'), ['action' => 'delete', 'ids' => range(1, 101)])->assertSessionHasErrors('ids');
    }

    public function test_bulk_actions_check_the_permission_for_each_action(): void
    {
        $tenant = $this->createTenant();
        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');
        $lead = $this->makeLead($tenant);

        $this->actingAs($receptionist);

        $this->post($this->appUrl('/leads/bulk'), ['action' => 'delete', 'ids' => [$lead->id]])->assertForbidden();
        $this->post($this->appUrl('/leads/bulk'), ['action' => 'assign', 'ids' => [$lead->id]])->assertForbidden();
        $this->post($this->appUrl('/leads/bulk'), ['action' => 'stage', 'ids' => [$lead->id], 'lead_stage_id' => $this->stage($tenant, 'contacted')->id])->assertSessionHasNoErrors();
    }

    public function test_permissions_are_enforced_per_route(): void
    {
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant);
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');

        $this->actingAs($staff);
        $this->get($this->appUrl('/leads'))->assertForbidden();
        $this->get($this->appUrl("/leads/{$lead->id}"))->assertForbidden();

        $this->actingAs($receptionist);
        $this->get($this->appUrl('/leads'))->assertOk();
        $this->get($this->appUrl('/leads/create'))->assertOk();
        $this->get($this->appUrl("/leads/{$lead->id}/edit"))->assertOk();
        $this->delete($this->appUrl("/leads/{$lead->id}"))->assertForbidden();
        $this->assertNotSoftDeleted('leads', ['id' => $lead->id]);
    }

    public function test_owner_can_edit_and_delete_a_lead(): void
    {
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant, ['name' => 'Before']);

        $this->actingAs($this->ownerOf($tenant));

        $this->put($this->appUrl("/leads/{$lead->id}"), ['name' => 'After', 'phone' => $lead->phone, 'next_followup_at' => null])
            ->assertRedirect(route('leads.show', $lead));
        $this->assertSame('After', $lead->fresh()->name);

        $this->delete($this->appUrl("/leads/{$lead->id}"))->assertRedirect(route('leads.index'));
        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'lead.deleted')->where('subject_id', $lead->id)->exists());
        $this->get($this->appUrl("/leads/{$lead->id}"))->assertNotFound();
    }

    public function test_leads_are_hidden_when_the_module_is_disabled(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');

        $this->assertNotContains('leads', app(ModuleManager::class)->enabledCodes($tenant));

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/leads'))
            ->assertNotFound();

        $this->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page->where('tenant.modules', fn ($modules) => ! collect($modules)->contains('leads')));
    }

    public function test_malformed_ids_are_not_found(): void
    {
        $tenant = $this->createTenant();

        $this->actingAs($this->ownerOf($tenant))->get($this->appUrl('/leads/abc'))->assertNotFound();
    }
}
