<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Models\TimeOff;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Blocks a period on a resource. Refused when it overlaps pending or confirmed appointments,
 * which must be moved or cancelled first. Runs under the resource lock, like bookings.
 */
class AddTimeOff
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(BookingResource $resource, DateTimeInterface $start, DateTimeInterface $end, ?string $reason = null, ?User $actor = null): TimeOff
    {
        $start = CarbonImmutable::instance($start)->utc();
        $end = CarbonImmutable::instance($end)->utc();

        if ($end <= $start) {
            throw ValidationException::withMessages(['ends_at' => __('The end must be after the start.')]);
        }

        return DB::transaction(function () use ($resource, $start, $end, $reason, $actor) {
            BookingResource::query()->whereKey($resource->id)->lockForUpdate()->first();

            $clashes = Appointment::query()
                ->where('booking_resource_id', $resource->id)
                ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
                ->overlapping($start, $end)
                ->count();

            if ($clashes > 0) {
                throw ValidationException::withMessages([
                    'starts_at' => trans_choice(
                        '{1} This overlaps 1 appointment. Reschedule or cancel it first.|[2,*] This overlaps :count appointments. Reschedule or cancel them first.',
                        $clashes,
                        ['count' => $clashes],
                    ),
                ]);
            }

            $timeOff = TimeOff::query()->create([
                'booking_resource_id' => $resource->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'reason' => $reason,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->audit->log('booking_resource.time_off_added', $resource, [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'reason' => $reason,
            ]);

            return $timeOff;
        });
    }
}
