<?php

namespace App\Domain\Food\Support;

use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;

/**
 * Tenant food preferences: the `food` tenant setting over the config/food.php defaults. Read fresh on
 * every call (no memo), so it is safe to use across requests.
 */
class FoodSettings
{
    public const KEY = 'food';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{online: bool, auto_confirm: bool, duration_minutes: int, opens: string, closes: string, slot_interval: int, max_party_size: int, min_notice_minutes: int, max_days_ahead: int}
     */
    public function reservations(): array
    {
        $stored = is_array($this->stored()['reservations'] ?? null) ? $this->stored()['reservations'] : [];
        $defaults = config('food.reservations');
        $value = fn (string $key) => array_key_exists($key, $stored) ? $stored[$key] : $defaults[$key];
        $time = fn (string $key) => is_string($value($key)) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value($key)) ? $value($key) : $defaults[$key];

        $duration = (int) $value('duration_minutes');
        $interval = (int) $value('slot_interval');

        return [
            'online' => (bool) $value('online'),
            'auto_confirm' => (bool) $value('auto_confirm'),
            'duration_minutes' => in_array($duration, config('food.duration_options'), true) ? $duration : $defaults['duration_minutes'],
            'opens' => $time('opens'),
            'closes' => $time('closes'),
            'slot_interval' => in_array($interval, config('food.slot_interval_options'), true) ? $interval : $defaults['slot_interval'],
            'max_party_size' => max(1, min((int) $value('max_party_size'), (int) config('food.max_party_size_limit'))),
            'min_notice_minutes' => max(0, (int) $value('min_notice_minutes')),
            'max_days_ahead' => max(1, min((int) $value('max_days_ahead'), 365)),
        ];
    }

    /** @param  array<string, mixed>  $reservations */
    public function updateReservations(array $reservations): void
    {
        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => [...$this->stored(), 'reservations' => $reservations]]);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $value = $this->context->setting(self::KEY, []);

        return is_array($value) ? $value : [];
    }
}
