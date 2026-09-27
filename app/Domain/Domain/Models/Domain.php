<?php

namespace App\Domain\Domain\Models;

use App\Domain\Domain\Enums\DomainStatus;
use App\Domain\Domain\Enums\DomainType;
use App\Domain\Domain\Enums\SslStatus;
use App\Domain\Domain\Services\DomainResolver;
use App\Domain\Domain\Support\Hostname;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hostname served as a tenant's public website. Platform-level table: domain
 * resolution must look across all tenants, so it is not tenant-scoped.
 */
#[Fillable(['tenant_id', 'domain', 'type', 'is_primary', 'status', 'verified_at', 'ssl_status'])]
class Domain extends Model
{
    protected static function booted(): void
    {
        $flush = function (self $domain): void {
            $resolver = app(DomainResolver::class);
            $resolver->forget($domain->domain);
            $resolver->forget($domain->getOriginal('domain'));
        };

        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'status' => DomainStatus::class,
            'ssl_status' => SslStatus::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected function domain(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Hostname::normalize($value));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status === DomainStatus::Active;
    }
}
