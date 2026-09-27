<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_can_be_requested(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->get($this->appUrl('/forgot-password'))
            ->assertInertia(fn (Assert $page) => $page->component('auth/ForgotPassword'));

        $this->post($this->appUrl('/forgot-password'), ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post($this->appUrl('/forgot-password'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get($this->appUrl('/reset-password/'.$notification->token.'?email='.urlencode($user->email)))
                ->assertInertia(fn (Assert $page) => $page
                    ->component('auth/ResetPassword')
                    ->where('token', $notification->token)
                    ->where('email', $user->email));

            $this->post($this->appUrl('/reset-password'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'N3w-passw0rd!',
                'password_confirmation' => 'N3w-passw0rd!',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertTrue(Hash::check('N3w-passw0rd!', $user->fresh()->password));
    }
}
