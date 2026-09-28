<?php

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Models\BookingResource;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * Prices a booking without a service from its resource's rates (ADR-020), e.g. a turf slot.
 *
 * - A rate is {label, weekdays (ISO 1-7), from, to (local HH:MM, from < to, to may be 24:00), hourly_rate}.
 * - Every minute of the booking is charged at the first rate covering it (weekday + local time),
 *   otherwise at the resource's hourly_rate. A booking from 17:30 to 18:30 with peak hours from
 *   18:00 costs half an hour at the normal rate and half an hour at the peak rate.
 * - No price (null) when some minute is covered by neither: the team sets the price by hand.
 */
class ResourceRates
{
    /**
     * Validates and normalises rates from a form. Errors are keyed rates.{index}.{field}.
     *
     * @param  array<int, mixed>  $rates
     * @return list<array{label: string, weekdays: list<int>, from: string, to: string, hourly_rate: string}>
     */
    public static function normalize(array $rates): array
    {
        $max = (int) config('booking.pricing.max_rates');

        if (count($rates) > $max) {
            throw ValidationException::withMessages(['rates' => __('Add at most :max rates.', ['max' => $max])]);
        }

        $normalized = [];

        foreach (array_values($rates) as $index => $rate) {
            $rate = is_array($rate) ? $rate : [];
            $label = trim((string) ($rate['label'] ?? ''));
            $weekdays = array_values(array_unique(array_map('intval', (array) ($rate['weekdays'] ?? []))));
            sort($weekdays);
            $from = (string) ($rate['from'] ?? '');
            $to = (string) ($rate['to'] ?? '');
            $amount = $rate['hourly_rate'] ?? null;

            if ($label === '' || mb_strlen($label) > 40) {
                throw ValidationException::withMessages(["rates.{$index}.label" => __('Name the rate (up to 40 characters).')]);
            }

            if ($weekdays === [] || min($weekdays) < 1 || max($weekdays) > 7) {
                throw ValidationException::withMessages(["rates.{$index}.weekdays" => __('Choose the days this rate applies.')]);
            }

            if (! self::isTime($from) || ! ($to === '24:00' || self::isTime($to)) || $from >= $to) {
                throw ValidationException::withMessages(["rates.{$index}.from" => __('Enter a start time before the end time.')]);
            }

            if (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > (float) config('booking.pricing.max_rate')) {
                throw ValidationException::withMessages(["rates.{$index}.hourly_rate" => __('Enter a valid hourly rate.')]);
            }

            $normalized[] = [
                'label' => $label,
                'weekdays' => $weekdays,
                'from' => $from,
                'to' => $to,
                'hourly_rate' => number_format((float) $amount, 2, '.', ''),
            ];
        }

        return $normalized;
    }

    /** The price of [start, end) on the resource, or null when its rates do not cover the whole booking. */
    public static function quote(BookingResource $resource, DateTimeInterface $start, DateTimeInterface $end): ?string
    {
        if (! $resource->hasRates()) {
            return null;
        }

        $cursor = CarbonImmutable::instance($start)->setTimezone(TenantTime::timezone());
        $minutes = (int) max(0, CarbonImmutable::instance($start)->diffInMinutes($end));
        $base = $resource->hourly_rate === null ? null : (string) $resource->hourly_rate;
        $rates = $resource->rates ?? [];
        $perHour = '0';

        for ($i = 0; $i < $minutes; $i++) {
            $minute = $cursor->addMinutes($i);
            $rate = self::rateAt($rates, $minute->isoWeekday(), $minute->format('H:i')) ?? $base;

            if ($rate === null) {
                return null;
            }

            $perHour = bcadd($perHour, $rate, 2);
        }

        return number_format(round((float) bcdiv($perHour, '60', 6), 2), 2, '.', '');
    }

    /**
     * The rates shown to website visitors: the base hourly rate and each named rate.
     *
     * @return array{hourly_rate: ?string, rates: list<array{label: string, weekdays: list<int>, from: string, to: string, hourly_rate: string}>}
     */
    public static function summary(BookingResource $resource): array
    {
        return [
            'hourly_rate' => $resource->hourly_rate === null ? null : (string) $resource->hourly_rate,
            'rates' => array_values($resource->rates ?? []),
        ];
    }

    /** @param  list<array<string, mixed>>  $rates */
    private static function rateAt(array $rates, int $weekday, string $time): ?string
    {
        foreach ($rates as $rate) {
            if (in_array($weekday, $rate['weekdays'] ?? [], true) && $time >= $rate['from'] && $time < $rate['to']) {
                return (string) $rate['hourly_rate'];
            }
        }

        return null;
    }

    private static function isTime(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }
}
