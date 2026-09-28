<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `appointment.created`. */
class AppointmentCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Appointment $appointment) {}
}
