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
     * @param  array{slot_interval?: int, auto_confirm?: bool, default_hours?: list<array{weekday: int, starts_at: string, ends_at: string}>}  $values
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
