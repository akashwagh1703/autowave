<?php

namespace App\Domain\Lead\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a lead came from (website, WhatsApp, walk-in, ...). Configurable per tenant.
 */
#[Fillable(['tenant_id', 'code', 'name', 'sort_order', 'is_active'])]
class LeadSource extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
