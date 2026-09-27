<?php

namespace App\Domain\Tenant\Scopes;

use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts tenant-owned models to the current tenant.
 *
 * Fails closed: without a tenant in the context, queries return no rows.
 * Platform/system code that must see every tenant calls withoutTenantScope().
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->check()) {
            $builder->where($model->qualifyColumn('tenant_id'), $context->id());

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
