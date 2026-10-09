<?php

namespace App\Http\Controllers\Website;

use App\Domain\Booking\Models\Appointment;
use App\Domain\Website\Services\ManageBooking;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer self-service page for an online booking (AW-037).
 * The show URL is signed; cancel/reschedule/slots then use the session opened by that visit.
 */
class ManageBookingController extends Controller
{
    public function show(Appointment $appointment, ManageBooking $manage): Response
    {
        session()->put($this->sessionKey($appointment), true);

        return Inertia::render('website/ManageBooking', $manage->props($appointment));
    }

    public function slots(Request $request, Appointment $appointment, ManageBooking $manage): JsonResponse
    {
        $this->authorizeManaged($appointment);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'resource_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'slots' => $manage->slots(
                $appointment,
                $data['date'],
                isset($data['resource_id']) ? (int) $data['resource_id'] : null,
            ),
        ]);
    }

    public function cancel(Appointment $appointment, ManageBooking $manage): RedirectResponse
    {
        $this->authorizeManaged($appointment);
        $manage->cancel($appointment);

        return back()->with('success', __('Your booking has been cancelled.'));
    }

    public function reschedule(Request $request, Appointment $appointment, ManageBooking $manage): RedirectResponse
    {
        $this->authorizeManaged($appointment);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'resource_id' => ['nullable', 'integer'],
        ]);

        $manage->move(
            $appointment,
            $data['starts_at'],
            isset($data['resource_id']) ? (int) $data['resource_id'] : null,
        );

        return back()->with('success', __('Your booking has been updated.'));
    }

    private function authorizeManaged(Appointment $appointment): void
    {
        abort_unless(session()->get($this->sessionKey($appointment)) === true, 403);
    }

    private function sessionKey(Appointment $appointment): string
    {
        return 'manage_booking.'.$appointment->id;
    }
}
