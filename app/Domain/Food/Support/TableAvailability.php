<?php

namespace App\Domain\Food\Support;

use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Food\Models\Reservation;
use Carbon\CarbonImmutable;

/**
 * Finds a free table that fits a party for [start, end). Used so online reservations
 * cannot overbook when every suitable table is already held (AW-063).
 */
final class TableAvailability
{
    public static function freeTable(int $partySize, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreReservationId = null): ?DiningTable
    {
        if ($partySize < 1) {
            return null;
        }

        $tables = DiningTable::query()->active()->where('seats', '>=', $partySize)->ordered()->get();

        if ($tables->isEmpty()) {
            return null;
        }

        foreach ($tables as $table) {
            $busy = Reservation::query()
                ->whereIn('status', ReservationStatus::HOLDING)
                ->where('dining_table_id', $table->id)
                ->where('reserved_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->when($ignoreReservationId, fn ($query) => $query->whereKeyNot($ignoreReservationId))
                ->exists();

            if (! $busy) {
                return $table;
            }
        }

        return null;
    }

    /** Whether any suitable table is free for the party at that time. */
    public static function hasCapacity(int $partySize, CarbonImmutable $start, int $durationMinutes, ?int $ignoreReservationId = null): bool
    {
        $end = $start->addMinutes($durationMinutes);

        return self::freeTable($partySize, $start, $end, $ignoreReservationId) !== null;
    }
}
