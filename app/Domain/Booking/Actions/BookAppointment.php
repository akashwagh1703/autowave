<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Events\AppointmentConfirmed;
use App\Domain\Booking\Events\AppointmentCreated;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Services\Availability;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Booking\Support\ResourceRates;
use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\OnlineSource;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books a resource for a customer (ADR-014). Double-booking protection, in order:
 * 1. the resource row is locked (SELECT … FOR UPDATE), serialising bookings per resource;
 * 2. availability is re-checked under the lock;
 * 3. the appointments_no_overlap exclusion constraint rejects anything that still slips through.
 */
class BookAppointment
{
    public const OVERLAP_CONSTRAINT = 'appointments_no_overlap';

    public function __construct(
        private readonly TenantContext $context,
        private readonly BookingSettings $settings,
        private readonly Availability $availability,
        private readonly CreateCustomer $createCustomer,
        private readonly RecordActivity $recordActivity,
    ) {}

    /**
     * @param  array{
     *     customer_id?: ?int,
     *     customer?: ?array{name: string, phone?: ?string, email?: ?string},
     *     booking_resource_id: int,
     *     service_id?: ?int,
     *     starts_at: DateTimeInterface,
     *     duration_minutes?: ?int,
     *     price?: numeric-string|float|int|null,
     *     notes?: ?string,
     *     status?: ?string,
     *     source?: ?string,
     *     allow_outside_hours?: bool,
     * }  $data  starts_at is an absolute instant (convert tenant-local input with TenantTime::parse)
     */
    public function handle(array $data, ?User $actor = null): Appointment
    {
        $resource = BookingResource::query()->find($data['booking_resource_id'] ?? null)
            ?? throw ValidationException::withMessages(['booking_resource_id' => __('Choose who or what to book.')]);

        $service = $this->resolveService($data['service_id'] ?? null, $resource);
        $duration = (int) ($data['duration_minutes'] ?? $service?->duration_minutes ?? 0);
        self::ensureDuration($duration);

        $start = CarbonImmutable::instance($data['starts_at'])->utc()->startOfMinute();
        $end = $start->addMinutes($duration);

        if ($start < CarbonImmutable::now()->startOfMinute()) {
            throw ValidationException::withMessages(['starts_at' => __('Choose a time in the future.')]);
        }

        $status = $this->initialStatus($data['status'] ?? null);

        try {
            return DB::transaction(function () use ($data, $actor, $resource, $service, $start, $end, $status) {
                $customer = $this->resolveCustomer($data, $actor);

                $locked = BookingResource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();

                if ($problem = $this->availability->problem($locked, $start, $end, allowOutsideHours: (bool) ($data['allow_outside_hours'] ?? false))) {
                    throw ValidationException::withMessages(['starts_at' => $problem]);
                }

                $appointment = Appointment::query()->create([
                    'customer_id' => $customer->id,
                    'booking_resource_id' => $locked->id,
                    'service_id' => $service?->id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'status' => $status,
                    'price' => $data['price'] ?? $service?->price ?? ResourceRates::quote($locked, $start, $end),
                    'notes' => $data['notes'] ?? null,
                    'source' => $data['source'] ?? 'manual',
                    'confirmed_at' => $status === AppointmentStatus::Confirmed ? now() : null,
                    'created_by_user_id' => $actor?->id,
                ]);

                $appointment->setRelations(['customer' => $customer, 'resource' => $locked, 'service' => $service]);

                $this->recordActivity->handle('appointment_booked', appointment: $appointment, actor: $actor, metadata: [
                    ...self::summary($appointment),
                    ...($appointment->source !== 'manual' ? ['source' => $appointment->source] : []),
                ]);

                AppointmentCreated::dispatch($appointment);

                if ($status === AppointmentStatus::Confirmed) {
                    AppointmentConfirmed::dispatch($appointment);
                }

                return $appointment;
            });
        } catch (QueryException $exception) {
            throw self::isOverlap($exception)
                ? ValidationException::withMessages(['starts_at' => __('This time was just booked. Choose another time.')])
                : $exception;
        }
    }

    public static function isOverlap(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? $exception->getCode()) === '23P01'
            && str_contains($exception->getMessage(), self::OVERLAP_CONSTRAINT);
    }

    public static function ensureDuration(int $minutes): void
    {
        $min = (int) config('booking.duration.min');
        $max = (int) config('booking.duration.max');

        if ($minutes < $min || $minutes > $max) {
            throw ValidationException::withMessages(['duration_minutes' => __('The duration must be between :min and :max minutes.', ['min' => $min, 'max' => $max])]);
        }
    }

    /**
     * Timeline metadata describing an appointment at the time of the entry.
     *
     * @return array<string, mixed>
     */
    public static function summary(Appointment $appointment): array
    {
        return [
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'resource' => $appointment->resource?->name,
            'service' => $appointment->service?->name,
        ];
    }

    private function resolveService(mixed $serviceId, BookingResource $resource): ?Service
    {
        if (! $serviceId) {
            return null;
        }

        if (! $this->context->hasEngine('service')) {
            throw ValidationException::withMessages(['service_id' => __('Services are not enabled for this business.')]);
        }

        $service = Service::query()->active()->find($serviceId)
            ?? throw ValidationException::withMessages(['service_id' => __('Choose a valid service.')]);

        if (! $resource->offers($service)) {
            throw ValidationException::withMessages(['service_id' => __(':resource does not offer :service.', ['resource' => $resource->name, 'service' => $service->name])]);
        }

        return $service;
    }

    /**
     * An existing customer by id, or the inline customer: reused when the phone number matches a
     * customer, otherwise created.
     */
    private function resolveCustomer(array $data, ?User $actor): Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::query()->find($data['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Choose a valid customer.')]);
        }

        $inline = $data['customer'] ?? null;

        if (! is_array($inline) || blank($inline['name'] ?? null)) {
            throw ValidationException::withMessages(['customer_id' => __('Choose a customer or enter their details.')]);
        }

        return CreateCustomer::duplicateOf($inline['phone'] ?? null)
            ?? $this->createCustomer->handle([
                'name' => $inline['name'],
                'phone' => $inline['phone'] ?? null,
                'email' => $inline['email'] ?? null,
            ], $actor, ['via' => OnlineSource::is($data['source'] ?? null) ? 'online_booking' : 'booking']);
    }

    private function initialStatus(?string $requested): AppointmentStatus
    {
        $status = $requested ? AppointmentStatus::tryFrom($requested) : null;

        if ($status && ! $status->isActive()) {
            throw ValidationException::withMessages(['status' => __('A new appointment must be pending or confirmed.')]);
        }

        return $status ?? ($this->settings->autoConfirm() ? AppointmentStatus::Confirmed : AppointmentStatus::Pending);
    }
}
