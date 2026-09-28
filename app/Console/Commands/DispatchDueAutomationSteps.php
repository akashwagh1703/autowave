<?php

namespace App\Console\Commands;

use App\Domain\Automation\Enums\JobStatus;
use App\Domain\Automation\Models\AutomationJob;
use App\Domain\Automation\Services\StepDispatcher;
use App\Domain\Automation\Services\StepRunner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Runs every minute (routes/console.php). Puts automation steps that are due on the queue, and
 * recovers steps a queue outage or a crashed worker left behind (ADR-015):
 *
 * - pending and due → queued (waits that have elapsed, and steps whose dispatch failed);
 * - queued for longer than stuck_queued_minutes → re-queued (the queue message was lost);
 * - running for longer than stuck_running_minutes → re-queued, or failed if out of attempts.
 *
 * Re-queuing is safe: a step is claimed before it runs, and actions use idempotency keys.
 */
class DispatchDueAutomationSteps extends Command
{
    protected $signature = 'automation:dispatch-due';

    protected $description = 'Queue automation steps that are due and recover stuck ones';

    public function handle(StepDispatcher $dispatcher, StepRunner $runner): int
    {
        $batch = (int) config('automation.dispatch_batch');

        $due = AutomationJob::withoutTenantScope()
            ->where('status', JobStatus::Pending)
            ->where('run_at', '<=', now())
            ->orderBy('run_at')
            ->limit($batch)
            ->pluck('id');

        $dispatched = $due->filter(fn (int $id) => $dispatcher->dispatch($id))->count();

        $stuckQueued = AutomationJob::withoutTenantScope()
            ->where('status', JobStatus::Queued)
            ->where('updated_at', '<', now()->subMinutes((int) config('automation.stuck_queued_minutes')))
            ->limit($batch)
            ->pluck('id');

        $requeued = $stuckQueued->filter(fn (int $id) => $this->requeue($dispatcher, $id, JobStatus::Queued))->count();

        $stuckRunning = AutomationJob::withoutTenantScope()
            ->where('status', JobStatus::Running)
            ->where('updated_at', '<', now()->subMinutes((int) config('automation.stuck_running_minutes')))
            ->limit($batch)
            ->get(['id', 'attempts']);

        $recovered = 0;
        $failed = 0;

        foreach ($stuckRunning as $job) {
            if ($job->attempts >= (int) config('automation.tries')) {
                $runner->fail($job->id, new RuntimeException('The step stopped responding (the worker may have crashed).'));
                $failed++;
            } elseif ($this->requeue($dispatcher, $job->id, JobStatus::Running)) {
                $recovered++;
            }
        }

        $this->components->info("Dispatched {$dispatched} due step(s); re-queued {$requeued} stuck queued and {$recovered} stuck running; failed {$failed}.");

        return self::SUCCESS;
    }

    /** Back to pending (only if nothing else moved it meanwhile), then dispatch as usual. */
    private function requeue(StepDispatcher $dispatcher, int $id, JobStatus $from): bool
    {
        $reset = AutomationJob::withoutTenantScope()->whereKey($id)
            ->where('status', $from)
            ->update(['status' => JobStatus::Pending, 'updated_at' => now()]);

        return $reset === 1 && $dispatcher->dispatch($id);
    }
}
