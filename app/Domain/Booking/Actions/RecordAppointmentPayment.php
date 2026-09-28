<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\AppointmentPayment;
use App\Domain\Commerce\Services\OrderPricing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money received for an appointment, e.g. a turf advance (ADR-020). The appointment needs a
 * price, a payment cannot be more than the balance, and cancelled appointments take no payments.
 * amount_paid is recomputed from the payment rows under a lock on the appointment.
 */
class RecordAppointmentPayment
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{amount: numeric-string|float|int, method: string, reference?: ?string, paid_at?: ?\DateTimeInterface}  $data
     */
    public function handle(Appointment $appointment, array $data, ?User $actor = null): AppointmentPayment
    {
        $amount = OrderPricing::money($data['amount'] ?? 0);

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount greater than zero.')]);
        }

        if (! array_key_exists($data['method'] ?? '', config('commerce.payment_methods'))) {
            throw ValidationException::withMessages(['method' => __('Choose how the customer paid.')]);
        }

        return DB::transaction(function () use ($appointment, $data, $actor, $amount) {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === AppointmentStatus::Cancelled) {
                throw ValidationException::withMessages(['amount' => __('A cancelled appointment cannot take payments.')]);
            }

            if ($locked->price === null) {
                throw ValidationException::withMessages(['amount' => __('Set a price for this appointment before recording a payment.')]);
            }

            $balance = $locked->balance();

            if (bccomp($amount, $balance, 2) > 0) {
                throw ValidationException::withMessages(['amount' => bccomp($balance, '0', 2) <= 0
                    ? __('This appointment is already fully paid.')
                    : __('The amount cannot be more than the balance due (:balance).', ['balance' => $balance])]);
            }

            $payment = $locked->payments()->create([
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                'paid_at' => $data['paid_at'] ?? now(),
                'recorded_by_user_id' => $actor?->id,
            ]);

            self::refreshTotals($appointment);
            $appointment->loadMissing(['resource', 'service']);

            $this->recordActivity->handle('payment_recorded', appointment: $appointment, actor: $actor, metadata: [
                ...BookAppointment::summary($appointment),
                'amount' => $amount,
                'method' => $payment->method,
                'method_label' => $payment->methodLabel(),
            ]);

            $this->audit->log('appointment.payment_recorded', $appointment, [
                'amount' => $amount,
                'method' => $payment->method,
            ]);

            return $payment;
        });
    }

    /** Recomputes amount_paid from the payment rows (appointment must be locked). */
    public static function refreshTotals(Appointment $appointment): void
    {
        $appointment->forceFill([
            'amount_paid' => number_format((float) AppointmentPayment::query()->where('appointment_id', $appointment->id)->sum('amount'), 2, '.', ''),
        ])->save();
    }
}
