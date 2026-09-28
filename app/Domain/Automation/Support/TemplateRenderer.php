<?php

namespace App\Domain\Automation\Support;

use App\Domain\Tenant\Models\TenantSetting;
use Illuminate\Support\Str;

/**
 * Fills {{entity.field}} placeholders (config/automation.php `variables`) with the run's data.
 * Plain substitution only — templates are never evaluated as code. Unknown or empty placeholders
 * become empty text.
 */
class TemplateRenderer
{
    public function render(string $template, SubjectContext $context): string
    {
        $rendered = preg_replace_callback(
            '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/',
            fn (array $match) => $this->value($match[1], $context),
            $template,
        ) ?? $template;

        return trim(preg_replace('/[ \t]{2,}/', ' ', $rendered) ?? $rendered);
    }

    public function value(string $variable, SubjectContext $context): string
    {
        $lead = $context->lead;
        $customer = $context->customer;
        $appointment = $context->appointment;
        $timezone = $context->tenant->timezone;

        return (string) match ($variable) {
            'business.name' => $context->tenant->name,
            'business.phone' => $this->businessPhone($context),
            'lead.name' => $lead?->name,
            'lead.first_name' => $lead ? Str::before(trim($lead->name), ' ') : null,
            'lead.phone' => $lead?->phone,
            'lead.interest' => $lead?->interest,
            'lead.stage' => $lead?->stage?->name,
            'customer.name' => $customer?->name,
            'customer.first_name' => $customer ? Str::before(trim($customer->name), ' ') : null,
            'customer.phone' => $customer?->phone,
            'appointment.date' => $appointment?->starts_at->setTimezone($timezone)->format('D, j M'),
            'appointment.time' => $appointment?->starts_at->setTimezone($timezone)->format('g:i A'),
            'appointment.service' => $appointment?->service?->name,
            'appointment.resource' => $appointment?->resource?->name,
            default => null,
        };
    }

    private function businessPhone(SubjectContext $context): ?string
    {
        $profile = TenantSetting::query()->where('key', 'business_profile')->value('value');

        return is_array($profile) ? ($profile['phone'] ?? null) : null;
    }
}
