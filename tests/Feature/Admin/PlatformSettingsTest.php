<?php

namespace Tests\Feature\Admin;

use App\Domain\Platform\Support\PlatformSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_can_switch_email_confirmation_off_and_on_with_an_audit_trail(): void
    {
        User::factory()->unverified()->create();
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);

        $this->get($this->adminUrl('/settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/settings/Index')
                ->where('settings.require_email_verification', true)
                ->where('unverifiedUsers', 1));

        $this->put($this->adminUrl('/settings'), ['require_email_verification' => false])->assertSessionHas('success');

        $this->assertFalse(app(PlatformSettings::class)->requireEmailVerification());
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.settings_updated', 'user_id' => $admin->id]);

        $this->put($this->adminUrl('/settings'), ['require_email_verification' => true]);

        $this->assertTrue(app(PlatformSettings::class)->requireEmailVerification());
    }

    public function test_only_platform_admins_can_change_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($this->adminUrl('/settings'))->assertForbidden();
        $this->put($this->adminUrl('/settings'), ['require_email_verification' => false])->assertForbidden();

        $this->assertTrue(app(PlatformSettings::class)->requireEmailVerification());
    }

    public function test_the_setting_must_be_a_boolean(): void
    {
        $this->actingAs(User::factory()->platformAdmin()->create())
            ->put($this->adminUrl('/settings'), ['require_email_verification' => 'maybe'])
            ->assertSessionHasErrors('require_email_verification');
    }

    public function test_the_admin_password_command_sets_a_new_password_for_platform_admins_only(): void
    {
        $admin = User::factory()->platformAdmin()->create(['email' => 'admin@autowave.test']);
        User::factory()->create(['email' => 'owner@autowave.test']);

        $this->artisan('autowave:admin-password', ['email' => 'admin@autowave.test'])->assertSuccessful();
        $this->assertFalse(Hash::check('password', $admin->fresh()->password));

        $this->artisan('autowave:admin-password', ['email' => 'owner@autowave.test'])->assertFailed();
    }
}
