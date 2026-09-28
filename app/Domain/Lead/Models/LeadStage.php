<?php

namespace App\Domain\Lead\Models;

use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's pipeline stage. Configurable per tenant; `outcome` gives it platform meaning.
 */
#[Fillable(['tenant_id', 'code', 'name', 'color', 'outcome', 'sort_order', 'is_active'])]
class LeadStage extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'outcome' => 'open',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'outcome' => StageOutcome::class,
            'is_active' => 'boolean',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /** First active stage with the given outcome (the default target for that outcome). */
    public static function firstFor(StageOutcome $outcome): ?self
    {
        return static::query()->active()->where('outcome', $outcome)->ordered()->first();
    }
}
