<?php

namespace App\Domain\Billing\Support;

use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Scopes\TenantScope;
use App\Domain\User\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** Who billing emails go to: a business's active owners, and active platform admins. */
class BillingRecipients
{
    /** @return Collection<int, User> */
    public static function owners(Tenant $tenant): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active)
            ->whereHas('memberships', fn ($membership) => $membership
                ->where('tenant_id', $tenant->getKey())
                ->where('status', MembershipStatus::Active)
                ->whereHas('roles', fn ($role) => $role->withoutGlobalScope(TenantScope::class)->where('roles.slug', 'owner')))
            ->get();
    }

    /** @return Collection<int, User> */
    public static function platformAdmins(): Collection
    {
        return User::query()->where('is_platform_admin', true)->where('status', UserStatus::Active)->get();
    }
}
