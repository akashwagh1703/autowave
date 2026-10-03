<?php

namespace App\Domain\Billing\Gateways;

/**
 * An online payment provider (BILLING_GATEWAY). Online payments are one-off payments per period, applied like
 * manual ones, so a business can move between manual and online payment at any time.
 *
 * Flow: createOrder() for the amount the server worked out → the browser pays in the provider's checkout →
 * the server checks the checkout signature (or a signed webhook) and the payment itself before applying it.
 */
interface PaymentGateway
{
    public function name(): string;

    /** All keys are present in .env. */
    public function configured(): bool;

    /** The key the provider's browser checkout needs (public by design; never the secret). */
    public function publicKey(): string;

    /**
     * @param  array<string, string|int>  $notes
     * @return string the provider's order id
     *
     * @throws GatewayException
     */
    public function createOrder(int $amount, string $receipt, array $notes): string;

    /** The signature the browser received after paying, for this order and payment. */
    public function verifyCheckout(string $orderId, string $paymentId, string $signature): bool;

    /** @throws GatewayException */
    public function fetchPayment(string $paymentId): GatewayPayment;

    /** Takes an authorised (not yet captured) payment. @throws GatewayException */
    public function capture(string $paymentId, int $amount): GatewayPayment;

    public function verifyWebhook(string $body, string $signature): bool;

    /** @param  array<string, mixed>  $payload */
    public function parseWebhook(array $payload): ?GatewayEvent;
}
