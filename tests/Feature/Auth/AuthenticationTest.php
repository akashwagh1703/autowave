<?php

namespace Tests\Feature\Auth;

use App\Domain\User\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders_on_the_app_host(): void
    {
        $this->get($this->appUrl('/login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
    }

    public function test_login_routes_do_not_exist_on_other_hosts(): void
    {
        $this->get($this->marketingUrl('/login'))->assertNotFound();
        $this->get($this->adminUrl('/register'))->assertNotFound();
    }

    public function test_users_can_log_in_and_last_login_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->post($this->appUrl('/login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.com']);

        $this->post($this->appUrl('/login'), ['email' => 'Asha@Example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_cannot_log_in_with_a_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->post($this->appUrl('/login'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_suspended_users_cannot_log_in(): void
    {
        $user = User::factory()->suspended()->create();

        $this->post($this->appUrl('/login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_suspended_mid_session_are_logged_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->get($this->appUrl('/workspaces'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post($this->appUrl('/login'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post($this->appUrl('/login'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_users_can_log_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post($this->appUrl('/logout'))->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_guests_are_redirected_to_the_app_login(): void
    {
        $this->get($this->appUrl('/dashboard'))->assertRedirect(route('login'));
    }
}
