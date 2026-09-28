<?php

namespace App\Http\Controllers\Website;

use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Services\OnlineBooking;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Online booking from the public website (the booking section). */
class BookingController extends Controller
{
    public function slots(Request $request, OnlineBooking $booking): JsonResponse
    {
        $this->ensureOpen($booking);

        $data = $request->validate([
            'service_id' => ['nullable', 'integer'],
            'resource_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'slots' => $booking->slots(
                isset($data['service_id']) ? (int) $data['service_id'] : null,
                isset($data['resource_id']) ? (int) $data['resource_id'] : null,
                $data['date'],
            ),
        ]);
    }

    public function store(Request $request, TenantContext $context, OnlineBooking $booking): RedirectResponse
    {
        $this->ensureOpen($booking);

        if (filled($request->input(EnquiryController::HONEYPOT))) {
            Log::info('website.booking_honeypot', ['tenant_id' => $context->id()]);

            return back();
        }

        $appointment = $booking->book($request->only(['service_id', 'resource_id', 'starts_at', 'name', 'phone', 'email', 'notes']));

        return back()->with('booking_confirmation', [
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'service' => $appointment->service?->name,
            'resource' => $appointment->resource?->name,
            'status' => $appointment->status->value,
        ]);
    }

    private function ensureOpen(OnlineBooking $booking): void
    {
        abort_unless(
            WebsiteSection::query()->where('type', 'booking')->where('enabled', true)->exists() && $booking->isOpen(),
            404,
        );
    }
}
