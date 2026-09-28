<?php

namespace App\Domain\Booking\Support;

use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Tenant booking preferences: the `booking` tenant setting (seeded from the business type's
 * `configuration.booking`) over the config/booking.php defaults, plus the resource label.
 */
class BookingSettings
{
    public const KEY = 'booking';

    public const LABEL_KEY = 'booking_resource_label';

    /** @var array<int, array<string, mixed>> keyed by tenant id */
    private array $memo = [];

    public function __construct(private readonly TenantContext $context) {}

    public function slotInterval(): int
    {
        $interval = (int) ($this->stored()['slot_interval'] ?? config('booking.slot_interval'));

        return in_array($interval, config('booking.slot_intervals'), true) ? $interval : (int) config('booking.slot_interval');
    }

    public function autoConfirm(): bool
    {
        return (bool) ($this->stored()['auto_confirm'] ?? config('booking.auto_confirm'));
    }

    /** @return list<array{weekday: int, starts_at: string, ends_at: string}> */
    public function defaultHours(): array
    {
        return array_values($this->stored()['default_hours'] ?? config('booking.default_hours'));
    }

    /**
     * Online booking from the public website: the `online` part of the setting over
     * config('booking.online').
     *
     * @return array{enabled: bool, auto_confirm: bool, min_notice_minutes: int, max_days_ahead: int, allow_any_resource: bool}
     */
    public function online(): array
    {
        $stored = is_array($this->stored()['online'] ?? null) ? $this->stored()['online'] : [];
        $defaults = config('booking.online');

        $notice = (int) ($stored['min_notice_minutes'] ?? $defaults['min_notice_minutes']);
        $days = (int) ($stored['max_days_ahead'] ?? $defaults['max_days_ahead']);

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'auto_confirm' => (bool) ($stored['auto_confirm'] ?? $defaults['auto_confirm']),
            'min_notice_minutes' => in_array($notice, config('booking.online_notice_options'), true) ? $notice : (int) $defaults['min_notice_minutes'],
            'max_days_ahead' => in_array($days, config('booking.online_days_ahead_options'), true) ? $days : (int) $defaults['max_days_ahead'],
            'allow_any_resource' => (bool) ($stored['allow_any_resource'] ?? $defaults['allow_any_resource']),
        ];
    }

    public function resourceLabel(): string
    {
        $label = $this->context->setting(self::LABEL_KEY);

        return is_string($label) && trim($label) !== '' ? trim($label) : (string) config('booking.default_resource_label');
    }

    /** @return array{singular: string, plural: string} */
    public function resourceLabels(): array
    {
        $singular = $this->resourceLabel();

        return ['singular' => $singular, 'plural' => Str::plural($singular)];
    }

    /**
     * @param  array{slot_interval?: int, auto_confirm?: bool, default_hours?: list<array{weekday: int, starts_at: string, ends_at: string}>, online?: array<string, mixed>}  $values
     */
    public function update(array $values, ?string $resourceLabel = null): void
    {
        $stored = [...$this->stored(), ...$values];
        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $stored]);
        $this->memo[$this->context->tenant()->id] = $stored;

        if ($resourceLabel !== null) {
            TenantSetting::query()->updateOrCreate(['key' => self::LABEL_KEY], ['value' => $resourceLabel]);
        }
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return $this->memo[$this->context->tenant()->id] ??= (function (): array {
            $value = $this->context->setting(self::KEY, []);

            return is_array($value) ? $value : [];
        })();
    }
}
