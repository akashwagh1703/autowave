<?php

namespace App\Domain\Automation\Support;

use App\Domain\Automation\Enums\StepType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises an automation definition against what the current tenant can use:
 *
 *   name, description, trigger, is_active, once_per_subject,
 *   steps: [{type: condition, config: {match, rules: [{field, operator, value}]}}
 *           {type: wait, config: {mode, amount, unit}}
 *           {type: action, action, config: {…action specific…}}]
 *
 * Errors use the builder's field paths, e.g. `steps.2.config.message`. Used by the builder and by
 * template provisioning, so a template the tenant cannot use is never created.
 */
class DefinitionValidator
{
    public function __construct(private readonly AutomationCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, description: ?string, trigger: string, is_active: bool, once_per_subject: bool, steps: list<array{type: string, action: ?string, config: array<string, mixed>}>}
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $limits = config('automation.limits');

        $base = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'trigger' => ['required', 'string', Rule::in(array_keys($this->catalog->triggers()))],
            'is_active' => ['boolean'],
            'once_per_subject' => ['boolean'],
            'steps' => ['required', 'array', 'list', 'min:1', 'max:'.$limits['steps']],
            'steps.*' => ['array'],
            'steps.*.type' => ['required', Rule::enum(StepType::class)],
        ], [
            'trigger.in' => 'Choose a trigger from the list.',
            'steps.required' => 'Add at least one step.',
            'steps.max' => 'An automation can have at most :max steps.',
        ], [
            'steps.*.type' => 'step type',
        ])->validate();

        $trigger = $base['trigger'];
        $errors = new MessageBag;
        $steps = [];

        foreach ($input['steps'] as $index => $step) {
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];

            $steps[] = match (StepType::from($step['type'])) {
                StepType::Condition => ['type' => 'condition', 'action' => null, 'config' => $this->condition($config, $trigger, "steps.{$index}.config", $errors)],
                StepType::Wait => ['type' => 'wait', 'action' => null, 'config' => $this->wait($config, $trigger, "steps.{$index}.config", $errors)],
                StepType::Action => $this->action($step, $config, $trigger, "steps.{$index}", $errors),
            };
        }

        if (! collect($steps)->contains('type', 'action')) {
            $errors->add('steps', 'Add at least one action — otherwise the automation does nothing.');
        } elseif (end($steps)['type'] === 'wait') {
            $errors->add('steps.'.(count($steps) - 1).'.type', 'A wait must be followed by another step.');
        }

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }

        return [
            'name' => trim($base['name']),
            'description' => filled($base['description'] ?? null) ? trim($base['description']) : null,
            'trigger' => $trigger,
            'is_active' => (bool) ($base['is_active'] ?? true),
            'once_per_subject' => (bool) ($base['once_per_subject'] ?? false),
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{match: string, rules: list<array{field: string, operator: string, value: mixed}>}
     */
    private function condition(array $config, string $trigger, string $prefix, MessageBag $errors): array
    {
        $fields = $this->catalog->fieldsFor($trigger);

        $validated = $this->check($config, [
            'match' => ['required', Rule::in(['all', 'any'])],
            'rules' => ['required', 'array', 'list', 'min:1', 'max:'.config('automation.limits.rules')],
        ], $prefix, $errors, ['rules.required' => 'Add at least one rule.']);

        if ($validated === null) {
            return ['match' => 'all', 'rules' => []];
        }

        $rules = [];

        foreach ($config['rules'] as $i => $input) {
            $key = "{$prefix}.rules.{$i}";
            $rule = $this->check(is_array($input) ? $input : [], [
                'field' => ['required', 'string', Rule::in(array_keys($fields))],
                'operator' => ['required', 'string'],
            ], $key, $errors, ['field.in' => 'Choose a field this trigger has.']);

            if ($rule === null) {
                continue;
            }

            $field = $fields[$rule['field']];
            $operators = config('automation.operators.'.$field['type'], []);

            if (! in_array($rule['operator'], $operators, true)) {
                $errors->add("{$key}.operator", 'Choose how to compare '.mb_strtolower($field['label']).'.');

                continue;
            }

            $value = $input['value'] ?? null;
            $valueRules = $this->valueRules($field, $rule['operator']);

            if ($valueRules === null) {
                $rules[] = ['field' => $rule['field'], 'operator' => $rule['operator'], 'value' => null];

                continue;
            }

            $checked = $this->check(['value' => $value], $valueRules, $key, $errors, [
                'value.required' => 'Enter a value to compare with.',
                'value.in' => 'Choose a value from the list.',
                'value.*.in' => 'Choose values from the list.',
            ]);

            if ($checked !== null) {
                $rules[] = ['field' => $rule['field'], 'operator' => $rule['operator'], 'value' => $this->normalizeValue($field, $rule['operator'], $checked['value'])];
            }
        }

        return ['match' => $validated['match'], 'rules' => $rules];
    }

    /**
     * Rules for a condition's comparison value; null when the operator takes none.
     *
     * @param  array<string, mixed>  $field
     * @return ?array<string, list<mixed>>
     */
    private function valueRules(array $field, string $operator): ?array
    {
        if (in_array($operator, ['is_set', 'is_not_set', 'is_true', 'is_false'], true)) {
            return null;
        }

        $options = fn () => Rule::in($this->catalog->optionValues($field['options']));

        return match ($field['type']) {
            'enum' => in_array($operator, ['in', 'not_in'], true)
                ? ['value' => ['required', 'array', 'list', 'min:1', 'max:50'], 'value.*' => ['string', $options()]]
                : ['value' => ['required', 'string', $options()]],
            'number' => ['value' => ['required', 'numeric', 'min:0', 'max:99999999']],
            'tags' => ['value' => ['required', 'string', 'max:50']],
            default => ['value' => ['required', 'string', 'max:150']],
        };
    }

    private function normalizeValue(array $field, string $operator, mixed $value): mixed
    {
        if ($field['type'] === 'enum' && in_array($operator, ['in', 'not_in'], true)) {
            return array_values(array_unique(array_map('strval', $value)));
        }

        return match ($field['type']) {
            'number' => (float) $value,
            'enum' => (string) $value,
            default => trim((string) $value),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{mode: string, amount: int, unit: string}
     */
    private function wait(array $config, string $trigger, string $prefix, MessageBag $errors): array
    {
        $mode = $config['mode'] ?? null;

        $validated = $this->check($config, [
            'mode' => ['required', Rule::in(array_keys($this->catalog->waitModesFor($trigger)))],
            'amount' => ['required', 'integer', $mode === 'delay' ? 'min:1' : 'min:0'],
            'unit' => ['required', Rule::in(array_keys(config('automation.wait_units')))],
        ], $prefix, $errors, ['mode.in' => 'Choose a wait this trigger supports.', 'amount.min' => 'Enter at least :min.']);

        if ($validated === null) {
            return ['mode' => 'delay', 'amount' => 1, 'unit' => 'minutes'];
        }

        $normalized = ['mode' => $validated['mode'], 'amount' => (int) $validated['amount'], 'unit' => $validated['unit']];
        $maxDays = (int) config('automation.limits.max_wait_days');

        if (WaitCalculator::minutes($normalized) > $maxDays * 1440) {
            $errors->add("{$prefix}.amount", "A wait can be at most {$maxDays} days.");
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $config
     * @return array{type: string, action: ?string, config: array<string, mixed>}
     */
    private function action(array $step, array $config, string $trigger, string $prefix, MessageBag $errors): array
    {
        $key = $step['action'] ?? null;

        if (! is_string($key) || ! array_key_exists($key, $this->catalog->actionsFor($trigger))) {
            $errors->add("{$prefix}.action", 'Choose an action this trigger supports.');

            return ['type' => 'action', 'action' => is_string($key) ? $key : null, 'config' => []];
        }

        $action = $this->catalog->action($key);
        $validated = $this->check($config, $action->rules(), "{$prefix}.config", $errors);

        return ['type' => 'action', 'action' => $key, 'config' => $validated === null ? [] : $action->normalize($validated)];
    }

    /**
     * Validate one part of the definition, adding errors under `$prefix`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return ?array<string, mixed> the validated data, or null when invalid
     */
    private function check(array $data, array $rules, string $prefix, MessageBag $errors, array $messages = []): ?array
    {
        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $fieldErrors) {
                foreach ($fieldErrors as $message) {
                    $errors->add("{$prefix}.{$key}", $message);
                }
            }

            return null;
        }

        return $validator->validated();
    }
}
