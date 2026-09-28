<?php

namespace App\Domain\Activity\Actions;

use App\Domain\Activity\Models\Activity;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Writes a timeline entry. A lead's and an appointment's entries also carry the customer_id so
 * they show on the customer timeline.
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
    ): Activity {
        if (! $lead && ! $customer && ! $appointment) {
            throw new InvalidArgumentException('An activity needs a lead, a customer or an appointment.');
        }

        return Activity::query()->create([
            'lead_id' => $lead?->id,
            'customer_id' => $customer?->id ?? $lead?->customer_id ?? $appointment?->customer_id,
            'appointment_id' => $appointment?->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'body' => $body,
            'metadata' => $metadata ?: null,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
