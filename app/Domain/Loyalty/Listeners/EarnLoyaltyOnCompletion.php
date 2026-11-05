<?php

namespace App\Domain\Loyalty\Listeners;

use App\Domain\Booking\Events\AppointmentCompleted;
use App\Domain\Commerce\Events\OrderCompleted;
use App\Domain\Loyalty\Actions\EarnFromAppointment;
use App\Domain\Loyalty\Actions\EarnFromOrder;
use Throwable;

/** Awards loyalty stamps after an order or appointment is completed. */
class EarnLoyaltyOnCompletion
{
    public function __construct(
        private readonly EarnFromOrder $fromOrder,
        private readonly EarnFromAppointment $fromAppointment,
    ) {}

    public function handle(OrderCompleted|AppointmentCompleted $event): void
    {
        try {
            if ($event instanceof OrderCompleted) {
                $this->fromOrder->handle($event->order);
            } else {
                $this->fromAppointment->handle($event->appointment);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
