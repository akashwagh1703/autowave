<?php

namespace App\Domain\Automation\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Automation\Models\Automation;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Turns an automation on or off. Turning it off also stops its runs in progress: each is cancelled
 * when its next step comes up. Turning one on respects the plan's number of active automations.
 */
class ToggleAutomation
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Entitlements $entitlements,
        private readonly TenantContext $context,
    ) {}

    /** @throws ValidationException when the plan allows no more active automations */
    public function handle(Automation $automation, bool $active): Automation
    {
        if ($automation->is_active === $active) {
            return $automation;
        }

        if ($active) {
            $this->ensureCanActivate($automation);
        }

        $automation->update(['is_active' => $active]);
        $this->audit->log($active ? 'automation.activated' : 'automation.deactivated', $automation, ['name' => $automation->name]);

        return $automation;
    }

    /** @throws ValidationException */
    public function ensureCanActivate(?Automation $automation = null, string $field = 'is_active'): void
    {
        $max = $this->entitlements->limit($this->context->tenant(), 'automations');

        if ($max === null) {
            return;
        }

        $active = Automation::query()->where('is_active', true)->when($automation?->exists, fn ($query) => $query->whereKeyNot($automation->getKey()))->count();

        if ($active >= (int) $max) {
            throw ValidationException::withMessages([$field => __('Your plan allows :max active automations. Pause another one, or upgrade your plan in Billing.', ['max' => $max])]);
        }
    }
}
