<?php

namespace App\Domain\Automation\Support;

/**
 * Evaluates a condition step ({match: all|any, rules: [{field, operator, value}]}) against the
 * current state of the run's records. A rule on a record the subject does not have (a lead with no
 * customer yet) sees an empty value.
 */
class ConditionEvaluator
{
    /**
     * @param  array{match?: string, rules?: list<array{field: string, operator: string, value?: mixed}>}  $config
     * @return array{passed: bool, results: list<array{field: string, operator: string, value: mixed, actual: mixed, passed: bool}>}
     */
    public function evaluate(array $config, SubjectContext $context): array
    {
        $results = [];

        foreach ($config['rules'] ?? [] as $rule) {
            $actual = $this->value($rule['field'], $context);
            $results[] = [
                'field' => $rule['field'],
                'operator' => $rule['operator'],
                'value' => $rule['value'] ?? null,
                'actual' => $actual,
                'passed' => $this->compare($rule['operator'], $actual, $rule['value'] ?? null),
            ];
        }

        $passed = array_column($results, 'passed');
        $any = ($config['match'] ?? 'all') === 'any';

        return [
            'passed' => $passed !== [] && ($any ? in_array(true, $passed, true) : ! in_array(false, $passed, true)),
            'results' => $results,
        ];
    }

    public function value(string $field, SubjectContext $context): mixed
    {
        $lead = $context->lead;
        $customer = $context->customer;
        $appointment = $context->appointment;
        $order = $context->order;

        return match ($field) {
            'lead.stage' => $lead?->stage?->code,
            'lead.source' => $lead?->source?->code,
            'lead.assigned' => $lead ? $lead->assigned_tenant_user_id !== null : null,
            'lead.contacted' => $lead ? $lead->last_contacted_at !== null : null,
            'lead.phone' => $lead?->phone,
            'lead.email' => $lead?->email,
            'lead.interest' => $lead?->interest,
            'lead.estimated_value' => $lead?->estimated_value !== null ? (float) $lead->estimated_value : null,
            'customer.tags' => $customer ? ($customer->tags ?? []) : null,
            'customer.phone' => $customer?->phone,
            'customer.email' => $customer?->email,
            'customer.city' => $customer?->city,
            'appointment.status' => $appointment?->status->value,
            'appointment.service' => $appointment?->service_id !== null ? (string) $appointment->service_id : null,
            'appointment.resource' => $appointment ? (string) $appointment->booking_resource_id : null,
            'appointment.price' => $appointment?->price !== null ? (float) $appointment->price : null,
            'appointment.source' => $appointment?->source,
            'order.status' => $order?->status->value,
            'order.source' => $order?->source,
            'order.fulfilment' => $order?->fulfilment,
            'order.payment_status' => $order?->payment_status->value,
            'order.total' => $order ? (float) $order->total : null,
            'conversation.channel' => $context->conversation?->channel,
            'conversation.assigned' => $context->conversation ? $context->conversation->assigned_tenant_user_id !== null : null,
            'message.text' => $context->latestInboundText(),
            'enrolment.status' => $context->enrolment()?->status->value,
            'enrolment.course' => $context->enrolment()?->batch?->course_id !== null ? (string) $context->enrolment()->batch->course_id : null,
            'enrolment.balance' => $context->enrolment() ? (float) $context->enrolment()->balance() : null,
            'fee.amount_due' => $context->fee() ? (float) $context->fee()->due() : null,
            'demo_class.status' => $context->demo()?->status->value,
            'reservation.status' => $context->reservation()?->status->value,
            'reservation.source' => $context->reservation()?->source,
            'reservation.party_size' => $context->reservation()?->party_size,
            default => null,
        };
    }

    public function compare(string $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            'is_set' => $this->isSet($actual),
            'is_not_set' => ! $this->isSet($actual),
            'is_true' => $actual === true,
            'is_false' => $actual === false,
            'equals' => $this->equals($actual, $expected),
            'not_equals' => ! $this->equals($actual, $expected),
            'in' => $actual !== null && in_array((string) $actual, array_map('strval', (array) $expected), true),
            'not_in' => $actual === null || ! in_array((string) $actual, array_map('strval', (array) $expected), true),
            'contains' => $this->contains($actual, $expected),
            'not_contains' => ! $this->contains($actual, $expected),
            'gt' => is_numeric($actual) && (float) $actual > (float) $expected,
            'gte' => is_numeric($actual) && (float) $actual >= (float) $expected,
            'lt' => is_numeric($actual) && (float) $actual < (float) $expected,
            'lte' => is_numeric($actual) && (float) $actual <= (float) $expected,
            default => false,
        };
    }

    private function isSet(mixed $value): bool
    {
        return is_array($value) ? $value !== [] : $value !== null && trim((string) $value) !== '';
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if ($actual === null) {
            return false;
        }

        if (is_float($actual) || is_int($actual)) {
            return is_numeric($expected) && (float) $actual === (float) $expected;
        }

        return mb_strtolower(trim((string) $actual)) === mb_strtolower(trim((string) $expected));
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        $needle = mb_strtolower(trim((string) $expected));

        if ($needle === '' || $actual === null) {
            return false;
        }

        if (is_array($actual)) {
            return in_array($needle, array_map(fn ($tag) => mb_strtolower((string) $tag), $actual), true);
        }

        return str_contains(mb_strtolower((string) $actual), $needle);
    }
}
