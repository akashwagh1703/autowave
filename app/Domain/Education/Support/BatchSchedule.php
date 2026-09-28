<?php

namespace App\Domain\Education\Support;

use App\Domain\Education\Models\Batch;
use Carbon\CarbonImmutable;

/** Human-readable batch schedules, e.g. "Mon, Wed, Fri · 5:00 PM–6:30 PM". */
final class BatchSchedule
{
    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public static function describe(Batch $batch): string
    {
        $weekdays = array_map('intval', $batch->weekdays ?? []);
        $days = match (true) {
            count($weekdays) === 7 => 'Every day',
            $weekdays === [1, 2, 3, 4, 5] => 'Mon–Fri',
            default => implode(', ', array_map(fn (int $day) => self::DAYS[$day] ?? '', $weekdays)),
        };

        $time = $batch->startTime()
            ? self::time($batch->startTime()).($batch->endTime() ? '–'.self::time($batch->endTime()) : '')
            : '';

        return trim(implode(' · ', array_filter([$days, $time])));
    }

    private static function time(string $hhmm): string
    {
        return CarbonImmutable::createFromFormat('H:i', $hhmm)->format('g:i A');
    }
}
