<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Booking\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Edits an appointment's price and notes. Time and resource changes go through RescheduleAppointment. */
class UpdateAppointmentDetails
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    /**
     * @param  array{price?: numeric-string|float|int|null, notes?: ?string}  $data
     */
    public function handle(Appointment $appointment, array $data, ?User $actor = null): Appointment
    {
        $appointment->fill(array_intersect_key($data, array_flip(['price', 'notes'])));

        if (! $appointment->isDirty()) {
            return $appointment;
        }

        if ($appointment->isDirty('price') && bccomp((string) ($appointment->amount_paid ?? '0'), '0', 2) > 0) {
            if ($appointment->price === null || bccomp((string) $appointment->price, (string) $appointment->amount_paid, 2) < 0) {
                throw ValidationException::withMessages(['price' => __('The price cannot be less than the amount already paid (:paid).', ['paid' => $appointment->amount_paid])]);
            }
        }

        return DB::transaction(function () use ($appointment, $actor) {
            $changed = array_keys($appointment->getDirty());
            $appointment->save();

            $this->recordActivity->handle('appointment_updated', appointment: $appointment, actor: $actor, metadata: [
                ...BookAppointment::summary($appointment),
                'changed' => $changed,
            ]);

            return $appointment;
        });
    }
}
