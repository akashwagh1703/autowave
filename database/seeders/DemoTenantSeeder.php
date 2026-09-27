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

        $salon = $this->tenant($createTenant, 'owner@abc-salon.test', 'Asha Owner', 'ABC Salon', 'abc-salon', 'beauty_salon');
        $turf = $this->tenant($createTenant, 'owner@abc-turf.test', 'Tarun Owner', 'ABC Turf', 'abc-turf', 'turf');

        $staff = $this->user('staff@abc-salon.test', 'Sana Staff');
        $this->member($context, $assignRole, $salon, $staff, 'staff');

        $manager = $this->user('manager@autowave.test', 'Meera Manager');
        $this->member($context, $assignRole, $salon, $manager, 'manager');
        $this->member($context, $assignRole, $turf, $manager, 'manager');

        $this->command?->info('Demo tenants: abc-salon, abc-turf. Users: owner@abc-salon.test, owner@abc-turf.test, staff@abc-salon.test, manager@autowave.test (password: password).');
    }

    private function tenant(CreateTenant $createTenant, string $email, string $name, string $business, string $slug, string $type): Tenant
    {
        return Tenant::query()->where('slug', $slug)->first()
            ?? $createTenant->handle($this->user($email, $name), $business, $type, ['slug' => $slug]);
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
