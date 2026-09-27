<?php

namespace App\Domain\Tenant\Concerns;

use App\Domain\Tenant\Exceptions\CrossTenantWrite;
use App\Domain\Tenant\Exceptions\MissingTenantContext;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Scopes\TenantScope;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Use on every tenant-owned model (see docs/02-architecture/multi-tenancy.md).
 *
 * - Reads are scoped to the current tenant (fail closed without one).
 * - tenant_id is filled from the context on create; it cannot come from another tenant.
 * - tenant_id can never be changed after creation.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $tenantId = $model->getAttribute('tenant_id');

            if ($tenantId === null) {
                if ($model->allowsTenantlessCreation()) {
                    return;
                }

                if (! $context->check()) {
                    throw MissingTenantContext::forModel($model);
                }

                $model->setAttribute('tenant_id', $context->id());

                return;
            }

            if ($context->check() && (int) $tenantId !== $context->id()) {
                throw CrossTenantWrite::forModel($model, $context->id());
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw CrossTenantWrite::forModel($model, app(TenantContext::class)->id());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Query across all tenants. Only for platform/system code; review every use.
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }

    /**
     * Override to allow deliberate tenant-less records (e.g. platform templates).
     */
    public function allowsTenantlessCreation(): bool
    {
        return false;
    }
}
