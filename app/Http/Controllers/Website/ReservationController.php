<?php

namespace App\Http\Controllers\Website;

use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Services\OnlineReservations;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Table reservation requests from the public website (the reservation section). */
class ReservationController extends Controller
{
    public function slots(Request $request, OnlineReservations $reservations): JsonResponse
    {
        abort_unless($reservations->isOpen(), 404);

        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['slots' => $reservations->slots($data['date'])]);
    }

    public function store(Request $request, TenantContext $context, OnlineReservations $reservations): RedirectResponse
    {
        abort_unless($reservations->isOpen(), 404);

        if (filled($request->input(EnquiryController::HONEYPOT))) {
            Log::info('website.reservation_honeypot', ['tenant_id' => $context->id()]);

            return back();
        }

        $reservation = $reservations->book($request->only(['name', 'phone', 'email', 'party_size', 'starts_at', 'notes']));

        return back()->with('reservation_confirmation', [
            'reserved_at' => $reservation->reserved_at->toIso8601String(),
            'party_size' => $reservation->party_size,
            'status' => $reservation->status->value,
        ]);
    }
}
