<?php

namespace App\Domain\Automation\Listeners;

use App\Domain\Automation\Enums\JobStatus;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\AutomationJob;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\RunLogger;
use App\Domain\Booking\Events\AppointmentRescheduled;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\TenantTime;
use Throwable;

/**
 * Keeps "before/after the appointment" waits on time: when an appointment moves, its runs' pending
 * steps anchored to the start are re-timed to the new start.
 */
class RetimeAppointmentWaits
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RunLogger $logger,
    ) {}

    public function handle(AppointmentRescheduled $event): void
    {
        try {
            $this->retime($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function retime(AppointmentRescheduled $event): void
    {
        $appointment = $event->appointment;

        if ($this->context->id() !== $appointment->tenant_id) {
            return;
        }

        $runs = AutomationRun::query()
            ->where('subject_type', 'appointment')
            ->where('subject_id', $appointment->id)
            ->whereIn('status', RunStatus::IN_PROGRESS)
            ->get()
            ->keyBy('id');

        if ($runs->isEmpty()) {
            return;
        }

        $jobs = AutomationJob::query()
            ->whereIn('automation_run_id', $runs->keys())
            ->where('status', JobStatus::Pending)
            ->where('anchor', AutomationJob::ANCHOR_APPOINTMENT_START)
            ->get();

        foreach ($jobs as $job) {
            $runAt = $appointment->starts_at->copy()->addMinutes((int) $job->offset_minutes);
            $job->forceFill(['run_at' => $runAt])->save();

            $this->logger->log(
                $runs[$job->automation_run_id],
                'wait.retimed',
                'The appointment moved; now waiting until '.$runAt->copy()->setTimezone(TenantTime::timezone())->format('D j M, g:i A').'.',
                step: $job->step_index,
                context: ['run_at' => $runAt->toIso8601String()],
            );
        }
    }
}
