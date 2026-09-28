<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Booking\Actions\ChangeAppointmentStatus;
use App\Domain\Booking\Actions\RescheduleAppointment;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Http\Controllers\Controller;
use App\Support\TenantTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Lifecycle actions on existing appointments: status changes, reschedules and bulk updates.
 * Cancelling needs `appointments.cancel`; everything else `appointments.update`.
 */
class AppointmentActionController extends Controller
{
    public const BULK_LIMIT = 100;

    private const STATUS_TARGETS = ['confirmed', 'completed', 'cancelled', 'no_show'];

    public function status(Request $request, Appointment $appointment, ChangeAppointmentStatus $changeStatus): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUS_TARGETS)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = AppointmentStatus::from($validated['status']);
        Gate::authorize($status === AppointmentStatus::Cancelled ? 'appointments.cancel' : 'appointments.update');

        $changeStatus->handle($appointment, $status, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', match ($status) {
            AppointmentStatus::Confirmed => __('Appointment confirmed.'),
            AppointmentStatus::Completed => __('Appointment marked as completed.'),
            AppointmentStatus::Cancelled => __('Appointment cancelled.'),
            AppointmentStatus::NoShow => __('Appointment marked as a no-show.'),
            AppointmentStatus::Pending => __('Appointment updated.'),
        });
    }

    public function reschedule(Request $request, Appointment $appointment, RescheduleAppointment $reschedule): RedirectResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'booking_resource_id' => ['nullable', 'integer'],
            'duration_minutes' => ['nullable', 'integer', 'min:'.config('booking.duration.min'), 'max:'.config('booking.duration.max')],
            'allow_outside_hours' => ['nullable', 'boolean'],
        ]);

        $reschedule->handle(
            $appointment,
            TenantTime::parse($validated['starts_at']),
            isset($validated['booking_resource_id']) ? (int) $validated['booking_resource_id'] : null,
            isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null,
            $request->user(),
            (bool) ($validated['allow_outside_hours'] ?? false),
        );

        return back()->with('success', __('Appointment rescheduled.'));
    }

    /**
     * Applies a status to each selected appointment that allows it; the rest are skipped and
     * counted, so one ineligible row never blocks the others.
     */
    public function bulk(Request $request, ChangeAppointmentStatus $changeStatus, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(self::STATUS_TARGETS)],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'ids.*' => ['integer', 'distinct'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = AppointmentStatus::from($validated['action']);
        Gate::authorize($status === AppointmentStatus::Cancelled ? 'appointments.cancel' : 'appointments.update');

        // Tenant-scoped: ids from another tenant simply do not match.
        $appointments = Appointment::query()->whereKey($validated['ids'])->orderBy('id')->get();
        $eligible = $appointments->filter(fn (Appointment $appointment) => $appointment->status->canTransitionTo($status)
            && (! $status->requiresStart() || $appointment->starts_at->isPast()));

        foreach ($eligible as $appointment) {
            $changeStatus->handle($appointment, $status, $request->user(), $validated['reason'] ?? null);
        }

        $audit->log("appointments.bulk_{$status->value}", null, ['appointment_ids' => $eligible->modelKeys()]);

        $skipped = $appointments->count() - $eligible->count();
        $message = trans_choice('{0} No appointments could be updated.|{1} :count appointment updated.|[2,*] :count appointments updated.', $eligible->count(), ['count' => $eligible->count()]);

        if ($skipped > 0) {
            $message .= ' '.trans_choice('{1} :count skipped (not eligible).|[2,*] :count skipped (not eligible).', $skipped, ['count' => $skipped]);
        }

        return back()->with($eligible->isEmpty() ? 'error' : 'success', $message);
    }
}
