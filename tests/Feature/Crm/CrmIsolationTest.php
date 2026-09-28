<?php

namespace Tests\Feature\Crm;

use App\Domain\Activity\Models\Activity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Exceptions\CrossTenantWrite;
use App\Domain\Tenant\Exceptions\MissingTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Mandatory cross-tenant tests (master prompt §86) for the CRM.
 */
class CrmIsolationTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_tenant_a_cannot_read_tenant_b_customer(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirs = $this->makeCustomer($b, ['name' => 'Secret Customer']);

        $this->actingAs($this->ownerOf($a));

        $this->get($this->appUrl("/customers/{$theirs->id}"))->assertNotFound();
        $this->get($this->appUrl("/customers/{$theirs->id}/edit"))->assertNotFound();
        $this->get($this->appUrl('/customers?search=Secret'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('customers.meta.total', 0));
    }

    public function test_tenant_a_cannot_update_tenant_b_lead(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirs = $this->makeLead($b, ['name' => 'Their Lead']);

        $this->actingAs($this->ownerOf($a));

        $this->put($this->appUrl("/leads/{$theirs->id}"), ['name' => 'Hijacked', 'phone' => '9999999999'])->assertNotFound();
        $this->patch($this->appUrl("/leads/{$theirs->id}/stage"), ['lead_stage_id' => $this->stage($a, 'contacted')->id])->assertNotFound();
        $this->patch($this->appUrl("/leads/{$theirs->id}/assign"), ['assigned_tenant_user_id' => null])->assertNotFound();
        $this->post($this->appUrl("/leads/{$theirs->id}/activities"), ['type' => 'note', 'body' => 'x'])->assertNotFound();
        $this->delete($this->appUrl("/leads/{$theirs->id}"))->assertNotFound();

        $fresh = Lead::withoutTenantScope()->findOrFail($theirs->id);
        $this->assertSame('Their Lead', $fresh->name);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame(1, Activity::withoutTenantScope()->where('lead_id', $theirs->id)->count());
    }

    public function test_tenant_a_cannot_read_tenant_b_lead_or_see_it_in_lists(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirs = $this->makeLead($b, ['name' => 'Their Lead']);
        $this->makeLead($a, ['name' => 'My Lead']);

        $this->actingAs($this->ownerOf($a));

        $this->get($this->appUrl("/leads/{$theirs->id}"))->assertNotFound();
        $this->get($this->appUrl('/leads?view=all'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leads.meta.total', 1)
                ->where('leads.data.0.name', 'My Lead'));
    }

    public function test_bulk_actions_ignore_ids_from_another_tenant(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $mine = $this->makeLead($a);
        $theirs = $this->makeLead($b);

        $this->actingAs($this->ownerOf($a))
            ->post($this->appUrl('/leads/bulk'), ['action' => 'delete', 'ids' => [$mine->id, $theirs->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('leads', ['id' => $mine->id]);
        $this->assertNotSoftDeleted('leads', ['id' => $theirs->id]);
    }

    public function test_a_lead_cannot_be_moved_to_another_tenants_stage_or_assigned_to_their_member(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $lead = $this->makeLead($a);

        $this->actingAs($this->ownerOf($a));

        $this->patch($this->appUrl("/leads/{$lead->id}/stage"), ['lead_stage_id' => $this->stage($b, 'contacted')->id])
            ->assertSessionHasErrors('lead_stage_id');
        $this->patch($this->appUrl("/leads/{$lead->id}/assign"), ['assigned_tenant_user_id' => $this->membershipOf($b, $this->ownerOf($b))->id])
            ->assertSessionHasErrors('assigned_tenant_user_id');

        $fresh = Lead::withoutTenantScope()->findOrFail($lead->id);
        $this->assertSame($this->stage($a, 'new')->id, $fresh->lead_stage_id);
        $this->assertNull($fresh->assigned_tenant_user_id);
    }

    public function test_composite_foreign_keys_reject_cross_tenant_references_at_the_database(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $lead = $this->makeLead($a);
        $foreignStage = $this->stage($b, 'contacted');

        $this->expectException(QueryException::class);

        DB::table('leads')->where('id', $lead->id)->update(['lead_stage_id' => $foreignStage->id]);
    }

    public function test_crm_models_fail_closed_without_a_tenant(): void
    {
        $tenant = $this->createTenant();
        $this->makeLead($tenant);
        $this->makeCustomer($tenant);

        $this->assertSame(0, Lead::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, LeadStage::query()->count());
        $this->assertSame(0, Activity::query()->count());

        $this->expectException(MissingTenantContext::class);
        Customer::query()->create(['name' => 'Orphan']);
    }

    public function test_writing_into_another_tenant_is_refused(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        $this->expectException(CrossTenantWrite::class);

        $this->inTenant($a, fn () => Customer::query()->create(['tenant_id' => $b->id, 'name' => 'Sneaky']));
    }
}
