<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Booking\Events\AppointmentRescheduled;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Services\Availability;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a pending or confirmed appointment to a new time and/or resource, with the same
 * protection as booking. Both resources are locked in id order so two opposite moves cannot
 * deadlock.
 */
class RescheduleAppointment
{
    public function __construct(
        private readonly Availability $availability,
        private readonly RecordActivity $recordActivity,
    ) {}

    public function handle(
        Appointment $appointment,
        DateTimeInterface $startsAt,
        ?int $resourceId = null,
        ?int $durationMinutes = null,
        ?User $actor = null,
        bool $allowOutsideHours = false,
    ): Appointment {
        if (! $appointment->status->isActive()) {
            throw ValidationException::withMessages(['starts_at' => __('Only pending or confirmed appointments can be rescheduled.')]);
        }

        $target = $resourceId && $resourceId !== $appointment->booking_resource_id
            ? (BookingResource::query()->find($resourceId) ?? throw ValidationException::withMessages(['booking_resource_id' => __('Choose who or what to book.')]))
            : $appointment->resource;

        $service = $appointment->service;

        if ($target->id !== $appointment->booking_resource_id && $service && ! $service->trashed() && ! $target->offers($service)) {
            throw ValidationException::withMessages(['booking_resource_id' => __(':resource does not offer :service.', ['resource' => $target->name, 'service' => $service->name])]);
        }

        $duration = $durationMinutes ?? $appointment->durationMinutes();
        BookAppointment::ensureDuration($duration);

        $start = CarbonImmutable::instance($startsAt)->utc()->startOfMinute();
        $end = $start->addMinutes($duration);

        if ($start < CarbonImmutable::now()->startOfMinute()) {
            throw ValidationException::withMessages(['starts_at' => __('Choose a time in the future.')]);
        }

        if ($start->equalTo($appointment->starts_at) && $end->equalTo($appointment->ends_at) && $target->id === $appointment->booking_resource_id) {
            return $appointment;
        }

        try {
            return DB::transaction(function () use ($appointment, $target, $start, $end, $actor, $allowOutsideHours) {
                $lockedResources = BookingResource::query()
                    ->whereKey(array_unique([$appointment->booking_resource_id, $target->id]))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $fresh = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

                if (! $fresh->status->isActive()) {
                    throw ValidationException::withMessages(['starts_at' => __('Only pending or confirmed appointments can be rescheduled.')]);
                }

                $locked = $lockedResources->get($target->id) ?? throw ValidationException::withMessages(['booking_resource_id' => __('Choose who or what to book.')]);

                if ($problem = $this->availability->problem($locked, $start, $end, $appointment->id, $allowOutsideHours)) {
                    throw ValidationException::withMessages(['starts_at' => $problem]);
                }

                $previousStart = $appointment->starts_at->copy();
                $previousResource = $appointment->resource;
                $from = BookAppointment::summary($appointment);

                $appointment->update([
                    'booking_resource_id' => $locked->id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                ]);
                $appointment->setRelation('resource', $locked);

                $this->recordActivity->handle('appointment_rescheduled', appointment: $appointment, actor: $actor, metadata: [
                    'from' => $from,
                    'to' => BookAppointment::summary($appointment),
                ]);

                AppointmentRescheduled::dispatch(
                    $appointment,
                    $previousStart,
                    $previousResource->id !== $locked->id ? $previousResource->id : null,
                );

                return $appointment;
            });
        } catch (QueryException $exception) {
            throw BookAppointment::isOverlap($exception)
                ? ValidationException::withMessages(['starts_at' => __('This time was just booked. Choose another time.')])
                : $exception;
        }
    }
}
