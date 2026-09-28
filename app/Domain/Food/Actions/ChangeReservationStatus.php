<?php

namespace App\Domain\Food\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Food\Events\ReservationCancelled;
use App\Domain\Food\Events\ReservationConfirmed;
use App\Domain\Food\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a reservation through its lifecycle (ReservationStatus::allowedTransitions). Seating,
 * completing and no-shows need the reservation time to have come (seating up to an hour early).
 * Each change is recorded on the customer's timeline; confirmed and cancelled fire automations.
 */
class ChangeReservationStatus
{
    public const EARLY_SEATING_MINUTES = 60;

    public function __construct(private readonly RecordActivity $recordActivity) {}

    public function handle(Reservation $reservation, ReservationStatus $status, ?User $actor = null, ?string $reason = null): Reservation
    {
        $this->ensureAllowed($reservation, $status);

        return DB::transaction(function () use ($reservation, $status, $actor, $reason) {
            $fresh = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->ensureAllowed($fresh, $status);

            $reservation->forceFill([
                'status' => $status,
                ...match ($status) {
                    ReservationStatus::Confirmed => ['confirmed_at' => now()],
                    ReservationStatus::Seated => ['seated_at' => now(), 'confirmed_at' => $reservation->confirmed_at ?? now()],
                    ReservationStatus::Completed => ['completed_at' => now()],
                    ReservationStatus::Cancelled => ['cancelled_at' => now(), 'cancellation_reason' => filled($reason) ? trim($reason) : null],
                    default => [],
                },
            ])->save();

            $reservation->loadMissing(['customer', 'table']);

            $this->recordActivity->handle(
                'reservation_'.$status->value,
                customer: $reservation->customer,
                actor: $actor,
                body: $status === ReservationStatus::Cancelled && filled($reason) ? trim($reason) : null,
                metadata: BookReservation::summary($reservation),
            );

            match ($status) {
                ReservationStatus::Confirmed => ReservationConfirmed::dispatch($reservation),
                ReservationStatus::Cancelled => ReservationCancelled::dispatch($reservation),
                default => null,
            };

            return $reservation;
        });
    }

    private function ensureAllowed(Reservation $reservation, ReservationStatus $status): void
    {
        if (! $reservation->status->canTransitionTo($status)) {
            throw ValidationException::withMessages(['status' => __('A :from reservation cannot be marked :to.', [
                'from' => mb_strtolower($reservation->status->label()),
                'to' => mb_strtolower($status->label()),
            ])]);
        }

        $early = $reservation->reserved_at->copy()->subMinutes(self::EARLY_SEATING_MINUTES);

        if (in_array($status, [ReservationStatus::Seated, ReservationStatus::NoShow], true) && $early->isFuture()) {
            throw ValidationException::withMessages(['status' => __('This can be done once the reservation time is near.')]);
        }
    }
}
