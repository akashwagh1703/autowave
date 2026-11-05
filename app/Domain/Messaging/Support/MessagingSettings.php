<?php

namespace App\Domain\Messaging\Support;

use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\Phone;

/**
 * Tenant messaging preferences: the `messaging` tenant setting over the config/messaging.php defaults.
 * Read fresh on every call (no memo), so it is safe inside long-lived workers.
 */
class MessagingSettings
{
    public const KEY = 'messaging';

    public const CHAT_GREETING = 'Hi';

    public function __construct(private readonly TenantContext $context) {}

    /** @return array{enabled: bool, start: string, end: string} times are HH:MM in the tenant's timezone */
    public function quietHours(): array
    {
        $stored = is_array($this->stored()['quiet_hours'] ?? null) ? $this->stored()['quiet_hours'] : [];
        $defaults = config('messaging.quiet_hours');
        $time = fn (string $key) => self::validTime($stored[$key] ?? null) ? $stored[$key] : $defaults[$key];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'start' => $time('start'),
            'end' => $time('end'),
        ];
    }

    /** @return array{from_name: ?string, reply_to: ?string} */
    public function email(): array
    {
        $stored = is_array($this->stored()['email'] ?? null) ? $this->stored()['email'] : [];

        return [
            'from_name' => filled($stored['from_name'] ?? null) ? (string) $stored['from_name'] : null,
            'reply_to' => filled($stored['reply_to'] ?? null) ? (string) $stored['reply_to'] : null,
        ];
    }

    /** Email the owners about new website enquiries, bookings, orders and reservations (on by default). */
    public function ownerAlerts(): bool
    {
        return (bool) ($this->stored()['owner_alerts'] ?? true);
    }

    /** Public WhatsApp number for the counter QR (customers scan to chat). */
    public function publicWhatsapp(): ?string
    {
        $stored = $this->stored()['public_whatsapp'] ?? null;

        return filled($stored) ? (string) $stored : null;
    }

    /**
     * wa.me link with a short Hi greeting. Uses the saved public number, or a fallback handle
     * (usually the connected WhatsApp display number).
     */
    public function whatsappChatUrl(?string $fallbackHandle = null): ?string
    {
        $normalized = Phone::normalize($this->publicWhatsapp() ?: $fallbackHandle);

        if (! $normalized) {
            return null;
        }

        return 'https://wa.me/'.ltrim($normalized, '+').'?text='.rawurlencode(self::CHAT_GREETING);
    }

    /**
     * @param  array{enabled: bool, start: string, end: string}  $quietHours
     * @param  array{from_name: ?string, reply_to: ?string}  $email
     */
    public function update(array $quietHours, array $email, ?bool $ownerAlerts = null, ?string $publicWhatsapp = null): void
    {
        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => [
            ...$this->stored(),
            'quiet_hours' => $quietHours,
            'email' => $email,
            'owner_alerts' => $ownerAlerts ?? $this->ownerAlerts(),
            'public_whatsapp' => $publicWhatsapp,
        ]]);
    }

    /** Seeds the counter QR number from the connected WhatsApp display handle when empty. */
    public function seedPublicWhatsappFrom(?string $displayHandle): void
    {
        if ($this->publicWhatsapp() || ! Phone::normalize($displayHandle)) {
            return;
        }

        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => [
            ...$this->stored(),
            'public_whatsapp' => Phone::normalize($displayHandle),
        ]]);
    }

    public static function validTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $value = $this->context->setting(self::KEY, []);

        return is_array($value) ? $value : [];
    }
}
