<?php

namespace App\Domain\Automation\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Automation\Models\Automation;

/**
 * Soft-deletes an automation. Its run history stays readable; runs in progress are cancelled when
 * their next step comes up.
 */
class DeleteAutomation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Automation $automation): void
    {
        $automation->update(['is_active' => false]);
        $automation->delete();

        $this->audit->log('automation.deleted', $automation, ['name' => $automation->name]);
    }
}
