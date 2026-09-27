<?php

namespace App\Domain\Tenant\Models;

use App\Domain\Business\Models\BusinessType;
use App\Domain\Domain\Models\Domain;
use App\Domain\Engine\Models\TenantEngine;
use App\Domain\Module\Models\TenantModule;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A business workspace. Not tenant-scoped itself; it is the tenant.
 */
#[Fillable(['name', 'slug', 'business_type_id', 'business_type_version', 'status', 'is_internal', 'timezone', 'locale', 'currency', 'created_by_user_id'])]
#[UseFactory(TenantFactory::class)]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'is_internal' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'is_internal' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot(['id', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function primaryDomain(): HasOne
    {
        return $this->hasOne(Domain::class)->where('is_primary', true);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    public function tenantModules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function tenantEngines(): HasMany
    {
        return $this->hasMany(TenantEngine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Tenant-scoped models: load inside TenantContext::run() for this tenant. */
    public function websiteConfig(): HasOne
    {
        return $this->hasOne(WebsiteConfig::class);
    }

    public function websiteSections(): HasMany
    {
        return $this->hasMany(WebsiteSection::class)->orderBy('sort_order');
    }
}
