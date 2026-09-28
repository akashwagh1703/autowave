<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\BookingResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Soft delete. Refused while the resource has upcoming pending or confirmed appointments:
 * those must be moved or cancelled first so no customer is silently dropped.
 */
class DeleteBookingResource
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(BookingResource $resource): void
    {
        DB::transaction(function () use ($resource) {
            BookingResource::query()->whereKey($resource->id)->lockForUpdate()->first();

            $upcoming = $resource->appointments()
                ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
                ->where('ends_at', '>', now())
                ->count();

            if ($upcoming > 0) {
                throw ValidationException::withMessages([
                    'resource' => trans_choice(
                        '{1} :name has 1 upcoming appointment. Reschedule or cancel it first.|[2,*] :name has :count upcoming appointments. Reschedule or cancel them first.',
                        $upcoming,
                        ['name' => $resource->name, 'count' => $upcoming],
                    ),
                ]);
            }

            $resource->services()->detach();
            $resource->delete();

            $this->audit->log('booking_resource.deleted', $resource, ['name' => $resource->name]);
        });
    }
}
