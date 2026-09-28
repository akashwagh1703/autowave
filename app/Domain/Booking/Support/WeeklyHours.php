<?php

namespace App\Domain\Booking\Support;

use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises weekly working windows: ISO weekday 1–7, "HH:MM" local times,
 * end after start, no overlapping windows on the same day, a bounded number per day.
 */
final class WeeklyHours
{
    /**
     * @param  array<int, mixed>  $rows
     * @return list<array{weekday: int, starts_at: string, ends_at: string}> sorted by weekday then start
     *
     * @throws ValidationException
     */
    public static function normalize(array $rows, string $field = 'working_hours'): array
    {
        $windows = [];

        foreach (array_values($rows) as $index => $row) {
            $weekday = is_array($row) ? filter_var($row['weekday'] ?? null, FILTER_VALIDATE_INT) : false;
            $start = is_array($row) ? self::time($row['starts_at'] ?? null) : null;
            $end = is_array($row) ? self::time($row['ends_at'] ?? null) : null;

            if ($weekday === false || $weekday < 1 || $weekday > 7 || ! $start || ! $end) {
                throw ValidationException::withMessages(["{$field}.{$index}" => __('Enter a valid day and times (HH:MM).')]);
            }

            if ($end <= $start) {
                throw ValidationException::withMessages(["{$field}.{$index}" => __('The closing time must be after the opening time.')]);
            }

            $windows[] = ['weekday' => $weekday, 'starts_at' => $start, 'ends_at' => $end];
        }

        usort($windows, fn (array $a, array $b) => [$a['weekday'], $a['starts_at']] <=> [$b['weekday'], $b['starts_at']]);

        $perDay = [];

        foreach ($windows as $index => $window) {
            $previous = $windows[$index - 1] ?? null;

            if ($previous && $previous['weekday'] === $window['weekday'] && $window['starts_at'] < $previous['ends_at']) {
                throw ValidationException::withMessages([$field => __('Working hours on the same day cannot overlap.')]);
            }

            $perDay[$window['weekday']] = ($perDay[$window['weekday']] ?? 0) + 1;

            if ($perDay[$window['weekday']] > config('booking.max_windows_per_day')) {
                throw ValidationException::withMessages([$field => __('Use at most :max time ranges per day.', ['max' => config('booking.max_windows_per_day')])]);
            }
        }

        return $windows;
    }

    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $value, $matches)) {
            return null;
        }

        return $matches[1].':'.$matches[2];
    }
}
