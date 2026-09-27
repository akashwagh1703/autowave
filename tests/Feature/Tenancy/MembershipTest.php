<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Http\Middleware\ResolveTenantFromMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_their_own_workspace(): void
    {
        $tenant = $this->createTenant('ABC Salon');

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/Dashboard')
                ->where('tenant.id', $tenant->id)
                ->where('workspace.name', 'ABC Salon')
                ->where('widgets', config('catalog.business_types.beauty_salon.configuration.dashboard_widgets')));
    }

    public function test_a_user_in_two_tenants_can_switch_between_them(): void
    {
        $salon = $this->createTenant('ABC Salon');
        $turf = $this->createTenant('ABC Turf', 'turf');
        $manager = User::factory()->create();
        $this->addMember($salon, $manager, 'manager');
        $this->addMember($turf, $manager, 'manager');

        $this->actingAs($manager)->get($this->appUrl('/workspaces'))
            ->assertInertia(fn (Assert $page) => $page->has('workspaces', 2));

        $this->post($this->appUrl("/workspaces/{$turf->id}/switch"))->assertRedirect(route('dashboard'));

        $this->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('tenant.id', $turf->id));

        $this->post($this->appUrl("/workspaces/{$salon->id}/switch"));

        $this->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('tenant.id', $salon->id));
    }

    public function test_users_cannot_switch_into_a_tenant_they_do_not_belong_to(): void
    {
        $mine = $this->createTenant('Mine');
        $theirs = $this->createTenant('Theirs');

        $this->actingAs($this->ownerOf($mine))
            ->post($this->appUrl("/workspaces/{$theirs->id}/switch"))
            ->assertNotFound();
    }

    public function test_a_tampered_session_tenant_falls_back_to_the_users_own_tenant(): void
    {
        $mine = $this->createTenant('Mine');
        $theirs = $this->createTenant('Theirs');

        $this->actingAs($this->ownerOf($mine))
            ->withSession([ResolveTenantFromMembership::SESSION_KEY => $theirs->id])
            ->get($this->appUrl('/dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tenant.id', $mine->id)->where('workspace.name', 'Mine'));
    }

    public function test_suspended_memberships_lose_access(): void
    {
        $tenant = $this->createTenant('ABC Salon');
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff', MembershipStatus::Suspended);

        $this->actingAs($staff)->get($this->appUrl('/dashboard'))->assertRedirect(route('workspaces.index'));
        $this->post($this->appUrl("/workspaces/{$tenant->id}/switch"))->assertNotFound();
    }

    public function test_members_of_a_suspended_tenant_lose_access(): void
    {
        $tenant = $this->createTenant('ABC Salon');
        $owner = $this->ownerOf($tenant);

        $tenant->update(['status' => TenantStatus::Suspended]);

        $this->actingAs($owner)->get($this->appUrl('/dashboard'))->assertRedirect(route('workspaces.index'));
        $this->get($this->appUrl('/workspaces'))->assertInertia(fn (Assert $page) => $page->has('workspaces', 0));
    }
}
