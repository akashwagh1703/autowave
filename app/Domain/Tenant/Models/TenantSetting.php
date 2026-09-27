<?php

namespace App\Domain\Tenant\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'key', 'value'])]
class TenantSetting extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
