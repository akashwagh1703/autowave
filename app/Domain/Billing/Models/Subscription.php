<?php

namespace App\Domain\Billing\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A business's plan and how long it is paid for (one row per business). Access is worked out from the dates
 * (Entitlements), so it never depends on how the period was paid. `ends_at` null means it never ends (the
 * internal business). A cheaper plan chosen while a period runs starts at `plan_changes_at`.
 */
#[Fillable(['tenant_id', 'plan_id', 'period', 'is_trial', 'starts_at', 'ends_at', 'next_plan_id', 'next_period', 'plan_changes_at', 'reminders'])]
class Subscription extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_trial' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'plan_changes_at' => 'datetime',
            'reminders' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function nextPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'next_plan_id');
    }

    /** The plan in force at $at: the scheduled plan once its change date has passed. */
    public function planAt(?Carbon $at = null): Plan
    {
        $at ??= now();

        return $this->next_plan_id && $this->plan_changes_at && $at->gte($this->plan_changes_at) ? $this->nextPlan : $this->plan;
    }

    public function periodAt(?Carbon $at = null): ?string
    {
        $at ??= now();

        return $this->next_plan_id && $this->plan_changes_at && $at->gte($this->plan_changes_at) ? $this->next_period : $this->period;
    }
}
