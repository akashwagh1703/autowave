<?php

namespace App\Domain\Billing\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay Orders + Checkout. The server creates an order for the exact amount, Checkout takes the money,
 * and the payment is accepted only after the HMAC signature (key secret over "order_id|payment_id", or the
 * webhook secret over the raw webhook body) checks out and the payment fetched from the API is captured
 * for that order and amount.
 */
class RazorpayGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'razorpay';
    }

    public function configured(): bool
    {
        return filled($this->key('key_id')) && filled($this->key('key_secret')) && filled($this->key('webhook_secret'));
    }

    public function publicKey(): string
    {
        return (string) $this->key('key_id');
    }

    public function createOrder(int $amount, string $receipt, array $notes): string
    {
        $order = $this->send(fn (PendingRequest $http) => $http->post('/orders', [
            'amount' => $amount,
            'currency' => config('billing.currency'),
            'receipt' => mb_substr($receipt, 0, 40),
            'notes' => $notes,
        ]));

        if (! is_string($order['id'] ?? null)) {
            throw new GatewayException('Razorpay returned an order without an id.');
        }

        return $order['id'];
    }

    public function verifyCheckout(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) $this->key('key_secret');

        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $secret), $signature);
    }

    public function fetchPayment(string $paymentId): GatewayPayment
    {
        return $this->payment($this->send(fn (PendingRequest $http) => $http->get('/payments/'.rawurlencode($paymentId))));
    }

    public function capture(string $paymentId, int $amount): GatewayPayment
    {
        return $this->payment($this->send(fn (PendingRequest $http) => $http->post('/payments/'.rawurlencode($paymentId).'/capture', [
            'amount' => $amount,
            'currency' => config('billing.currency'),
        ])));
    }

    public function verifyWebhook(string $body, string $signature): bool
    {
        $secret = (string) $this->key('webhook_secret');

        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }

    public function parseWebhook(array $payload): ?GatewayEvent
    {
        $type = match ($payload['event'] ?? null) {
            'payment.captured', 'payment.authorized', 'order.paid' => GatewayEvent::PAID,
            'payment.failed' => GatewayEvent::FAILED,
            default => null,
        };
        $payment = $payload['payload']['payment']['entity'] ?? null;

        if ($type === null || ! is_array($payment) || ! is_string($payment['id'] ?? null) || ! is_string($payment['order_id'] ?? null)) {
            return null;
        }

        return new GatewayEvent(
            $type,
            $payment['order_id'],
            $payment['id'],
            (int) ($payment['amount'] ?? 0),
            (string) ($payment['currency'] ?? ''),
            isset($payment['error_description']) ? mb_substr((string) $payment['error_description'], 0, 200) : null,
        );
    }

    /** @param  array<string, mixed>  $data */
    private function payment(array $data): GatewayPayment
    {
        if (! is_string($data['id'] ?? null)) {
            throw new GatewayException('Razorpay returned a payment without an id.');
        }

        return new GatewayPayment(
            $data['id'],
            is_string($data['order_id'] ?? null) ? $data['order_id'] : null,
            (string) ($data['status'] ?? ''),
            (int) ($data['amount'] ?? 0),
            (string) ($data['currency'] ?? ''),
            isset($data['method']) ? (string) $data['method'] : null,
            isset($data['error_description']) ? mb_substr((string) $data['error_description'], 0, 200) : null,
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            $response = $call(Http::baseUrl((string) $this->key('api_url'))
                ->withBasicAuth((string) $this->key('key_id'), (string) $this->key('key_secret'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) ($this->key('timeout') ?: 15)));
        } catch (ConnectionException $exception) {
            throw new GatewayException('Razorpay could not be reached: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            $error = $response->json('error.description') ?? $response->json('error.code') ?? 'HTTP '.$response->status();

            throw new GatewayException('Razorpay refused the request: '.mb_substr((string) $error, 0, 200));
        }

        return (array) $response->json();
    }

    private function key(string $name): mixed
    {
        return config("billing.gateways.razorpay.{$name}");
    }
}
