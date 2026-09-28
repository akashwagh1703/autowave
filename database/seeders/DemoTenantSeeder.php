<?php

namespace Database\Seeders;

use App\Domain\RBAC\Actions\AssignRole;
use App\Domain\RBAC\Models\Role;
use App\Domain\Tenant\Actions\CreateTenant;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo data: two tenants to exercise isolation by hand. Password for all: "password".
 * Refuses to run in production.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(CreateTenant $createTenant, AssignRole $assignRole, TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoTenantSeeder must not run in production.');
        }

        $salon = $this->tenant($createTenant, 'owner@abc-salon.test', 'Asha Owner', 'ABC Salon', 'abc-salon', 'beauty_salon', [
            'branding' => ['primary_color' => '#db2777', 'tagline' => 'Hair, skin and nails in the heart of Pune'],
            'profile' => ['phone' => '+91 98765 43210', 'city' => 'Pune', 'description' => 'A neighbourhood salon offering haircuts, colour, facials and bridal packages.'],
        ]);
        $turf = $this->tenant($createTenant, 'owner@abc-turf.test', 'Tarun Owner', 'ABC Turf', 'abc-turf', 'turf', [
            'branding' => ['primary_color' => '#16a34a', 'tagline' => 'Floodlit 5-a-side football, open till midnight'],
            'profile' => ['phone' => '+91 91234 56789', 'city' => 'Pune'],
        ]);
        $coaching = $this->tenant($createTenant, 'owner@abc-coaching.test', 'Kiran Owner', 'ABC Coaching', 'abc-coaching', 'coaching', [
            'branding' => ['primary_color' => '#2563eb', 'tagline' => 'Maths and science coaching for classes 8 to 12'],
            'profile' => ['phone' => '+91 90000 11111', 'city' => 'Pune', 'description' => 'Small batches, weekly tests and personal doubt-solving for school and board exams.'],
        ]);
        $this->tenant($createTenant, 'owner@abc-cafe.test', 'Chetan Owner', 'ABC Cafe', 'abc-cafe', 'cafe', [
            'branding' => ['primary_color' => '#b45309', 'tagline' => 'All-day breakfast, coffee and comfort food'],
            'profile' => ['phone' => '+91 90000 22222', 'city' => 'Pune', 'description' => 'A cosy neighbourhood cafe with indoor and garden seating.'],
        ]);
        $this->tenant($createTenant, 'owner@abc-store.test', 'Sunil Owner', 'ABC Store', 'abc-store', 'local_store', [
            'branding' => ['primary_color' => '#0d9488', 'tagline' => 'Daily groceries, delivered in your neighbourhood'],
            'profile' => ['phone' => '+91 90000 33333', 'city' => 'Pune'],
        ]);

        $staff = $this->user('staff@abc-salon.test', 'Sana Staff');
        $this->member($context, $assignRole, $salon, $staff, 'staff');

        $teacher = $this->user('teacher@abc-coaching.test', 'Tanvi Teacher');
        $this->member($context, $assignRole, $coaching, $teacher, 'staff');

        $manager = $this->user('manager@autowave.test', 'Meera Manager');
        $this->member($context, $assignRole, $salon, $manager, 'manager');
        $this->member($context, $assignRole, $turf, $manager, 'manager');

        $this->command?->info('Demo tenants: abc-salon, abc-turf, abc-coaching, abc-cafe, abc-store. Owners: owner@<slug>.test; also staff@abc-salon.test, teacher@abc-coaching.test, manager@autowave.test (password: password).');
    }

    private function tenant(CreateTenant $createTenant, string $email, string $name, string $business, string $slug, string $type, array $options): Tenant
    {
        return Tenant::query()->where('slug', $slug)->first()
            ?? $createTenant->handle($this->user($email, $name), $business, $type, ['slug' => $slug, ...$options]);
    }

    private function user(string $email, string $name): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        return $user;
    }

    private function member(TenantContext $context, AssignRole $assignRole, Tenant $tenant, User $user, string $roleSlug): void
    {
        $context->run($tenant, function (Tenant $tenant) use ($assignRole, $user, $roleSlug) {
            $membership = TenantUser::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['status' => MembershipStatus::Active, 'joined_at' => now()],
            );

            $assignRole->handle($membership, Role::query()->where('slug', $roleSlug)->firstOrFail());
        });
    }
}
