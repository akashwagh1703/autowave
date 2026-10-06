<?php

namespace App\Domain\Chatbot\Support;

use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;

/**
 * The WhatsApp assistant's settings: the `whatsapp_assistant` tenant setting over config('chatbot.defaults').
 * Read fresh on every call, so a change in Settings applies to the next message.
 */
class ChatbotSettings
{
    public const KEY = 'whatsapp_assistant';

    public function __construct(private readonly TenantContext $context) {}

    /** @return array{enabled: bool, welcome: ?string, show_image: bool, items: list<string>, pause_hours: int, alert_team: bool, ai_answers: bool} */
    public function all(): array
    {
        $stored = $this->context->setting(self::KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $defaults = config('chatbot.defaults');
        $items = config('chatbot.items');
        $limits = config('chatbot.pause_hours');

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'welcome' => filled($stored['welcome'] ?? null) ? (string) $stored['welcome'] : null,
            'show_image' => (bool) ($stored['show_image'] ?? $defaults['show_image']),
            // Items switched off are listed; anything new in config is on by default.
            'items' => array_values(array_diff($items, is_array($stored['hidden_items'] ?? null) ? $stored['hidden_items'] : [])),
            'pause_hours' => max($limits['min'], min($limits['max'], (int) ($stored['pause_hours'] ?? $defaults['pause_hours']))),
            'alert_team' => (bool) ($stored['alert_team'] ?? $defaults['alert_team']),
            'ai_answers' => (bool) ($stored['ai_answers'] ?? $defaults['ai_answers']),
        ];
    }

    public function enabled(): bool
    {
        return $this->all()['enabled'];
    }

    /** @param  array{enabled: bool, welcome: ?string, show_image: bool, items: list<string>, pause_hours: int, alert_team: bool, ai_answers?: bool}  $values */
    public function update(array $values): void
    {
        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => [
            'enabled' => $values['enabled'],
            'welcome' => $values['welcome'],
            'show_image' => $values['show_image'],
            'hidden_items' => array_values(array_diff(config('chatbot.items'), $values['items'])),
            'pause_hours' => $values['pause_hours'],
            'alert_team' => $values['alert_team'],
            'ai_answers' => (bool) ($values['ai_answers'] ?? false),
        ]]);
    }
}
