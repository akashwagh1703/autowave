<?php

namespace App\Domain\Tenant\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class MissingTenantContext extends RuntimeException
{
    public static function make(): self
    {
        return new self('No tenant is set in the TenantContext.');
    }

    public static function forModel(Model $model): self
    {
        return new self(sprintf('Cannot create [%s] without a tenant: set the TenantContext or provide tenant_id.', $model::class));
    }
}
