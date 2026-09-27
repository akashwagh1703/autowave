<?php

namespace App\Domain\Tenant\Models;

use App\Domain\RBAC\Models\Role;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Membership of a user in a tenant. Roles hang off the membership, so removing a
 * membership removes the user's roles in that tenant.
 */
#[Table('tenant_users')]
#[Fillable(['tenant_id', 'user_id', 'status', 'joined_at'])]
class TenantUser extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'tenant_user_id', 'role_id')
            ->withTimestamps();
    }
}
