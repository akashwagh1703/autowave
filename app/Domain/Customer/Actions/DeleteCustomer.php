<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Customer\Models\Customer;

/**
 * Soft delete: linked leads and the timeline keep pointing at the record.
 */
class DeleteCustomer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Customer $customer): void
    {
        $customer->delete();

        $this->audit->log('customer.deleted', $customer, ['name' => $customer->name]);
    }
}
