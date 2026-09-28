<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Appointment;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `appointment.rescheduled`. */
class AppointmentRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly CarbonInterface $previousStartsAt,
        public readonly ?int $previousResourceId = null,
    ) {}
}
