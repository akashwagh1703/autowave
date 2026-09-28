<?php

namespace App\Domain\Food\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Food\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a live reservation's time, length, party size, table or notes. Assigning a table that is
 * already booked for the time is refused by the database (reservations_no_overlap).
 */
class UpdateReservation
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    /**
     * @param  array{reserved_at: DateTimeInterface, duration_minutes: int, party_size: int, dining_table_id?: ?int, notes?: ?string}  $data
     */
    public function handle(Reservation $reservation, array $data, ?User $actor = null): Reservation
    {
        if (! $reservation->status->holdsTable()) {
            throw ValidationException::withMessages(['reserved_at' => __('Only pending, confirmed or seated reservations can be changed.')]);
        }

        $start = CarbonImmutable::instance($data['reserved_at'])->utc()->startOfMinute();
        $duration = (int) $data['duration_minutes'];
        $party = (int) $data['party_size'];

        if ($duration < 15 || $duration > 720) {
            throw ValidationException::withMessages(['duration_minutes' => __('Choose a duration between 15 minutes and 12 hours.')]);
        }

        if ($party < 1 || $party > (int) config('food.max_party_size_limit')) {
            throw ValidationException::withMessages(['party_size' => __('Enter the number of guests.')]);
        }

        $tableId = ! empty($data['dining_table_id']) ? (int) $data['dining_table_id'] : null;
        $table = null;

        if ($tableId !== null && $tableId !== $reservation->dining_table_id) {
            $table = DiningTable::query()->active()->find($tableId)
                ?? throw ValidationException::withMessages(['dining_table_id' => __('Choose an active table.')]);
        }

        $previousTable = $reservation->dining_table_id;

        try {
            DB::transaction(function () use ($reservation, $data, $start, $duration, $party, $tableId) {
                $reservation->update([
                    'reserved_at' => $start,
                    'ends_at' => $start->addMinutes($duration),
                    'party_size' => $party,
                    'dining_table_id' => $tableId,
                    'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                ]);
            });
        } catch (QueryException $exception) {
            if (BookReservation::isOverlap($exception)) {
                throw ValidationException::withMessages(['dining_table_id' => __('This table is already booked at that time.')]);
            }

            throw $exception;
        }

        $reservation->unsetRelation('table')->load(['customer', 'table']);

        if ($tableId !== null && $tableId !== $previousTable && $table) {
            $this->recordActivity->handle('reservation_table_assigned', customer: $reservation->customer, actor: $actor, metadata: BookReservation::summary($reservation));
        }

        return $reservation;
    }
}
