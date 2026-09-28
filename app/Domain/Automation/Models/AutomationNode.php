<?php

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\StepType;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of an automation: a condition, a wait or an action, at a position. */
#[Fillable(['tenant_id', 'automation_id', 'position', 'type', 'action', 'config'])]
class AutomationNode extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'type' => StepType::class,
            'config' => 'array',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class)->withTrashed();
    }
}
