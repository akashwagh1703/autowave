<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_admin_login_renders_on_the_admin_host_only(): void
    {
        $this->get($this->adminUrl('/login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/auth/Login'));

        $this->get($this->appUrl('/tenants'))->assertNotFound();
    }

    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get($this->adminUrl('/'))->assertRedirect(route('admin.login'));
    }

    public function test_platform_admins_can_log_in_and_it_is_audited(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->post($this->adminUrl('/login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login', 'user_id' => $admin->id]);
    }

    public function test_non_admins_cannot_log_in_to_admin_even_with_the_right_password(): void
    {
        $user = User::factory()->create();

        $this->post($this->adminUrl('/login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login_failed']);
    }

    public function test_suspended_admins_cannot_log_in(): void
    {
        $admin = User::factory()->platformAdmin()->suspended()->create();

        $this->post($this->adminUrl('/login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_signed_in_non_admins_get_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->adminUrl('/'))
            ->assertForbidden();
    }

    public function test_admins_see_the_dashboard_and_tenants(): void
    {
        $this->createTenant('ABC Salon');
        $this->createTenant('XYZ Clinic', 'clinic');

        $this->actingAs(User::factory()->platformAdmin()->create());

        $this->get($this->adminUrl('/'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/Dashboard')->where('stats.tenants', 2));

        $this->get($this->adminUrl('/tenants?search=clinic'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/tenants/Index')
                ->has('tenants.data', 1)
                ->where('tenants.data.0.name', 'XYZ Clinic'));
    }

    public function test_admins_can_suspend_and_activate_tenants_with_an_audit_trail(): void
    {
        $tenant = $this->createTenant('ABC Salon', options: ['slug' => 'abc-salon']);
        $admin = User::factory()->platformAdmin()->create();

        $this->get($this->siteUrl('abc-salon.autowave.test'))->assertOk();

        $this->actingAs($admin)->post($this->adminUrl("/tenants/{$tenant->id}/suspend"))->assertSessionHas('success');

        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()->status);
        $this->get($this->siteUrl('abc-salon.autowave.test'))->assertNotFound();

        $log = AuditLog::query()->where('action', 'tenant.suspended')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($tenant->id, $log->subject_id);
        $this->assertEquals(['from' => 'active', 'to' => 'suspended'], $log->metadata);

        $this->post($this->adminUrl("/tenants/{$tenant->id}/activate"));

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.activated', 'subject_id' => $tenant->id]);
    }

    public function test_the_internal_tenant_cannot_be_suspended(): void
    {
        $tenant = $this->createTenant('AutoWave Internal', 'autowave_internal', options: ['is_internal' => true]);

        $this->actingAs(User::factory()->platformAdmin()->create())
            ->post($this->adminUrl("/tenants/{$tenant->id}/suspend"))
            ->assertSessionHas('error');

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
    }

    public function test_admin_logout(): void
    {
        $this->actingAs(User::factory()->platformAdmin()->create())
            ->post($this->adminUrl('/logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }
}
