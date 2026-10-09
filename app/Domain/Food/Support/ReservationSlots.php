<?php

namespace App\Domain\Food\Support;

use App\Support\TenantTime;
use Carbon\CarbonImmutable;

/**
 * Reservation start times guests can pick on the website: every `slot_interval` minutes from `opens`
 * to before `closes` (tenant-local), at least `min_notice_minutes` ahead and within `max_days_ahead`.
 * When a party size is given, times with no free fitting table are left out (AW-063).
 */
final class ReservationSlots
{
    /**
     * @param  array<string, mixed>  $settings  FoodSettings::reservations()
     * @return list<array{starts_at: string, time: string}>
     */
    public static function forDate(string $date, array $settings, ?int $partySize = null): array
    {
        $timezone = TenantTime::timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        if (! $day || $day < $today || $day > $today->addDays($settings['max_days_ahead'])) {
            return [];
        }

        $cursor = $day->setTimeFromTimeString($settings['opens']);
        $closes = $day->setTimeFromTimeString($settings['closes']);
        $earliest = CarbonImmutable::now()->addMinutes($settings['min_notice_minutes']);
        $duration = (int) $settings['duration_minutes'];
        $slots = [];

        while ($cursor < $closes && count($slots) < 200) {
            if ($cursor >= $earliest) {
                $start = $cursor->utc();

                if ($partySize === null || TableAvailability::hasCapacity($partySize, $start, $duration)) {
                    $slots[] = ['starts_at' => $start->toIso8601String(), 'time' => $cursor->format('H:i')];
                }
            }

            $cursor = $cursor->addMinutes($settings['slot_interval']);
        }

        return $slots;
    }

    /** @param  array<string, mixed>  $settings */
    public static function isBookable(CarbonImmutable $start, array $settings, ?int $partySize = null): bool
    {
        $local = $start->setTimezone(TenantTime::timezone());

        return in_array(
            $start->utc()->toIso8601String(),
            array_column(self::forDate($local->toDateString(), $settings, $partySize), 'starts_at'),
            true,
        );
    }
}
