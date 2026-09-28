<?php

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Enums\JobStatus;
use App\Domain\Automation\Jobs\RunAutomationStep;
use App\Domain\Automation\Models\AutomationJob;
use App\Domain\Automation\Models\AutomationRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Creates step rows (automation_jobs) and puts due ones on the `automation` queue.
 *
 * A step row is created once per (run, step) — the unique key makes scheduling idempotent. Rows due
 * later wait for automation:dispatch-due. If the queue is down, the row stays pending and the
 * scheduler dispatches it on its next pass: work is delayed, never lost.
 */
class StepDispatcher
{
    public function schedule(AutomationRun $run, int $step, CarbonInterface $runAt, ?string $anchor = null, ?int $offsetMinutes = null): AutomationJob
    {
        $job = AutomationJob::query()->firstOrCreate(
            ['automation_run_id' => $run->id, 'step_index' => $step],
            [
                'tenant_id' => $run->tenant_id,
                'run_at' => $runAt,
                'status' => JobStatus::Pending,
                'anchor' => $anchor,
                'offset_minutes' => $offsetMinutes,
            ],
        );

        if ($job->wasRecentlyCreated && ! $job->run_at->isFuture()) {
            $this->dispatch($job->id);
        }

        return $job;
    }

    /** pending → queued, then onto the queue (after the surrounding transaction commits). */
    public function dispatch(int $jobId): bool
    {
        $marked = AutomationJob::withoutTenantScope()->whereKey($jobId)
            ->where('status', JobStatus::Pending)
            ->update(['status' => JobStatus::Queued, 'queued_at' => now(), 'updated_at' => now()]);

        if ($marked !== 1) {
            return false;
        }

        try {
            Bus::dispatch((new RunAutomationStep($jobId))->afterCommit());
        } catch (Throwable $exception) {
            AutomationJob::withoutTenantScope()->whereKey($jobId)
                ->where('status', JobStatus::Queued)
                ->update(['status' => JobStatus::Pending, 'queued_at' => null, 'updated_at' => now()]);

            report($exception);

            return false;
        }

        return true;
    }
}
