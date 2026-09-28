<?php

namespace App\Domain\Website\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validates and normalises a section's configuration against its field definitions in
 * config/website.php. Unknown keys are dropped; strings are trimmed (empty → null); list items
 * keep only their defined fields. Errors are keyed `config.<field>` / `config.<list>.<n>.<field>`.
 */
class SectionSchema
{
    public function __construct(private readonly SectionCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the validated values of the fields the tenant can edit
     */
    public function validate(string $type, array $input): array
    {
        $fields = $this->catalog->fields($type);
        $rules = [];
        $attributes = [];

        foreach ($fields as $key => $field) {
            $this->rulesFor("config.{$key}", $field, $rules, $attributes);
        }

        $validated = Validator::make(['config' => $input], $rules, [], $attributes)->validate();

        $values = [];

        foreach ($fields as $key => $field) {
            $values[$key] = $this->normalize($field, $validated['config'][$key] ?? null);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $attributes
     */
    private function rulesFor(string $path, array $field, array &$rules, array &$attributes): void
    {
        $attributes[$path] = mb_strtolower($field['label']);
        $presence = ($field['required'] ?? false) ? 'required' : 'nullable';

        switch ($field['type']) {
            case 'text':
            case 'textarea':
                $rules[$path] = [$presence, 'string', 'max:'.($field['max'] ?? 255)];
                break;
            case 'boolean':
                $rules[$path] = ['nullable', 'boolean'];
                break;
            case 'select':
                $rules[$path] = [$presence, 'string', Rule::in(array_keys($field['options']))];
                break;
            case 'list':
                $rules[$path] = ['nullable', 'array', 'max:'.($field['max'] ?? 10)];
                $rules["{$path}.*"] = ['array'];

                foreach ($field['fields'] as $key => $itemField) {
                    $this->rulesFor("{$path}.*.{$key}", $itemField, $rules, $attributes);
                }
                break;
        }
    }

    private function normalize(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'boolean' => $value === null ? (bool) ($field['default'] ?? false) : filter_var($value, FILTER_VALIDATE_BOOL),
            'list' => array_values(array_map(
                fn (array $item) => array_combine(
                    array_keys($field['fields']),
                    array_map(fn (string $key) => $this->normalize($field['fields'][$key], $item[$key] ?? null), array_keys($field['fields'])),
                ),
                is_array($value) ? $value : [],
            )),
            default => is_string($value) && trim($value) !== '' ? trim($value) : null,
        };
    }
}
