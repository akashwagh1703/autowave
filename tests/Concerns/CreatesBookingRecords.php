<?php

namespace Tests\Concerns;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Actions\SaveBookingResource;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Service\Actions\SaveService;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Booking fixtures. Times are tenant-local wall-clock strings ("2026-10-06 11:00"), converted
 * with the tenant's timezone. Use with CreatesCrmRecords and CreatesTenants.
 */
trait CreatesBookingRecords
{
    /** Monday 5 October 2026, 10:00 in India. */
    protected function travelToBookingDay(string $local = '2026-10-05 10:00', string $timezone = 'Asia/Kolkata'): void
    {
        $this->travelTo(CarbonImmutable::parse($local, $timezone));
    }

    protected function local(Tenant $tenant, string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $tenant->timezone)->utc();
    }

    /** @return list<array{weekday: int, starts_at: string, ends_at: string}> */
    protected function hours(string $from = '09:00', string $to = '18:00', array $weekdays = [1, 2, 3, 4, 5, 6, 7]): array
    {
        return array_map(fn (int $weekday) => ['weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to], $weekdays);
    }

    /**
     * A resource open 09:00–18:00 every day unless other hours are given.
     *
     * @param  array<string, mixed>  $data
     */
    protected function makeResource(Tenant $tenant, array $data = [], ?array $hours = null): BookingResource
    {
        static $sequence = 0;
        $sequence++;

        return $this->inTenant($tenant, fn () => app(SaveBookingResource::class)->handle([
            'name' => 'Resource '.$sequence,
            'working_hours' => $hours ?? $this->hours(),
            ...$data,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<BookingResource>  $resources
     */
    protected function makeService(Tenant $tenant, array $data = [], array $resources = []): Service
    {
        static $sequence = 0;
        $sequence++;

        return $this->inTenant($tenant, fn () => app(SaveService::class)->handle([
            'name' => 'Service '.$sequence,
            'duration_minutes' => 60,
            'price' => 500,
            'resource_ids' => array_map(fn (BookingResource $resource) => $resource->id, $resources),
            ...$data,
        ]));
    }

    /**
     * Books through BookAppointment. Without a service the appointment lasts 60 minutes; without a
     * customer a new one is created.
     *
     * @param  array<string, mixed>  $data
     */
    protected function book(Tenant $tenant, BookingResource $resource, string $localStart, array $data = [], ?User $actor = null): Appointment
    {
        if (! isset($data['customer']) && ! isset($data['customer_id'])) {
            $data['customer_id'] = $this->makeCustomer($tenant)->id;
        }

        if (! isset($data['service_id']) && ! isset($data['duration_minutes'])) {
            $data['duration_minutes'] = 60;
        }

        return $this->inTenant($tenant, fn () => app(BookAppointment::class)->handle([
            'booking_resource_id' => $resource->id,
            'starts_at' => $this->local($tenant, $localStart),
            ...$data,
        ], $actor));
    }
}
