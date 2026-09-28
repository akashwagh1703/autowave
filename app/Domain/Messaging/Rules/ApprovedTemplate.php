<?php

namespace App\Domain\Messaging\Rules;

use App\Domain\Messaging\Models\MessageTemplate;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/** `template` ({name, language}) is an approved WhatsApp template of the current tenant, and `params` fills its variables. */
class ApprovedTemplate implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ! is_string($value['name'] ?? null) || ! is_string($value['language'] ?? null)) {
            $fail('Choose an approved template.');

            return;
        }

        $template = MessageTemplate::query()->approved()
            ->where('channel', 'whatsapp')
            ->where('name', $value['name'])
            ->where('language', $value['language'])
            ->first();

        if (! $template) {
            $fail('Choose an approved template. Sync templates in Settings → Messaging if it is missing.');

            return;
        }

        $params = is_array($this->data['params'] ?? null) ? array_values($this->data['params']) : [];
        $filled = array_filter(array_slice($params, 0, $template->variables), fn ($param) => is_string($param) && trim($param) !== '');

        if (count($filled) < $template->variables) {
            $fail("Fill in all {$template->variables} template variables.");
        }
    }
}
