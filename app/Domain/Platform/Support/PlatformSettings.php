<?php

namespace App\Domain\Platform\Support;

use App\Domain\Platform\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/** Platform-wide settings with defaults from config('autowave.platform_settings'). Read on every request, so cached. */
class PlatformSettings
{
    public const REQUIRE_EMAIL_VERIFICATION = 'require_email_verification';

    private const CACHE_KEY = 'platform_settings';

    public function requireEmailVerification(): bool
    {
        return (bool) $this->get(self::REQUIRE_EMAIL_VERIFICATION);
    }

    public function get(string $key): mixed
    {
        $stored = Cache::remember(self::CACHE_KEY, 3600, fn () => PlatformSetting::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $stored) ? $stored[$key] : config("autowave.platform_settings.{$key}");
    }

    public function set(string $key, mixed $value): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }
}
