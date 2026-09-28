<?php

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\JobStatus;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a run, due at run_at. `anchor` + `offset_minutes` mark a step timed relative to the
 * appointment start, so it can be re-timed when the appointment moves.
 */
#[Fillable([
    'tenant_id', 'automation_run_id', 'step_index', 'run_at', 'status', 'attempts', 'anchor', 'offset_minutes',
    'queued_at', 'started_at', 'finished_at', 'error',
])]
class AutomationJob extends Model
{
    use BelongsToTenant;

    public const ANCHOR_APPOINTMENT_START = 'appointment_start';

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'run_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}
