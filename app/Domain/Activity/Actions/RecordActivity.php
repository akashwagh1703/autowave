<?php

namespace App\Domain\Activity\Actions;

use App\Domain\Activity\Models\Activity;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Writes a timeline entry. A lead's, an appointment's and an order's entries also carry the
 * customer_id so they show on the customer timeline.
 */
class RecordActivity
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        string $type,
        ?Lead $lead = null,
        ?Customer $customer = null,
        ?User $actor = null,
        ?string $body = null,
        array $metadata = [],
        ?DateTimeInterface $occurredAt = null,
        ?Appointment $appointment = null,
        ?Order $order = null,
    ): Activity {
        if (! $lead && ! $customer && ! $appointment && ! $order) {
            throw new InvalidArgumentException('An activity needs a lead, a customer, an appointment or an order.');
        }

        return Activity::query()->create([
            'lead_id' => $lead?->id,
            'customer_id' => $customer?->id ?? $lead?->customer_id ?? $appointment?->customer_id ?? $order?->customer_id,
            'appointment_id' => $appointment?->id,
            'order_id' => $order?->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'body' => $body,
            'metadata' => $metadata ?: null,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
