<?php

namespace App\Domain\Automation\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant's workflow: a trigger and ordered steps (automation_nodes). Soft-deleted so its run
 * history stays readable.
 */
#[Fillable(['tenant_id', 'name', 'description', 'trigger', 'is_active', 'once_per_subject', 'template_key', 'created_by_user_id'])]
class Automation extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'once_per_subject' => 'boolean',
        ];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(AutomationNode::class)->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The steps as stored on a run: type, action and config per step.
     *
     * @return list<array{type: string, action: ?string, config: array<string, mixed>}>
     */
    public function stepDefinitions(): array
    {
        return $this->nodes->map(fn (AutomationNode $node) => [
            'type' => $node->type->value,
            'action' => $node->action,
            'config' => $node->config ?? [],
        ])->values()->all();
    }
}
