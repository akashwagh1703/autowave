<?php

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Enums\JobStatus;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Enums\StepType;
use App\Domain\Automation\Models\AutomationJob;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\AutomationCatalog;
use App\Domain\Automation\Support\ConditionEvaluator;
use App\Domain\Automation\Support\SubjectContext;
use App\Domain\Automation\Support\WaitCalculator;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes one step row of a run (ADR-015):
 *
 * 1. claim the row (pending|queued → running) — a second worker or a duplicate queue message
 *    finds nothing to claim and stops, so a step never executes twice at the same time;
 * 2. stop if the run is over, the automation was turned off or deleted, or the subject is gone;
 * 3. condition → carry on or skip the run; wait → schedule the next step; action → do it;
 * 4. complete the row and schedule the next step in one transaction.
 *
 * A failing action puts the row back to queued and rethrows, so the queue retries it with backoff.
 * After the last attempt RunAutomationStep::failed() calls fail().
 */
class StepRunner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StepDispatcher $dispatcher,
        private readonly RunLogger $logger,
        private readonly ConditionEvaluator $conditions,
        private readonly WaitCalculator $waits,
    ) {}

    public function run(int $jobId): void
    {
        $this->inTenant($jobId, function (AutomationJob $job) {
            if (! $this->claim($job)) {
                return;
            }

            $job->refresh();
            $run = $job->run;
            $previousDepth = Context::get('automation_depth');
            Context::add('automation_depth', $run->depth + 1);

            try {
                $this->execute($job, $run);
            } catch (Throwable $exception) {
                $job->forceFill(['status' => JobStatus::Queued, 'error' => Str::limit($exception->getMessage(), 1000, '')])->save();
                $this->logger->log($run, 'step.failed', "Attempt {$job->attempts} of step ".($job->step_index + 1).' failed: '.Str::limit($exception->getMessage(), 300), 'warning', $job->step_index, [
                    'attempt' => $job->attempts,
                    'exception' => $exception::class,
                ]);

                throw $exception;
            } finally {
                $previousDepth === null ? Context::forget('automation_depth') : Context::add('automation_depth', $previousDepth);
            }
        });
    }

    /** The step used all its attempts: the run fails, with the error kept for the run page. */
    public function fail(int $jobId, ?Throwable $exception): void
    {
        $this->inTenant($jobId, function (AutomationJob $job) use ($exception) {
            $error = Str::limit($exception?->getMessage() ?? 'Unknown error', 1000, '');
            $job->forceFill(['status' => JobStatus::Failed, 'finished_at' => now(), 'error' => $error])->save();

            $run = $job->run;

            if ($run->status->isFinal()) {
                return;
            }

            $run->forceFill(['status' => RunStatus::Failed, 'error' => $error, 'completed_at' => now()])->save();
            $this->logger->log($run, 'run.failed', 'Step '.($job->step_index + 1)." failed after {$job->attempts} attempt(s): ".Str::limit($error, 300), 'error', $job->step_index);
        });
    }

    /** @param  callable(AutomationJob): void  $callback */
    private function inTenant(int $jobId, callable $callback): void
    {
        $job = AutomationJob::withoutTenantScope()->find($jobId);
        $tenant = $job ? Tenant::query()->find($job->tenant_id) : null;

        if ($job && $tenant) {
            $this->context->run($tenant, fn () => $callback($job));
        }
    }

    private function claim(AutomationJob $job): bool
    {
        return AutomationJob::query()->whereKey($job->id)
            ->whereIn('status', JobStatus::CLAIMABLE)
            ->update([
                'status' => JobStatus::Running,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    private function execute(AutomationJob $job, AutomationRun $run): void
    {
        if ($run->status->isFinal()) {
            $this->finishJob($job, JobStatus::Cancelled);

            return;
        }

        if ($reason = $this->blocker($run)) {
            $this->cancel($run, $job, $reason);

            return;
        }

        $subject = $run->freshSubject();

        if (! $subject) {
            $this->cancel($run, $job, 'The '.$run->subject_type.' no longer exists.');

            return;
        }

        $step = $run->steps[$job->step_index] ?? null;

        if ($step === null) {
            $this->advance($job, $run);

            return;
        }

        $this->markRunning($run);
        $run->increment('attempts');
        $context = SubjectContext::for($this->context->tenant(), $subject);

        match (StepType::from($step['type'])) {
            StepType::Condition => $this->condition($job, $run, $step, $context),
            StepType::Wait => $this->wait($job, $run, $step, $context),
            StepType::Action => $this->action($job, $run, $step, $context),
        };
    }

    /** @param  array<string, mixed>  $step */
    private function condition(AutomationJob $job, AutomationRun $run, array $step, SubjectContext $context): void
    {
        $result = $this->conditions->evaluate($step['config'], $context);

        if ($result['passed']) {
            $this->logger->log($run, 'condition.passed', 'Condition met.', step: $job->step_index, context: ['results' => $result['results']]);
            $this->advance($job, $run);

            return;
        }

        $failed = collect($result['results'])->firstWhere('passed', false);
        $this->logger->log($run, 'condition.failed', 'Condition not met'.($failed ? ': '.$this->describeRule($failed) : '').'. The run stopped here.', step: $job->step_index, context: ['results' => $result['results']]);

        DB::transaction(function () use ($job, $run) {
            $this->finishJob($job, JobStatus::Completed);
            $run->forceFill(['status' => RunStatus::Skipped, 'completed_at' => now()])->save();
        });
    }

    /** @param  array<string, mixed>  $step */
    private function wait(AutomationJob $job, AutomationRun $run, array $step, SubjectContext $context): void
    {
        $schedule = $this->waits->schedule($step['config'], $context, CarbonImmutable::now());
        $runAt = $schedule['run_at'];
        $later = $runAt->isFuture();

        $this->logger->log(
            $run,
            'wait.scheduled',
            $later ? 'Waiting until '.$runAt->setTimezone(TenantTime::timezone())->format('D j M, g:i A').'.' : 'The wait time has already passed; carrying on now.',
            step: $job->step_index,
            context: ['run_at' => $runAt->toIso8601String()],
        );

        $this->advance($job, $run, $runAt, $schedule['anchor'], $schedule['offset_minutes'], waiting: $later);
    }

    /** @param  array<string, mixed>  $step */
    private function action(AutomationJob $job, AutomationRun $run, array $step, SubjectContext $context): void
    {
        $key = (string) $step['action'];
        $label = config("automation.actions.{$key}.label", $key);
        $catalog = app(AutomationCatalog::class);

        if (! $catalog->trigger($run->trigger) || ! array_key_exists($key, $catalog->actionsFor($run->trigger))) {
            $this->logger->log($run, 'action.skipped', "{$label}: this action is no longer available for the business.", 'warning', $job->step_index);
            $this->advance($job, $run);

            return;
        }

        $result = $catalog->action($key)->handle(
            new ActionContext($run, $job->step_index, $context, ActionContext::keyFor($run, $job->step_index), $run->automation?->name),
            $step['config'],
        );

        $this->logger->log($run, $result->skipped ? 'action.skipped' : 'action.completed', "{$label}: {$result->message}", step: $job->step_index, context: $result->data);
        $this->advance($job, $run);
    }

    /** Complete this step and schedule the next one (or complete the run), atomically. */
    private function advance(AutomationJob $job, AutomationRun $run, ?CarbonImmutable $nextAt = null, ?string $anchor = null, ?int $offsetMinutes = null, bool $waiting = false): void
    {
        DB::transaction(function () use ($job, $run, $nextAt, $anchor, $offsetMinutes, $waiting) {
            $this->finishJob($job, JobStatus::Completed);
            $next = $job->step_index + 1;

            if ($next >= $run->stepCount()) {
                $run->forceFill(['status' => RunStatus::Completed, 'completed_at' => now(), 'error' => null])->save();
                $this->logger->log($run, 'run.completed', 'Completed.');

                return;
            }

            if ($waiting) {
                $run->forceFill(['status' => RunStatus::Waiting])->save();
            }

            $this->dispatcher->schedule($run, $next, $nextAt ?? now(), $anchor, $offsetMinutes);
        });
    }

    private function markRunning(AutomationRun $run): void
    {
        if ($run->status === RunStatus::Running) {
            return;
        }

        $starting = $run->status === RunStatus::Pending;
        $run->forceFill(['status' => RunStatus::Running, 'started_at' => $run->started_at ?? now()])->save();

        if ($starting) {
            $this->logger->log($run, 'run.started', 'Started by “'.AutomationCatalog::triggerLabel($run->trigger).'”.');
        }
    }

    private function blocker(AutomationRun $run): ?string
    {
        $automation = $run->automation;

        return match (true) {
            ! $this->context->tenant()->isActive() => 'The business is not active.',
            ! app(Entitlements::class)->canOperate($this->context->tenant()) => 'The business’s plan has ended.',
            ! $this->context->hasModule('automation') => 'Automations are turned off for this business.',
            ! $automation || $automation->trashed() => 'The automation was deleted.',
            ! $automation->is_active => 'The automation was turned off.',
            default => null,
        };
    }

    private function cancel(AutomationRun $run, AutomationJob $job, string $reason): void
    {
        DB::transaction(function () use ($run, $job, $reason) {
            $this->finishJob($job, JobStatus::Cancelled);
            AutomationJob::query()->where('automation_run_id', $run->id)->whereIn('status', JobStatus::CLAIMABLE)
                ->update(['status' => JobStatus::Cancelled, 'finished_at' => now(), 'updated_at' => now()]);
            $run->forceFill(['status' => RunStatus::Cancelled, 'completed_at' => now()])->save();
            $this->logger->log($run, 'run.cancelled', "Cancelled: {$reason}", step: $job->step_index);
        });
    }

    private function finishJob(AutomationJob $job, JobStatus $status): void
    {
        $job->forceFill(['status' => $status, 'finished_at' => now(), 'error' => null])->save();
    }

    /** @param  array{field: string, operator: string, value: mixed, actual: mixed}  $result */
    private function describeRule(array $result): string
    {
        $field = AutomationCatalog::definition('fields', $result['field']);
        $label = $field['label'] ?? $result['field'];
        $operator = config("automation.operator_labels.{$result['operator']}", $result['operator']);
        $display = function (mixed $value) use ($field): string {
            if ($value === null || $value === '' || $value === []) {
                return 'empty';
            }

            if (is_bool($value)) {
                return $value ? 'yes' : 'no';
            }

            if (isset($field['options'])) {
                $labels = array_column(app(AutomationCatalog::class)->options($field['options']), 'label', 'value');

                return collect((array) $value)->map(fn ($item) => $labels[(string) $item] ?? (string) $item)->join(', ');
            }

            return is_array($value) ? implode(', ', $value) : (string) $value;
        };

        $expected = in_array($result['operator'], ['is_set', 'is_not_set', 'is_true', 'is_false'], true) ? '' : ' '.$display($result['value']);

        return mb_strtolower($label)." {$operator}{$expected} (it is {$display($result['actual'])})";
    }
}
