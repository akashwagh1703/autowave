<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `appointment.confirmed` (also fired for bookings created as confirmed). */
class AppointmentConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
