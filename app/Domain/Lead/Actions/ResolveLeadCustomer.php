<?php

namespace App\Domain\Lead\Actions;

use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Models\User;

/**
 * The customer a converting lead becomes: its linked customer, else a live customer with the
 * same phone (then email), else a new customer built from the lead.
 */
class ResolveLeadCustomer
{
    public function __construct(private readonly CreateCustomer $createCustomer) {}

    /**
     * @return array{0: Customer, 1: bool} the customer and whether it was created
     */
    public function handle(Lead $lead, ?User $actor = null): array
    {
        $linked = $lead->customer_id ? Customer::query()->find($lead->customer_id) : null;

        if ($linked) {
            return [$linked, false];
        }

        $match = $lead->phone_normalized
            ? Customer::query()->where('phone_normalized', $lead->phone_normalized)->first()
            : null;

        $match ??= filled($lead->email)
            ? Customer::query()->whereRaw('lower(email) = ?', [mb_strtolower($lead->email)])->first()
            : null;

        if ($match) {
            return [$match, false];
        }

        $customer = $this->createCustomer->handle([
            'name' => $lead->name,
            'phone' => $lead->phone,
            'email' => $lead->email,
        ], $actor, ['lead_id' => $lead->id, 'lead_name' => $lead->name]);

        return [$customer, true];
    }
}
