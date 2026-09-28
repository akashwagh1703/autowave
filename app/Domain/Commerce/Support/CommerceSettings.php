<?php

namespace App\Domain\Commerce\Support;

use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;

/**
 * Tenant commerce preferences: the `commerce` tenant setting over the config/commerce.php
 * defaults. Read fresh on every call (no memo), so it is safe to use across requests.
 */
class CommerceSettings
{
    public const KEY = 'commerce';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Online ordering from the public website.
     *
     * @return array{enabled: bool, auto_confirm: bool, pickup: bool, delivery: bool, delivery_fee: string, free_delivery_over: ?string, min_order: ?string, delivery_note: ?string}
     */
    public function online(): array
    {
        $stored = is_array($this->stored()['online'] ?? null) ? $this->stored()['online'] : [];
        $defaults = config('commerce.online');
        $value = fn (string $key) => array_key_exists($key, $stored) ? $stored[$key] : $defaults[$key];

        return [
            'enabled' => (bool) $value('enabled'),
            'auto_confirm' => (bool) $value('auto_confirm'),
            'pickup' => (bool) $value('pickup'),
            'delivery' => (bool) $value('delivery'),
            'delivery_fee' => self::money($value('delivery_fee')) ?? '0.00',
            'free_delivery_over' => self::money($value('free_delivery_over')),
            'min_order' => self::money($value('min_order')),
            'delivery_note' => filled($value('delivery_note')) ? (string) $value('delivery_note') : null,
        ];
    }

    /** @return list<string> fulfilment methods offered on the website */
    public function onlineFulfilment(): array
    {
        $online = $this->online();

        return array_values(array_filter(['pickup', 'delivery'], fn (string $method) => $online[$method]));
    }

    /** Delivery fee for a website order with this subtotal. */
    public function deliveryFeeFor(string $subtotal): string
    {
        $online = $this->online();

        if ($online['free_delivery_over'] !== null && bccomp($subtotal, $online['free_delivery_over'], 2) >= 0) {
            return '0.00';
        }

        return $online['delivery_fee'];
    }

    /** @param  array<string, mixed>  $online */
    public function updateOnline(array $online): void
    {
        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => [...$this->stored(), 'online' => $online]]);
    }

    public static function money(mixed $value): ?string
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : number_format(max(0, (float) $value), 2, '.', '');
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $value = $this->context->setting(self::KEY, []);

        return is_array($value) ? $value : [];
    }
}
