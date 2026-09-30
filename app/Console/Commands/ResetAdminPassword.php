<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Sets a new generated password for a platform admin and shows it once. For a lost Super Admin password. */
class ResetAdminPassword extends Command
{
    protected $signature = 'autowave:admin-password {email? : Platform admin email (default: AUTOWAVE_ADMIN_EMAIL)}';

    protected $description = 'Set a new random password for a platform admin and print it once';

    public function handle(): int
    {
        $email = Str::lower((string) ($this->argument('email') ?: config('autowave.platform_admin.email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! $user->is_platform_admin) {
            $this->components->error("No platform admin with the email {$email}.");

            return self::FAILURE;
        }

        $password = Str::password(20);

        $user->forceFill([
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->components->info("New password for {$email}:");
        $this->line("  {$password}");
        $this->newLine();
        $this->components->warn('Store it in a password manager now; it is not shown again.');

        return self::SUCCESS;
    }
}
