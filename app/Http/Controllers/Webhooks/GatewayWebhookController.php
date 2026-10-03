<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Jobs\ProcessGatewayEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Payment gateway webhooks for AutoWave plans. The signature over the raw body (webhook secret from .env)
 * proves the gateway sent it; verified events are processed on the queue. Unknown event types get a 200 so
 * the gateway stops retrying them.
 */
class GatewayWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 262144;

    public function __invoke(Request $request, PaymentGateways $gateways, string $gateway): Response
    {
        $current = $gateways->current();
        abort_unless($current && $current->name() === $gateway && $current->configured(), 404);

        $body = $request->getContent();
        abort_if(strlen($body) > self::MAX_BODY_BYTES, 413);
        abort_unless($current->verifyWebhook($body, (string) $request->header('X-Razorpay-Signature', '')), 403);

        $payload = json_decode($body, true);
        abort_unless(is_array($payload), 400);

        $event = $current->parseWebhook($payload);

        if ($event) {
            ProcessGatewayEvent::dispatch($event);
        }

        return response('OK', 200, ['Content-Type' => 'text/plain']);
    }
}
