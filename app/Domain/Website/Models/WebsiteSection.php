<?php

namespace App\Domain\Website\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An ordered, configurable block of the tenant's website (hero, about, contact, ...).
 * Content that exists elsewhere (services, prices, contact details) is read from tenant data at render time.
 */
#[Fillable(['tenant_id', 'type', 'sort_order', 'enabled', 'configuration'])]
class WebsiteSection extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'configuration' => 'array',
        ];
    }
}
