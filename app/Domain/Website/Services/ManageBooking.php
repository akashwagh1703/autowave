<?php

namespace App\Domain\Website\Services;

use App\Domain\Booking\Actions\ChangeAppointmentStatus;
use App\Domain\Booking\Actions\RescheduleAppointment;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Customer self-service for an online booking: view, cancel, reschedule (AW-037).
 * Reuses OnlineBooking slot rules and staff RescheduleAppointment / ChangeAppointmentStatus.
 */
class ManageBooking
{
    public function __construct(
        private readonly OnlineBooking $booking,
        private readonly BookingSettings $settings,
        private readonly RescheduleAppointment $reschedule,
        private readonly ChangeAppointmentStatus $changeStatus,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function props(Appointment $appointment): array
    {
        $appointment->loadMissing(['service:id,name', 'resource:id,name', 'customer:id,name']);

        return [
            'appointment' => [
                'id' => $appointment->id,
                'starts_at' => $appointment->starts_at->toIso8601String(),
                'ends_at' => $appointment->ends_at->toIso8601String(),
                'status' => $appointment->status->value,
                'service' => $appointment->service?->name,
                'resource' => $appointment->resource?->name,
                'customer' => $appointment->customer?->name,
                'can_change' => $this->canChange($appointment),
            ],
            'booking' => $this->booking->isOpen() ? [
                'window' => $this->booking->window(),
                'resource_label' => $this->settings->resourceLabels(),
                'uses_services' => $this->booking->usesServices(),
                'service_id' => $appointment->service_id,
                'resource_id' => $appointment->booking_resource_id,
                'allow_any_resource' => $this->settings->online()['allow_any_resource'],
                'resources' => $this->booking->resources()
                    ->filter(fn ($resource) => ! $appointment->service_id || $resource->services->contains('id', $appointment->service_id))
                    ->map(fn ($resource) => [
                        'id' => $resource->id,
                        'name' => $resource->name,
                    ])->values()->all(),
            ] : null,
            'locale' => [
                'timezone' => TenantTime::timezone(),
                'currency' => $this->context->tenant()->currency,
            ],
        ];
    }

    /** @return list<array{starts_at: string, time: string, price: ?string, price_varies: bool}> */
    public function slots(Appointment $appointment, string $date, ?int $resourceId = null): array
    {
        $this->ensureChangeable($appointment);

        return $this->booking->slots(
            $appointment->service_id,
            $resourceId ?? $appointment->booking_resource_id,
            $date,
        );
    }

    public function cancel(Appointment $appointment): Appointment
    {
        $this->ensureChangeable($appointment);

        return $this->changeStatus->handle($appointment, AppointmentStatus::Cancelled, reason: __('Cancelled by the customer online.'));
    }

    public function move(Appointment $appointment, string $startsAt, ?int $resourceId = null): Appointment
    {
        $this->ensureChangeable($appointment);

        $start = CarbonImmutable::parse($startsAt)->utc()->startOfMinute();
        $window = $this->booking->window();
        $localDate = $start->setTimezone(TenantTime::timezone())->toDateString();
        $earliest = CarbonImmutable::now()->addMinutes($this->settings->online()['min_notice_minutes']);

        if ($start < $earliest || $localDate < $window['first'] || $localDate > $window['last']) {
            throw ValidationException::withMessages(['starts_at' => __('This time can no longer be booked online. Choose another time.')]);
        }

        $targetResource = $resourceId ?? $appointment->booking_resource_id;
        $candidates = $this->booking->resources()
            ->filter(fn ($resource) => ! $appointment->service_id || $resource->services->contains('id', $appointment->service_id))
            ->values();

        if ($resourceId === null && $this->settings->online()['allow_any_resource'] && $candidates->count() > 1) {
            // Keep the current resource when "any" was not chosen explicitly.
            $targetResource = $appointment->booking_resource_id;
        }

        if (! $candidates->contains('id', $targetResource)) {
            throw ValidationException::withMessages(['resource_id' => __('Choose who or what to book.')]);
        }

        return $this->reschedule->handle($appointment, $start, $targetResource);
    }

    public function canChange(Appointment $appointment): bool
    {
        if (! $appointment->status->isActive()) {
            return false;
        }

        $earliest = CarbonImmutable::now()->addMinutes($this->settings->online()['min_notice_minutes']);

        return $appointment->starts_at->toImmutable() >= $earliest;
    }

    private function ensureChangeable(Appointment $appointment): void
    {
        if (! $this->canChange($appointment)) {
            throw ValidationException::withMessages([
                'appointment' => __('This booking can no longer be changed online. Please contact the business.'),
            ]);
        }
    }
}
