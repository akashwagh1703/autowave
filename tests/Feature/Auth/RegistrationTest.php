<?php

namespace Tests\Feature\Auth;

use App\Domain\Platform\Support\PlatformSettings;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_renders(): void
    {
        $this->get($this->appUrl('/register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Register'));
    }

    public function test_new_users_can_register_and_must_verify_their_email(): void
    {
        Notification::fake();

        $this->post($this->appUrl('/register'), [
            'name' => 'Asha Patil',
            'email' => 'asha@example.com',
            'password' => 'Str0ng-passw0rd!',
            'password_confirmation' => 'Str0ng-passw0rd!',
        ])->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'asha@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_platform_admin);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get($this->appUrl('/dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_with_email_confirmation_off_new_users_are_confirmed_without_an_email(): void
    {
        Notification::fake();
        app(PlatformSettings::class)->set(PlatformSettings::REQUIRE_EMAIL_VERIFICATION, false);

        $this->post($this->appUrl('/register'), [
            'name' => 'Asha Patil',
            'email' => 'asha@example.com',
            'password' => 'Str0ng-passw0rd!',
            'password_confirmation' => 'Str0ng-passw0rd!',
        ])->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'asha@example.com')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        Notification::assertNothingSent();

        $this->get($this->appUrl('/dashboard'))->assertRedirect(route('onboarding.create'));
    }

    public function test_unconfirmed_users_get_in_while_confirmation_is_off_and_not_after(): void
    {
        $user = User::factory()->unverified()->create();
        app(PlatformSettings::class)->set(PlatformSettings::REQUIRE_EMAIL_VERIFICATION, false);

        $this->actingAs($user)->get($this->appUrl('/dashboard'))->assertRedirect(route('onboarding.create'));

        app(PlatformSettings::class)->set(PlatformSettings::REQUIRE_EMAIL_VERIFICATION, true);

        $this->get($this->appUrl('/dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_registration_cannot_grant_platform_admin(): void
    {
        $this->post($this->appUrl('/register'), [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'Str0ng-passw0rd!',
            'password_confirmation' => 'Str0ng-passw0rd!',
            'is_platform_admin' => true,
            'status' => 'active',
        ]);

        $this->assertFalse(User::query()->where('email', 'mallory@example.com')->firstOrFail()->is_platform_admin);
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verified_user_without_a_business_is_sent_to_onboarding(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->appUrl('/dashboard'))
            ->assertRedirect(route('onboarding.create'));

        $this->get($this->appUrl('/workspaces'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('business/Workspaces')->has('workspaces', 0)->where('canCreate', true));
    }
}
