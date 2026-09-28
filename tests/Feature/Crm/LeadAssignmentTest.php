<?php

namespace Tests\Feature\Crm;

use App\Domain\Lead\Actions\AssignLead;
use App\Domain\Lead\Actions\ChangeLeadStage;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Events\LeadAssigned;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LeadAssignmentTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_only_active_members_who_can_work_leads_are_assignable(): void
    {
        $tenant = $this->createTenant();
        $sales = $this->addMember($tenant, User::factory()->create(['name' => 'Sam Sales']), 'sales_executive');
        $this->addMember($tenant, User::factory()->create(), 'staff');
        $this->addMember($tenant, User::factory()->create(), 'accountant');
        $this->addMember($tenant, User::factory()->create(), 'manager', MembershipStatus::Suspended);

        $ids = $this->inTenant($tenant, fn () => app(AssignLead::class)->assignableMembers()->modelKeys());

        $this->assertEqualsCanonicalizing([$this->membershipOf($tenant, $this->ownerOf($tenant))->id, $sales->id], $ids);
    }

    public function test_assigning_records_an_activity_and_fires_an_event(): void
    {
        Event::fake([LeadAssigned::class]);
        $tenant = $this->createTenant();
        $sales = $this->addMember($tenant, User::factory()->create(['name' => 'Sam Sales']), 'sales_executive');
        $lead = $this->makeLead($tenant);

        $this->inTenant($tenant, fn () => app(AssignLead::class)->handle($lead, $sales));

        $this->assertSame($sales->id, $lead->fresh()->assigned_tenant_user_id);
        $this->inTenant($tenant, function () use ($lead) {
            $activity = $lead->activities()->where('type', 'assigned')->sole();
            $this->assertSame('Sam Sales', $activity->metadata['to']['name']);
            $this->assertFalse($activity->metadata['automatic']);
        });
        Event::assertDispatched(LeadAssigned::class, fn (LeadAssigned $event) => $event->assignee->is($sales));
    }

    public function test_members_without_lead_access_cannot_be_assigned(): void
    {
        $tenant = $this->createTenant();
        $staff = $this->addMember($tenant, User::factory()->create(), 'staff');
        $lead = $this->makeLead($tenant);

        $this->expectException(ValidationException::class);

        $this->inTenant($tenant, fn () => app(AssignLead::class)->handle($lead, $staff));
    }

    public function test_auto_assign_picks_the_member_with_the_fewest_open_leads(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
        $sales = $this->addMember($tenant, User::factory()->create(), 'sales_executive');
        $this->inTenant($tenant, fn () => TenantSetting::query()->updateOrCreate(['key' => 'crm'], ['value' => ['auto_assign' => true]]));

        // Ties go to the longest-standing member (the owner), then load balances.
        $first = $this->makeLead($tenant);
        $second = $this->makeLead($tenant);
        $third = $this->makeLead($tenant);

        $this->assertSame($owner->id, $first->fresh()->assigned_tenant_user_id);
        $this->assertSame($sales->id, $second->fresh()->assigned_tenant_user_id);
        $this->assertSame($owner->id, $third->fresh()->assigned_tenant_user_id);

        // Closed leads do not count towards a member's load.
        $this->inTenant($tenant, function () use ($first, $third) {
            app(ChangeLeadStage::class)->toOutcome($first, StageOutcome::Lost);
            app(ChangeLeadStage::class)->toOutcome($third, StageOutcome::Lost);
        });
        $this->assertSame($owner->id, $this->makeLead($tenant)->fresh()->assigned_tenant_user_id);

        $this->inTenant($tenant, fn () => $this->assertTrue($second->activities()->where('type', 'assigned')->sole()->metadata['automatic']));
    }

    public function test_leads_stay_unassigned_when_auto_assign_is_off(): void
    {
        $tenant = $this->createTenant();

        $this->assertNull($this->makeLead($tenant)->fresh()->assigned_tenant_user_id);
    }

    public function test_assignment_requires_the_assign_permission(): void
    {
        $tenant = $this->createTenant();
        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');
        $lead = $this->makeLead($tenant);

        $this->actingAs($receptionist)
            ->patch($this->appUrl("/leads/{$lead->id}/assign"), ['assigned_tenant_user_id' => null])
            ->assertForbidden();

        $this->actingAs($receptionist)
            ->post($this->appUrl('/leads'), ['name' => 'New', 'phone' => '9876501234', 'assigned_tenant_user_id' => $this->membershipOf($tenant, $receptionist)->id])
            ->assertSessionHasErrors('assigned_tenant_user_id');
    }

    public function test_a_sales_executive_can_assign_through_the_app(): void
    {
        $tenant = $this->createTenant();
        $salesUser = User::factory()->create();
        $sales = $this->addMember($tenant, $salesUser, 'sales_executive');
        $lead = $this->makeLead($tenant);

        $this->actingAs($salesUser)
            ->patch($this->appUrl("/leads/{$lead->id}/assign"), ['assigned_tenant_user_id' => $sales->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($sales->id, $lead->fresh()->assigned_tenant_user_id);
    }
}
