<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\AppointmentPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Removes an appointment payment recorded by mistake. Not a refund; kept on the timeline and in the audit log. */
class RemoveAppointmentPayment
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Appointment $appointment, AppointmentPayment $payment, ?User $actor = null): void
    {
        abort_unless($payment->appointment_id === $appointment->id, 404);

        DB::transaction(function () use ($appointment, $payment, $actor) {
            Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $payment->delete();
            RecordAppointmentPayment::refreshTotals($appointment);
            $appointment->loadMissing(['resource', 'service']);

            $metadata = ['amount' => (string) $payment->amount, 'method' => $payment->method];

            $this->recordActivity->handle('payment_removed', appointment: $appointment, actor: $actor, metadata: [...BookAppointment::summary($appointment), ...$metadata]);
            $this->audit->log('appointment.payment_removed', $appointment, $metadata);
        });
    }
}
