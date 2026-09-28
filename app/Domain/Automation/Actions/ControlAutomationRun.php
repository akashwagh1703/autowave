<?php

namespace App\Domain\Automation\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Automation\Enums\JobStatus;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\AutomationJob;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\RunLogger;
use App\Domain\Automation\Services\StepDispatcher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual control of a run from the run page: retry a failed run from the step that failed, or
 * cancel a run that is still in progress.
 */
class ControlAutomationRun
{
    public function __construct(
        private readonly StepDispatcher $dispatcher,
        private readonly RunLogger $logger,
        private readonly AuditLogger $audit,
    ) {}

    public function retry(AutomationRun $run, User $actor): void
    {
        $job = DB::transaction(function () use ($run, $actor) {
            $run = AutomationRun::query()->lockForUpdate()->findOrFail($run->id);
            $job = $run->jobs()->where('status', JobStatus::Failed)->orderByDesc('step_index')->first();

            if ($run->status !== RunStatus::Failed || ! $job) {
                throw ValidationException::withMessages(['run' => __('Only failed runs can be retried.')]);
            }

            $job->forceFill([
                'status' => JobStatus::Pending,
                'run_at' => now(),
                'attempts' => 0,
                'error' => null,
                'queued_at' => null,
                'started_at' => null,
                'finished_at' => null,
            ])->save();

            $run->forceFill(['status' => RunStatus::Running, 'error' => null, 'completed_at' => null])->save();
            $this->logger->log($run, 'run.retried', "Retried by {$actor->name} from step ".($job->step_index + 1).'.', step: $job->step_index);
            $this->audit->log('automation.run_retried', $run, ['automation_id' => $run->automation_id, 'step' => $job->step_index]);

            return $job;
        });

        $this->dispatcher->dispatch($job->id);
    }

    public function cancel(AutomationRun $run, User $actor): void
    {
        DB::transaction(function () use ($run, $actor) {
            $run = AutomationRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->status->isFinal()) {
                throw ValidationException::withMessages(['run' => __('Only runs in progress can be cancelled.')]);
            }

            AutomationJob::query()->where('automation_run_id', $run->id)->whereIn('status', JobStatus::CLAIMABLE)
                ->update(['status' => JobStatus::Cancelled, 'finished_at' => now(), 'updated_at' => now()]);

            $run->forceFill(['status' => RunStatus::Cancelled, 'completed_at' => now()])->save();
            $this->logger->log($run, 'run.cancelled', "Cancelled by {$actor->name}.");
            $this->audit->log('automation.run_cancelled', $run, ['automation_id' => $run->automation_id]);
        });
    }
}
