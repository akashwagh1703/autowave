<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Events\AppointmentCancelled;
use App\Domain\Booking\Events\AppointmentCompleted;
use App\Domain\Booking\Events\AppointmentConfirmed;
use App\Domain\Booking\Events\AppointmentNoShow;
use App\Domain\Booking\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an appointment through its lifecycle (AppointmentStatus::allowedTransitions).
 * Completed and no-show need the appointment to have started. Each change is recorded on the
 * timeline and fires its automation trigger.
 */
class ChangeAppointmentStatus
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    public function handle(Appointment $appointment, AppointmentStatus $status, ?User $actor = null, ?string $reason = null): Appointment
    {
        $this->ensureAllowed($appointment, $status);

        return DB::transaction(function () use ($appointment, $status, $actor, $reason) {
            $fresh = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->ensureAllowed($fresh, $status);

            $appointment->forceFill(['status' => $status, ...$this->timestamps($status, $reason)])->save();

            $this->recordActivity->handle(
                'appointment_'.$status->value,
                appointment: $appointment,
                actor: $actor,
                body: $status === AppointmentStatus::Cancelled ? $reason : null,
                metadata: BookAppointment::summary($appointment),
            );

            match ($status) {
                AppointmentStatus::Confirmed => AppointmentConfirmed::dispatch($appointment),
                AppointmentStatus::Completed => AppointmentCompleted::dispatch($appointment),
                AppointmentStatus::Cancelled => AppointmentCancelled::dispatch($appointment),
                AppointmentStatus::NoShow => AppointmentNoShow::dispatch($appointment),
                AppointmentStatus::Pending => null,
            };

            return $appointment;
        });
    }

    private function ensureAllowed(Appointment $appointment, AppointmentStatus $status): void
    {
        if (! $appointment->status->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => __('A :from appointment cannot be marked :to.', [
                    'from' => strtolower($appointment->status->label()),
                    'to' => strtolower($status->label()),
                ]),
            ]);
        }

        if ($status->requiresStart() && $appointment->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'status' => __('An appointment can be marked :to once it has started.', ['to' => strtolower($status->label())]),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function timestamps(AppointmentStatus $status, ?string $reason): array
    {
        return match ($status) {
            AppointmentStatus::Confirmed => ['confirmed_at' => now()],
            AppointmentStatus::Completed => ['completed_at' => now()],
            AppointmentStatus::Cancelled => ['cancelled_at' => now(), 'cancellation_reason' => $reason],
            AppointmentStatus::NoShow => ['no_show_at' => now()],
            AppointmentStatus::Pending => [],
        };
    }
}
