<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first platform administrator if it does not exist. Never resets an existing password.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('autowave.platform_admin');
        $email = Str::lower($config['email']);

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $password = $config['password'] ?: Str::password(20);

        $user = User::query()->create([
            'name' => $config['name'],
            'email' => $email,
            'password' => $password,
        ]);

        $user->forceFill([
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ])->save();

        if (! $config['password']) {
            $this->command?->warn("Platform admin {$email} created with generated password: {$password}");
            $this->command?->warn('Store it in a password manager now; it is not shown again.');
        }
    }
}
