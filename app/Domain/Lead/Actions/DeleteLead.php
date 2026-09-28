<?php

namespace App\Domain\Lead\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Lead\Models\Lead;

/**
 * Soft delete. The timeline is kept so a linked customer's history stays intact.
 */
class DeleteLead
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Lead $lead): void
    {
        $lead->delete();

        $this->audit->log('lead.deleted', $lead, ['name' => $lead->name]);
    }
}
