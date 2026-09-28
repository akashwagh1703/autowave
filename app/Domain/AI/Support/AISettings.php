<?php

namespace App\Domain\AI\Support;

use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;

/**
 * The business's AI preferences: the `ai` tenant setting over config/ai.php `defaults`.
 * Read fresh on every call, so it is safe inside long-lived workers.
 */
class AISettings
{
    public const KEY = 'ai';

    public function __construct(private readonly TenantContext $context) {}

    /** @return array{enabled: bool, auto_extract: bool, tone: string, notes: ?string} */
    public function all(): array
    {
        $stored = $this->context->setting(self::KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $defaults = config('ai.defaults');
        $tone = $stored['tone'] ?? $defaults['tone'];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'auto_extract' => (bool) ($stored['auto_extract'] ?? $defaults['auto_extract']),
            'tone' => array_key_exists($tone, config('ai.tones')) ? $tone : $defaults['tone'],
            'notes' => filled($stored['notes'] ?? null) ? (string) $stored['notes'] : $defaults['notes'],
        ];
    }

    public function enabled(): bool
    {
        return $this->all()['enabled'];
    }

    public function autoExtract(): bool
    {
        return $this->all()['auto_extract'];
    }

    /** @param  array{enabled: bool, auto_extract: bool, tone: string, notes: ?string}  $values */
    public function update(array $values): void
    {
        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $values]);
    }
}
