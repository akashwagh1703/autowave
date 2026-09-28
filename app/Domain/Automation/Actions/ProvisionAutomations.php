<?php

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Models\Automation;
use App\Domain\Business\Models\BusinessType;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Creates the default automations (config automation.templates) for a tenant with the automation
 * module. The business type may choose its templates in `configuration.automation_templates`.
 *
 * Idempotent: a template is created once per tenant — never again once it exists, even if the
 * owner deleted it. Templates that need a trigger, field, stage or action the tenant does not have
 * are skipped. Must run inside TenantContext::run() for the tenant.
 */
class ProvisionAutomations
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SaveAutomation $save,
    ) {}

    /** @return list<string> the template keys created */
    public function handle(Tenant $tenant, ?BusinessType $type): array
    {
        if ($this->context->id() !== $tenant->id) {
            throw new LogicException('ProvisionAutomations must run inside the tenant context.');
        }

        if (! $this->context->hasModule('automation')) {
            return [];
        }

        $keys = $type?->configuration['automation_templates'] ?? config('automation.default_templates');
        $existing = Automation::withTrashed()->whereNotNull('template_key')->pluck('template_key')->all();
        $created = [];

        foreach (array_diff($keys, $existing) as $key) {
            $template = config("automation.templates.{$key}");

            if (! $template) {
                continue;
            }

            try {
                $this->save->handle([
                    'name' => $template['name'],
                    'description' => $template['description'] ?? null,
                    'trigger' => $template['trigger'],
                    'is_active' => $template['active'] ?? false,
                    'once_per_subject' => $template['once_per_subject'] ?? false,
                    'steps' => $template['steps'],
                ], templateKey: $key);

                $created[] = $key;
            } catch (ValidationException $exception) {
                Log::info('automation.template_skipped', ['tenant_id' => $tenant->id, 'template' => $key, 'errors' => $exception->errors()]);
            }
        }

        return $created;
    }

    /** Backfill for tenants created before automations existed. */
    public function ensureFor(Tenant $tenant): array
    {
        return $this->context->run($tenant, fn (Tenant $tenant) => $this->handle($tenant, $tenant->businessType));
    }
}
