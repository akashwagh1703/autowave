<?php

namespace App\Domain\Automation\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Automation\Models\Automation;

/**
 * Turns an automation on or off. Turning it off also stops its runs in progress: each is cancelled
 * when its next step comes up.
 */
class ToggleAutomation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Automation $automation, bool $active): Automation
    {
        if ($automation->is_active === $active) {
            return $automation;
        }

        $automation->update(['is_active' => $active]);
        $this->audit->log($active ? 'automation.activated' : 'automation.deactivated', $automation, ['name' => $automation->name]);

        return $automation;
    }
}
