<?php

namespace App\Domain\Loyalty\Actions;

use App\Domain\Booking\Models\Appointment;
use App\Domain\Loyalty\Models\LoyaltyStamp;
use App\Domain\Loyalty\Support\LoyaltySettings;

/** Awards one stamp when an appointment is completed (if enabled). */
class EarnFromAppointment
{
    public function __construct(
        private readonly LoyaltySettings $settings,
        private readonly EnrollCustomer $enroll,
        private readonly AddStamps $addStamps,
    ) {}

    public function handle(Appointment $appointment): ?LoyaltyStamp
    {
        if (! $this->settings->enabled() || ! $this->settings->all()['earn_appointments']) {
            return null;
        }

        $appointment->loadMissing('customer');
        $customer = $appointment->customer;

        if (! $customer || $customer->trashed()) {
            return null;
        }

        $account = $this->enroll->forCustomer($customer, via: 'appointment');

        return $this->addStamps->handle(
            $account,
            1,
            'appointment',
            $appointment->id,
        );
    }
}
