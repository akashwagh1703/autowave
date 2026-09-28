<?php

namespace App\Domain\Automation\Jobs;

use App\Domain\Automation\Services\StepRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executes one step of an automation run on the `automation` queue. Carries only the step row id;
 * StepRunner loads everything else inside the run's tenant context.
 */
class RunAutomationStep implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public readonly int $automationJobId)
    {
        $this->onQueue(config('automation.queue'));
        $this->tries = (int) config('automation.tries');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('automation.backoff');
    }

    public function handle(StepRunner $runner): void
    {
        $runner->run($this->automationJobId);
    }

    public function failed(?Throwable $exception): void
    {
        app(StepRunner::class)->fail($this->automationJobId, $exception);
    }
}
