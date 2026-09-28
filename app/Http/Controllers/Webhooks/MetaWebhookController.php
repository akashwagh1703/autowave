<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Messaging\Jobs\ProcessWebhookCall;
use App\Domain\Messaging\Models\MessagingChannel;
use App\Domain\Messaging\Models\WebhookCall;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Meta webhooks for WhatsApp and Instagram (ADR-018). The URL's key picks the tenant's channel; the
 * signature proves the body came from that channel's Meta app. Verified bodies are stored and processed
 * on the queue, so Meta gets its 200 at once.
 */
class MetaWebhookController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /** Meta's subscription check: echo hub.challenge when hub.verify_token matches. */
    public function verify(Request $request, string $webhookKey): Response
    {
        $channel = $this->channel($webhookKey);
        // PHP turns "hub.mode" into "hub_mode" when parsing the query string.
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        abort_unless($mode === 'subscribe' && $channel->verifyToken() !== '' && hash_equals($channel->verifyToken(), $token), 403);

        return response(mb_substr($challenge, 0, 200), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, string $webhookKey): Response
    {
        $channel = $this->channel($webhookKey);
        $secret = $channel->credential('app_secret');
        $body = $request->getContent();

        abort_if(strlen($body) > (int) config('messaging.webhooks.max_payload_kb') * 1024, 413);

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        abort_unless($secret && hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature), 403);

        $payload = json_decode($body, true);
        abort_unless(is_array($payload), 400);

        $tenant = Tenant::query()->findOrFail($channel->tenant_id);

        $call = $this->context->run($tenant, function () use ($channel, $payload) {
            if (! $this->context->hasModule('messaging')) {
                return null;
            }

            $channel->forceFill(['last_webhook_at' => now()])->saveQuietly();

            return WebhookCall::query()->create([
                'messaging_channel_id' => $channel->id,
                'payload' => $payload,
                'status' => WebhookCall::PENDING,
            ]);
        });

        if ($call) {
            try {
                Bus::dispatch(new ProcessWebhookCall($call->id));
            } catch (Throwable $exception) {
                // Left pending; messaging:dispatch-pending picks it up.
                report($exception);
            }
        }

        return response('EVENT_RECEIVED', 200, ['Content-Type' => 'text/plain']);
    }

    private function channel(string $webhookKey): MessagingChannel
    {
        abort_unless(strlen($webhookKey) >= 32 && strlen($webhookKey) <= 64, 404);

        return MessagingChannel::withoutTenantScope()->where('webhook_key', $webhookKey)->first() ?? abort(404);
    }
}
