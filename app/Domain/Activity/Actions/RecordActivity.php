<?php

namespace App\Domain\Activity\Actions;

use App\Domain\Activity\Models\Activity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Writes a timeline entry. A lead's entries also carry its customer_id so they show on the
 * customer timeline.
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
    ): Activity {
        if (! $lead && ! $customer) {
            throw new InvalidArgumentException('An activity needs a lead or a customer.');
        }

        return Activity::query()->create([
            'lead_id' => $lead?->id,
            'customer_id' => $customer?->id ?? $lead?->customer_id,
            'user_id' => $actor?->id,
            'type' => $type,
            'body' => $body,
            'metadata' => $metadata ?: null,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
