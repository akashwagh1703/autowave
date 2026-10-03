<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Billing\Gateways\GatewayEvent;
use App\Domain\Billing\Gateways\GatewayException;
use App\Domain\Billing\Gateways\GatewayPayment;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Paying for a plan online. start() saves the payment as `initiated` with the server's amount and opens a
 * gateway order for exactly that; the browser pays in the gateway's checkout. The payment is applied by
 * complete(), reached from the checkout callback (signature checked) or a signed webhook, whichever comes
 * first. complete() fetches the payment from the gateway and requires it captured, for this order, amount
 * and currency, then settles it once under a row lock. Later calls change nothing.
 */
class OnlineCheckout
{
    public function __construct(
        private readonly ManagePayments $payments,
        private readonly PaymentGateways $gateways,
        private readonly BillingSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  plan, period, buyer_gstin, coupon
     * @return array{payment: BillingPayment, checkout: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function start(Tenant $tenant, User $actor, array $input): array
    {
        $gateway = $this->gateway();
        $input['buyer_gstin'] = ManagePayments::normalizeGstin($input['buyer_gstin'] ?? null);

        $data = Validator::make($input, [
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('is_public', true)->where('is_active', true)],
            'period' => ['required', Rule::in(array_keys(config('billing.periods')))],
            'buyer_gstin' => ['nullable', 'regex:'.ManagePayments::GSTIN_PATTERN],
            'coupon' => ['nullable', 'string', 'max:30'],
        ], [
            'buyer_gstin.regex' => __('Enter a valid 15-character GSTIN, or leave it empty.'),
        ])->validate();

        $this->payments->ensureNoPending($tenant);
        $plan = Plan::query()->where('code', $data['plan'])->firstOrFail();

        $payment = DB::transaction(function () use ($tenant, $actor, $plan, $data, $gateway) {
            $this->payments->expireCheckouts($tenant);
            $quote = $this->payments->quoteForPayment($tenant, $plan, $data['period'], $data['buyer_gstin'], $data['coupon'] ?? null, lock: true);

            if ($quote->total === 0) {
                throw ValidationException::withMessages(['coupon' => __('This coupon covers the whole price, so there is nothing to pay. Use "Activate" instead.')]);
            }

            if ($quote->total < (int) config('billing.online_minimum')) {
                throw ValidationException::withMessages(['method' => __('This amount is too small to pay online. Pay by UPI or bank transfer instead.')]);
            }

            return BillingPayment::withoutTenantScope()->create([
                ...$quote->paymentAttributes(),
                'tenant_id' => $tenant->getKey(),
                'method' => 'online',
                'status' => BillingPayment::INITIATED,
                'gateway' => $gateway->name(),
                'buyer_gstin' => $data['buyer_gstin'],
                'submitted_by_user_id' => $actor->getKey(),
            ]);
        });

        try {
            $orderId = $gateway->createOrder($payment->total, 'AW-PAY-'.$payment->id, [
                'payment_id' => $payment->id,
                'tenant_id' => $tenant->getKey(),
                'reference' => BillingSettings::paymentReference($tenant->getKey()),
            ]);
        } catch (GatewayException $exception) {
            report($exception);
            $payment->update(['status' => BillingPayment::EXPIRED]);

            throw ValidationException::withMessages(['method' => __('Online payment is not available right now. Try again in a few minutes, or pay by UPI or bank transfer.')]);
        }

        $payment->update(['gateway_order_id' => $orderId]);
        $this->audit->log('billing.checkout_started', $payment, ['plan' => $plan->code, 'period' => $payment->period, 'total' => $payment->total, 'coupon' => $payment->coupon_code], $tenant->getKey());

        return [
            'payment' => $payment,
            'checkout' => [
                'payment_id' => $payment->id,
                'gateway' => $gateway->name(),
                'key' => $gateway->publicKey(),
                'order_id' => $orderId,
                'amount' => $payment->total,
                'currency' => config('billing.currency'),
                'name' => $this->settings->get('seller.name') ?: config('app.name'),
                'description' => __(':plan plan (:period)', ['plan' => $plan->name, 'period' => strtolower((string) config("billing.periods.{$payment->period}.label"))]),
                'prefill' => ['name' => $actor->name, 'email' => $actor->email],
                'notes' => ['reference' => BillingSettings::paymentReference($tenant->getKey())],
            ],
        ];
    }

    /**
     * The checkout's success callback from the owner's browser.
     *
     * @throws ValidationException
     */
    public function confirm(BillingPayment $payment, string $orderId, string $gatewayPaymentId, string $signature): BillingPayment
    {
        try {
            $gateway = $this->gatewayFor($payment);
        } catch (GatewayException $exception) {
            report($exception);

            throw ValidationException::withMessages(['payment' => __('Your payment is still being confirmed by the bank. We will email you as soon as it is done; there is no need to pay again.')]);
        }

        if ($payment->gateway_order_id === null || ! hash_equals($payment->gateway_order_id, $orderId) || ! $gateway->verifyCheckout($orderId, $gatewayPaymentId, $signature)) {
            $this->audit->log('billing.checkout_unverified', $payment, ['gateway_payment_id' => mb_substr($gatewayPaymentId, 0, 100)], $payment->tenant_id);

            throw ValidationException::withMessages(['payment' => __('We could not confirm this payment here. If money left your account, it will be confirmed automatically within a few minutes; otherwise contact AutoWave support.')]);
        }

        try {
            $completed = $this->complete($orderId, $gatewayPaymentId, 'checkout');
        } catch (GatewayException $exception) {
            report($exception);
            $completed = null;
        }

        if (! $completed || $completed->status !== BillingPayment::APPROVED) {
            throw ValidationException::withMessages(['payment' => __('Your payment is still being confirmed by the bank. We will email you as soon as it is done; there is no need to pay again.')]);
        }

        return $completed;
    }

    /** A signed webhook event. @throws GatewayException so the webhook is retried */
    public function handle(GatewayEvent $event): void
    {
        if ($event->type === GatewayEvent::PAID) {
            $this->complete($event->orderId, $event->paymentId, 'webhook');

            return;
        }

        BillingPayment::withoutTenantScope()
            ->where('gateway_order_id', $event->orderId)
            ->where('status', BillingPayment::INITIATED)
            ->update(['note' => mb_substr(__('Last attempt failed: :reason', ['reason' => $event->error ?: __('declined')]), 0, 500), 'updated_at' => now()]);
    }

    /**
     * Applies the gateway payment for this order, once. Null when the order is unknown, or the money isn't
     * captured yet or doesn't match (the payment stays as it was; a mismatch is logged for Super Admin).
     *
     * @throws GatewayException
     */
    public function complete(string $orderId, string $gatewayPaymentId, string $source): ?BillingPayment
    {
        $payment = BillingPayment::withoutTenantScope()->where('gateway_order_id', $orderId)->first();

        if (! $payment || $payment->status === BillingPayment::APPROVED) {
            return $payment;
        }

        if (! in_array($payment->status, [BillingPayment::INITIATED, BillingPayment::EXPIRED], true)) {
            return null;
        }

        $gateway = $this->gatewayFor($payment);
        $remote = $gateway->fetchPayment($gatewayPaymentId);

        if (! $this->matches($payment, $remote, $orderId)) {
            Log::warning('Online payment does not match its order', ['payment_id' => $payment->id, 'order_id' => $orderId, 'gateway_payment_id' => $remote->id, 'amount' => $remote->amount, 'status' => $remote->status]);
            $this->audit->log('billing.online_payment_mismatch', $payment, ['gateway_payment_id' => $remote->id, 'amount' => $remote->amount, 'currency' => $remote->currency, 'order_id' => $remote->orderId], $payment->tenant_id);

            return null;
        }

        if ($remote->status === GatewayPayment::AUTHORIZED) {
            $remote = $gateway->capture($remote->id, $payment->total);
        }

        if (! $remote->captured() || ! $this->matches($payment, $remote, $orderId)) {
            return null;
        }

        [$payment, $settled] = DB::transaction(function () use ($payment, $remote, $source) {
            $locked = BillingPayment::withoutTenantScope()->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($locked->status, [BillingPayment::INITIATED, BillingPayment::EXPIRED], true)) {
                return [$locked, false];
            }

            $this->payments->settle($locked, [
                'gateway_payment_id' => $remote->id,
                'paid_on' => now()->timezone('Asia/Kolkata')->toDateString(),
                'reviewed_at' => now(),
                'note' => null,
            ]);
            $this->audit->log('billing.online_payment_received', $locked, [
                'gateway_payment_id' => $remote->id,
                'total' => $locked->total,
                'source' => $source,
                'until' => $locked->covers_until->toIso8601String(),
            ], $locked->tenant_id);

            return [$locked, true];
        });

        if ($settled) {
            $this->payments->notifyOwners($payment);
        }

        return $payment;
    }

