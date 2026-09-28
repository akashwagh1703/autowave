<?php

namespace App\Http\Presenters;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\AppointmentPayment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Models\TimeOff;
use App\Domain\Booking\Models\WorkingHour;
use App\Domain\Service\Models\Service;
use App\Domain\Service\Models\ServiceCategory;

/**
 * Browser-safe shapes for service and booking pages. Only fields listed here reach the frontend.
 * Timestamps are ISO-8601 UTC; working hours are tenant-local "HH:MM".
 */
final class BookingPresenter
{
    /** @return array<string, mixed> */
    public static function service(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'duration_minutes' => $service->duration_minutes,
            'price' => $service->price,
            'is_active' => $service->is_active,
            'service_category_id' => $service->service_category_id,
            'category' => $service->relationLoaded('category') && $service->category
                ? ['id' => $service->category->id, 'name' => $service->category->name]
                : null,
            'resource_ids' => $service->relationLoaded('resources') ? $service->resources->modelKeys() : null,
            'resources_count' => $service->resources_count ?? null,
            'deleted' => $service->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    public static function category(ServiceCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'services_count' => $category->services_count ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public static function resource(BookingResource $resource): array
    {
        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'description' => $resource->description,
            'color' => $resource->color,
            'hourly_rate' => $resource->hourly_rate,
            'rates' => array_values($resource->rates ?? []),
            'is_active' => $resource->is_active,
            'tenant_user_id' => $resource->tenant_user_id,
            'member' => $resource->relationLoaded('member') && $resource->member
                ? ['id' => $resource->member->id, 'name' => $resource->member->user?->name]
                : null,
            'service_ids' => $resource->relationLoaded('services') ? $resource->services->modelKeys() : null,
            'working_hours' => $resource->relationLoaded('workingHours')
                ? $resource->workingHours->map(fn (WorkingHour $hour) => self::workingHour($hour))->values()->all()
                : null,
            'deleted' => $resource->trashed(),
        ];
    }

    /** @return array{weekday: int, starts_at: string, ends_at: string} */
    public static function workingHour(WorkingHour $hour): array
    {
        return ['weekday' => $hour->weekday, 'starts_at' => $hour->startTime(), 'ends_at' => $hour->endTime()];
    }

    /** @return array<string, mixed> */
    public static function payment(AppointmentPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (string) $payment->amount,
            'method' => $payment->method,
            'method_label' => $payment->methodLabel(),
            'reference' => $payment->reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'recorded_by' => $payment->relationLoaded('recorder') && $payment->recorder ? $payment->recorder->name : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function timeOff(TimeOff $timeOff): array
    {
        return [
            'id' => $timeOff->id,
            'starts_at' => $timeOff->starts_at->toIso8601String(),
            'ends_at' => $timeOff->ends_at->toIso8601String(),
            'reason' => $timeOff->reason,
        ];
    }

    /** @return array<string, mixed> */
    public static function appointment(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'duration_minutes' => $appointment->durationMinutes(),
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'is_active' => $appointment->status->isActive(),
            'price' => $appointment->price,
            'amount_paid' => $appointment->amount_paid ?? '0.00',
            'balance' => $appointment->balance(),
            'payments' => $appointment->relationLoaded('payments')
                ? $appointment->payments->map(fn (AppointmentPayment $payment) => self::payment($payment))->values()->all()
                : null,
            'notes' => $appointment->notes,
            'source' => $appointment->source,
            'cancellation_reason' => $appointment->cancellation_reason,
            'confirmed_at' => $appointment->confirmed_at?->toIso8601String(),
            'completed_at' => $appointment->completed_at?->toIso8601String(),
            'cancelled_at' => $appointment->cancelled_at?->toIso8601String(),
            'no_show_at' => $appointment->no_show_at?->toIso8601String(),
            'created_at' => $appointment->created_at?->toIso8601String(),
            'booking_resource_id' => $appointment->booking_resource_id,
            'service_id' => $appointment->service_id,
            'customer_id' => $appointment->customer_id,
            'customer' => $appointment->relationLoaded('customer') && $appointment->customer
                ? [
                    'id' => $appointment->customer->id,
                    'name' => $appointment->customer->name,
                    'phone' => $appointment->customer->phone,
                    'deleted' => $appointment->customer->trashed(),
                ]
                : null,
            'resource' => $appointment->relationLoaded('resource') && $appointment->resource
                ? [
                    'id' => $appointment->resource->id,
                    'name' => $appointment->resource->name,
                    'color' => $appointment->resource->color,
                    'deleted' => $appointment->resource->trashed(),
                ]
                : null,
            'service' => $appointment->relationLoaded('service') && $appointment->service
                ? ['id' => $appointment->service->id, 'name' => $appointment->service->name, 'deleted' => $appointment->service->trashed()]
                : null,
            'creator' => $appointment->relationLoaded('creator') && $appointment->creator
                ? ['id' => $appointment->creator->id, 'name' => $appointment->creator->name]
                : null,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function statuses(): array
    {
        return collect(AppointmentStatus::cases())
            ->map(fn (AppointmentStatus $status) => ['value' => $status->value, 'label' => $status->label()])
            ->all();
    }
}
