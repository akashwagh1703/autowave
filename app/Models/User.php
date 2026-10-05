<?php

namespace App\Models;

use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\User\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Throwable;

/**
 * A person. Users are global; access to a business comes from tenant memberships.
 * is_platform_admin and status are never mass-assignable.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'status' => 'active',
        'is_platform_admin' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'is_platform_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** Sent inline at sign-up; a mail outage must not turn registration into an error page (resend is in the app). */
    public function sendEmailVerificationNotification(): void
    {
        try {
            parent::sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot(['id', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Tenants the user can currently work in: active membership in an active tenant.
     */
    public function accessibleTenants(): BelongsToMany
    {
        return $this->tenants()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->where('tenants.status', TenantStatus::Active->value)
            ->orderBy('tenants.name');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin && $this->isActive();
    }

    /**
     * The active membership to use, preferring the given tenant when the user can access it.
     */
    public function activeMembership(?int $preferredTenantId = null): ?TenantUser
    {
        $query = TenantUser::query()
            ->where('tenant_users.user_id', $this->getKey())
            ->where('tenant_users.status', MembershipStatus::Active)
            ->whereHas('tenant', fn ($tenant) => $tenant->where('status', TenantStatus::Active))
            ->orderBy('tenant_users.id');

        if ($preferredTenantId !== null) {
            $preferred = (clone $query)->where('tenant_users.tenant_id', $preferredTenantId)->first();

            if ($preferred) {
                return $preferred;
            }
        }

        return $query->first();
    }
}
