<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Booking\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