    /** Checkouts nobody finished within config('billing.checkout_expiry_hours'). */
    public function expireStale(): int
    {
        return BillingPayment::withoutTenantScope()
            ->where('status', BillingPayment::INITIATED)
            ->where('created_at', '<', now()->subHours((int) config('billing.checkout_expiry_hours')))
            ->update(['status' => BillingPayment::EXPIRED, 'updated_at' => now()]);
    }

    private function matches(BillingPayment $payment, GatewayPayment $remote, string $orderId): bool
    {
        return $remote->orderId === $orderId
            && $remote->amount === $payment->total
            && strtoupper($remote->currency) === config('billing.currency');
    }

    /** @throws ValidationException */
    private function gateway(): PaymentGateway
    {
        $gateway = $this->gateways->current();

        if (! $gateway || ! $this->settings->onlineEnabled()) {
            throw ValidationException::withMessages(['method' => __('Online payment is switched off. Pay by UPI or bank transfer instead.')]);
        }

        return $gateway;
    }

    /** The gateway the payment was made with, still configured (online may since be switched off). */
    private function gatewayFor(BillingPayment $payment): PaymentGateway
    {
        $gateway = $this->gateways->current();

        if (! $gateway || ! $gateway->configured() || $gateway->name() !== $payment->gateway) {
            throw new GatewayException("The {$payment->gateway} gateway is no longer configured.");
        }

        return $gateway;
    }
}
