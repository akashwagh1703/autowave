<?php

namespace App\Domain\Tenant\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CrossTenantWrite extends RuntimeException
{
    public static function forModel(Model $model, ?int $contextTenantId): self
    {
        return new self(sprintf(
            'Refusing to write [%s] for tenant [%s] while the current tenant is [%s].',
            $model::class,
            $model->getAttribute('tenant_id') ?? 'null',
            $contextTenantId ?? 'none',
        ));
    }
}
