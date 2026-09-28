<?php

namespace App\Domain\Education\Support;

use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;

/** Tenant education preferences: the `education` tenant setting over the config/education.php defaults. */
class EducationSettings
{
    public const KEY = 'education';

    /** @var array<int, array<string, mixed>> keyed by tenant id */
    private array $memo = [];

    public function __construct(private readonly TenantContext $context) {}

    public function defaultInstalments(): int
    {
        $count = (int) ($this->stored()['default_instalments'] ?? config('education.default_instalments'));

        return max(1, min($count, (int) config('education.max_instalments')));
    }

    public function reminderDaysBefore(): int
    {
        $days = (int) ($this->stored()['reminder_days_before'] ?? config('education.reminder_days_before'));

        return in_array($days, config('education.reminder_days_options'), true) ? $days : (int) config('education.reminder_days_before');
    }

    /** @return array{default_instalments: int, reminder_days_before: int} */
    public function all(): array
    {
        return ['default_instalments' => $this->defaultInstalments(), 'reminder_days_before' => $this->reminderDaysBefore()];
    }

    /** @param  array{default_instalments?: int, reminder_days_before?: int}  $values */
    public function update(array $values): void
    {
        $stored = [...$this->stored(), ...$values];
        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $stored]);
        $this->memo[$this->context->tenant()->id] = $stored;
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
