<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Models\TimeOff;
use App\Domain\Booking\Models\WorkingHour;
use App\Domain\Booking\Support\BookingSettings;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * When a resource can be booked. Working hours are local wall-clock times in the tenant's
 * timezone; appointments and time off are stored in UTC. Blocking appointments (pending,
 * confirmed, completed) and time off make a period unavailable.
 *
 * This is the friendly pre-check. The appointments_no_overlap constraint is the guarantee.
 */
class Availability
{
    public function __construct(private readonly BookingSettings $settings) {}

    /**
     * Bookable start times on a local date (Y-m-d), stepping by the tenant's slot interval inside
     * each working window. Past times are excluded.
     *
     * @return list<array{starts_at: string, ends_at: string, time: string}>
     */
    public function slots(BookingResource $resource, string $date, int $durationMinutes, ?int $ignoreAppointmentId = null): array
    {
        if (! $resource->is_active || $durationMinutes <= 0) {
            return [];
        }

        $timezone = TenantTime::timezone();
        $day = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $windows = $this->windowsOn($resource, $day);

        if ($windows === []) {
            return [];
        }

        $busy = $this->busyPeriods($resource, $day->utc(), $day->addDay()->utc(), $ignoreAppointmentId);
        $interval = $this->settings->slotInterval();
        $now = CarbonImmutable::now();
        $slots = [];

        foreach ($windows as [$windowStart, $windowEnd]) {
            for ($start = $windowStart; $start->addMinutes($durationMinutes) <= $windowEnd; $start = $start->addMinutes($interval)) {
                $end = $start->addMinutes($durationMinutes);

                if ($start < $now || $this->overlapsAny($busy, $start, $end)) {
                    continue;
                }

                $slots[$start->getTimestamp()] = [
                    'starts_at' => $start->utc()->toIso8601String(),
                    'ends_at' => $end->utc()->toIso8601String(),
                    'time' => $start->format('H:i'),
                ];
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * Why [start, end) cannot be booked on this resource, or null when it can.
     */
    public function problem(
        BookingResource $resource,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?int $ignoreAppointmentId = null,
        bool $allowOutsideHours = false,
    ): ?string {
        $start = CarbonImmutable::instance($start)->utc();
        $end = CarbonImmutable::instance($end)->utc();

        if ($end <= $start) {
            return __('The end time must be after the start time.');
        }

        if (! $resource->is_active || $resource->trashed()) {
            return __(':name is not taking bookings.', ['name' => $resource->name]);
        }

        if (! $allowOutsideHours && ! $this->withinWorkingHours($resource, $start, $end)) {
            return __('This time is outside :name\'s working hours.', ['name' => $resource->name]);
        }

        if (TimeOff::query()->where('booking_resource_id', $resource->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
            return __(':name is unavailable (time off) at this time.', ['name' => $resource->name]);
        }

        $clash = Appointment::query()
            ->where('booking_resource_id', $resource->id)
            ->blocking()
            ->overlapping($start, $end)
            ->when($ignoreAppointmentId, fn ($query) => $query->whereKeyNot($ignoreAppointmentId))
            ->exists();

        return $clash ? __('This time is already booked for :name.', ['name' => $resource->name]) : null;
    }

    public function withinWorkingHours(BookingResource $resource, DateTimeInterface $start, DateTimeInterface $end): bool
    {
        $timezone = TenantTime::timezone();
        $localStart = CarbonImmutable::instance($start)->setTimezone($timezone);
        $localEnd = CarbonImmutable::instance($end)->setTimezone($timezone);

        foreach ($this->windowsOn($resource, $localStart->startOfDay()) as [$windowStart, $windowEnd]) {
            if ($localStart >= $windowStart && $localEnd <= $windowEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * Working windows on a local day as absolute instants (DST-safe: built from the local date).
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function windowsOn(BookingResource $resource, CarbonImmutable $localDay): array
    {
        $hours = $resource->relationLoaded('workingHours') ? $resource->workingHours : $resource->workingHours()->get();

        return $hours
            ->filter(fn (WorkingHour $hour) => $hour->weekday === $localDay->isoWeekday())
            ->map(fn (WorkingHour $hour) => [
                $localDay->setTimeFromTimeString($hour->startTime()),
                $localDay->setTimeFromTimeString($hour->endTime()),
            ])
            ->sortBy(fn (array $window) => $window[0]->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * Blocking appointments and time off overlapping [from, to), as UTC periods.
     *
     * @return Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function busyPeriods(BookingResource $resource, DateTimeInterface $from, DateTimeInterface $to, ?int $ignoreAppointmentId = null): Collection
    {
        $appointments = Appointment::query()
            ->where('booking_resource_id', $resource->id)
            ->blocking()
            ->overlapping($from, $to)
            ->when($ignoreAppointmentId, fn ($query) => $query->whereKeyNot($ignoreAppointmentId))
            ->get(['starts_at', 'ends_at']);

        $timeOff = TimeOff::query()
            ->where('booking_resource_id', $resource->id)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at']);

        return $appointments->toBase()->merge($timeOff)
            ->map(fn ($period) => [CarbonImmutable::instance($period->starts_at), CarbonImmutable::instance($period->ends_at)])
            ->values();
    }

    /**
     * @param  Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     */
    private function overlapsAny(Collection $busy, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $busy->contains(fn (array $period) => $period[0] < $end && $period[1] > $start);
    }
}
