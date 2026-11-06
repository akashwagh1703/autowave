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

    /** Stored confirmation texts that still ask customers to “show QR at pickup” (or other retired copy). */
    private const STALE_CONFIRMATION_MARKERS = [
        'Show this QR when you collect your order',
        'show this QR when you collect',
        'Show this QR at pickup',
    ];

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

    /**
     * Customer WhatsApp for orders: payment request, confirmation after payment, and ready alerts.
     *
     * @return array{
     *     whatsapp: bool,
     *     include_qr: bool,
     *     qr_payload: string,
     *     upi_id: ?string,
     *     upi_payee: ?string,
     *     payment_message: string,
     *     message: string,
     *     ready_message_pickup: string,
     *     ready_message_delivery: string,
     *     ready_message: string
     * }
     */
    public function confirmation(): array
    {
        $stored = is_array($this->stored()['confirmation'] ?? null) ? $this->stored()['confirmation'] : [];
        $defaults = config('commerce.confirmation');
        $value = fn (string $key) => array_key_exists($key, $stored) ? $stored[$key] : $defaults[$key];
        $payload = (string) $value('qr_payload');

        if (! in_array($payload, ['status', 'reference', 'upi'], true)) {
            $payload = (string) $defaults['qr_payload'];
        }

        return [
            'whatsapp' => (bool) $value('whatsapp'),
            'include_qr' => (bool) $value('include_qr'),
            'qr_payload' => $payload,
            'upi_id' => filled($value('upi_id')) ? (string) $value('upi_id') : null,
            'upi_payee' => filled($value('upi_payee')) ? (string) $value('upi_payee') : null,
            'payment_message' => $this->messageOrDefault($value('payment_message'), (string) $defaults['payment_message']),
            'message' => $this->messageOrDefault($value('message'), (string) $defaults['message'], refreshStaleConfirmation: true),
            'ready_message_pickup' => $this->messageOrDefault($value('ready_message_pickup'), (string) $defaults['ready_message_pickup']),
            'ready_message_delivery' => $this->messageOrDefault($value('ready_message_delivery'), (string) $defaults['ready_message_delivery']),
            'ready_message' => $this->messageOrDefault($value('ready_message'), (string) $defaults['ready_message']),
        ];
    }

    /** Template used when an order is marked ready (pickup / delivery / other). */
    public function readyMessageFor(string $fulfilment): string
    {
        $confirmation = $this->confirmation();

        return match ($fulfilment) {
            'pickup' => $confirmation['ready_message_pickup'],
            'delivery' => $confirmation['ready_message_delivery'],
            default => $confirmation['ready_message'],
        };
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
        $this->persist(['online' => $online]);
    }

    /** @param  array<string, mixed>  $confirmation */
    public function updateConfirmation(array $confirmation): void
    {
        $this->persist(['confirmation' => $confirmation]);
    }

    /**
     * @param  array<string, mixed>  $online
     * @param  array<string, mixed>  $confirmation
     */
    public function update(array $online, array $confirmation): void
    {
        $this->persist(['online' => $online, 'confirmation' => $confirmation]);
    }

    /** @param  array<string, mixed>  $patch */
    private function persist(array $patch): void
    {
        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => [...$this->stored(), ...$patch]]);
        $this->context->tenant()->unsetRelation('settings');
    }

    public static function money(mixed $value): ?string
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : number_format(max(0, (float) $value), 2, '.', '');
    }

    private function messageOrDefault(mixed $stored, string $default, bool $refreshStaleConfirmation = false): string
    {
        $message = trim((string) $stored);

        if ($message === '') {
            return $default;
        }

        if ($refreshStaleConfirmation) {
            foreach (self::STALE_CONFIRMATION_MARKERS as $marker) {
                if (str_contains($message, $marker)) {
                    return $default;
                }
            }
        }

        return $message;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $value = $this->context->setting(self::KEY, []);

        return is_array($value) ? $value : [];
    }
}
