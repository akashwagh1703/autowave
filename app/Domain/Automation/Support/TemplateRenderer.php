<?php

namespace App\Domain\Automation\Support;

use App\Domain\Education\Support\BatchSchedule;
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
        $order = $context->order;
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
            'order.number' => $order?->reference(),
            'order.total' => $order ? number_format((float) $order->total, 2) : null,
            'order.items' => $order?->itemSummary(),
            'order.fulfilment' => $order ? config("commerce.fulfilment.{$order->fulfilment}.label", $order->fulfilment) : null,
            'conversation.channel' => $context->conversation ? config("messaging.channels.{$context->conversation->channel}.label", $context->conversation->channel) : null,
            'message.text' => $context->latestInboundText(),
            'enrolment.course' => $context->enrolment()?->batch?->course?->name,
            'enrolment.batch' => $context->enrolment()?->batch?->name,
            'enrolment.schedule' => $context->enrolment()?->batch ? BatchSchedule::describe($context->enrolment()->batch) : null,
            'enrolment.balance' => $context->enrolment() ? number_format((float) $context->enrolment()->balance(), 2) : null,
            'fee.amount_due' => $context->fee() ? number_format((float) $context->fee()->due(), 2) : null,
            'fee.due_date' => $context->fee()?->due_on->format('D, j M'),
            'demo_class.date' => $context->demo()?->scheduled_at->setTimezone($timezone)->format('D, j M'),
            'demo_class.time' => $context->demo()?->scheduled_at->setTimezone($timezone)->format('g:i A'),
            'demo_class.course' => $context->demo()?->course?->name,
            'reservation.date' => $context->reservation()?->reserved_at->setTimezone($timezone)->format('D, j M'),
            'reservation.time' => $context->reservation()?->reserved_at->setTimezone($timezone)->format('g:i A'),
            'reservation.party_size' => $context->reservation()?->party_size,
            'reservation.table' => $context->reservation()?->table?->name,
            default => null,
        };
    }

    private function businessPhone(SubjectContext $context): ?string
    {
        $profile = TenantSetting::query()->where('key', 'business_profile')->value('value');

        return is_array($profile) ? ($profile['phone'] ?? null) : null;
    }
}
