<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `appointment.no_show`. */
class AppointmentNoShow implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
