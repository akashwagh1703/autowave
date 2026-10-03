<?php

namespace Tests\Feature\Messaging;

use App\Domain\Activity\Models\Activity;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Jobs\DownloadInboundMedia;
use App\Domain\Messaging\Jobs\ProcessWebhookCall;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\WebhookCall;
use App\Domain\Messaging\Services\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Meta webhooks: verification, signatures, normalisation, idempotency and receipts (ADR-018). */
class MetaWebhookTest extends TestCase
{
    use CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_the_subscription_check_echoes_the_challenge_only_with_the_right_token(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);
        $url = $this->appUrl("/webhooks/meta/{$channel->webhook_key}");

        $this->get($url.'?hub.mode=subscribe&hub.verify_token='.$channel->verifyToken().'&hub.challenge=1158201444')
            ->assertOk()
            ->assertSeeText('1158201444')
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->get($url.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1')->assertForbidden();
        $this->get($url.'?hub.mode=unsubscribe&hub.verify_token='.$channel->verifyToken().'&hub.challenge=1')->assertForbidden();
        $this->get($this->appUrl('/webhooks/meta/'.str_repeat('x', 40).'?hub.mode=subscribe&hub.verify_token=a&hub.challenge=1'))->assertNotFound();
        $this->get($this->appUrl('/webhooks/meta/short'))->assertNotFound();
    }

    public function test_unsigned_or_wrongly_signed_bodies_are_refused(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);
        $payload = $this->whatsappText('919876543210', 'Hi', 'wamid.1');

        $this->postWebhook($channel, $payload, secret: null)->assertForbidden();
        $this->postWebhook($channel, $payload, secret: 'another-apps-secret')->assertForbidden();

        $this->assertSame(0, WebhookCall::withoutTenantScope()->count());
        $this->assertSame(0, ConversationMessage::withoutTenantScope()->count());
    }

    public function test_an_inbound_message_creates_a_lead_a_conversation_and_a_timeline_entry(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);

        $this->postWebhook($channel, $this->whatsappText('919876543210', 'Do you have a slot tomorrow?', 'wamid.abc', 'Priya Sharma'))
            ->assertOk()
            ->assertSeeText('EVENT_RECEIVED');

        $this->inTenant($tenant, function () use ($channel) {
            $call = WebhookCall::query()->sole();
            $this->assertSame(WebhookCall::PROCESSED, $call->status);
            $this->assertNotNull($channel->refresh()->last_webhook_at);

            $conversation = Conversation::query()->sole();
            $this->assertSame(['whatsapp', '+919876543210', 'Priya Sharma', 1], [$conversation->channel, $conversation->contact_handle, $conversation->contact_name, $conversation->unread_count]);
            $this->assertSame('Do you have a slot tomorrow?', $conversation->last_message_preview);
            $this->assertNotNull($conversation->last_inbound_at);

            $lead = Lead::query()->sole();
            $this->assertSame(['Priya Sharma', '+919876543210', 'whatsapp'], [$lead->name, $lead->phone_normalized, $lead->source?->code]);
            $this->assertSame($lead->id, $conversation->lead_id);

            $activity = Activity::query()->where('lead_id', $lead->id)->where('type', 'whatsapp')->sole();
            $this->assertSame('inbound', $activity->metadata['direction']);
            $this->assertSame('Priya Sharma', $activity->metadata['from_name']);
            $this->assertSame('Do you have a slot tomorrow?', $activity->body);
        });
    }

    public function test_a_known_customer_is_linked_instead_of_creating_a_lead(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant, ['name' => 'Asha', 'phone' => '9876543210']);
        $channel = $this->connectWhatsApp($tenant);

        $this->postWebhook($channel, $this->whatsappText('919876543210', 'Hi again', 'wamid.c1'))->assertOk();

        $this->inTenant($tenant, function () use ($customer) {
            $this->assertSame($customer->id, Conversation::query()->sole()->customer_id);
            $this->assertSame(0, Lead::query()->count());
            $this->assertSame(1, Activity::query()->where('customer_id', $customer->id)->where('type', 'whatsapp')->count());
        });
    }

    public function test_redelivered_webhooks_store_each_message_once(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);
        $payload = $this->whatsappText('919876543210', 'Hello', 'wamid.same');

        $this->postWebhook($channel, $payload)->assertOk();
        $this->postWebhook($channel, $payload)->assertOk();

        $this->inTenant($tenant, function () {
            $this->assertSame(2, WebhookCall::query()->count());
            $this->assertSame(1, ConversationMessage::query()->count());
            $this->assertSame(1, Conversation::query()->sole()->unread_count);
            $this->assertSame(1, Lead::query()->count());
        });
    }

    public function test_entries_for_another_number_and_reactions_are_ignored(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);

        $this->postWebhook($channel, $this->whatsappPayload(
            ['messages' => [['from' => '919876543210', 'id' => 'wamid.other', 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => 'x']]]],
            phoneNumberId: '9999999999',
        ))->assertOk();
        $this->postWebhook($channel, $this->whatsappPayload(['messages' => [['from' => '919876543210', 'id' => 'wamid.r', 'timestamp' => (string) now()->getTimestamp(), 'type' => 'reaction', 'reaction' => ['emoji' => '👍']]]]))->assertOk();

        $this->assertSame(0, ConversationMessage::withoutTenantScope()->count());
    }

    public function test_media_messages_are_stored_as_placeholders_and_queued_for_download(): void
    {
        Bus::fake([DownloadInboundMedia::class]);
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);

        $this->postWebhook($channel, $this->whatsappPayload([
            'messages' => [['from' => '919876543210', 'id' => 'wamid.img', 'timestamp' => (string) now()->getTimestamp(), 'type' => 'image', 'image' => ['id' => 'media-1', 'caption' => 'My hair']]],
        ]))->assertOk();

        $message = ConversationMessage::withoutTenantScope()->sole();
        $this->assertSame(['image', '[Image] My hair'], [$message->type, $message->body]);
        $this->assertSame(['id' => 'media-1', 'status' => 'pending'], array_filter($message->meta['media']));
        Bus::assertDispatched(DownloadInboundMedia::class, fn (DownloadInboundMedia $job) => $job->messageId === $message->id);
    }

    public function test_stop_opts_the_contact_out_and_start_opts_them_back_in(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);

        $this->postWebhook($channel, $this->whatsappText('919876543210', 'stop', 'wamid.s1'))->assertOk();
        $conversation = Conversation::withoutTenantScope()->sole();
        $this->assertTrue($conversation->isOptedOut());
        $this->assertSame('out', Activity::withoutTenantScope()->where('type', 'whatsapp')->sole()->metadata['opt_out']);

        $this->postWebhook($channel, $this->whatsappText('919876543210', 'Start', 'wamid.s2'))->assertOk();
        $this->assertFalse($conversation->refresh()->isOptedOut());

        // "Stop by tomorrow?" is a question, not an opt-out.
        $this->postWebhook($channel, $this->whatsappText('919876543210', 'Can I stop by tomorrow?', 'wamid.s3'))->assertOk();
        $this->assertFalse($conversation->refresh()->isOptedOut());
    }

    public function test_delivery_receipts_only_move_forward(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out1']]])]);
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);
        $conversation = $this->receive($tenant, '9876543210');
        $message = $this->inTenant($tenant, fn () => app(MessagingService::class)->queue([
            'channel' => 'whatsapp', 'recipient' => '+919876543210', 'body' => 'Hi', 'idempotency_key' => 'test:1', 'conversation_id' => $conversation->id,
        ]));
        $this->assertSame([MessageStatus::Sent, 'wamid.out1'], [$message->refresh()->status, $message->provider_message_id]);

        $this->postWebhook($channel, $this->whatsappStatus('wamid.out1', 'read', $message->id))->assertOk();
        $this->assertSame(MessageStatus::Read, $message->refresh()->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertNotNull($message->read_at);

        // A late "delivered" receipt does not move it back.
        $this->postWebhook($channel, $this->whatsappStatus('wamid.out1', 'delivered', $message->id))->assertOk();
        $this->assertSame(MessageStatus::Read, $message->refresh()->status);
    }

    public function test_a_failed_receipt_marks_the_message_failed_with_metas_reason(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out2']]])]);
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);
        $this->receive($tenant, '9876543210');
        $message = $this->inTenant($tenant, fn () => app(MessagingService::class)->queue([
            'channel' => 'whatsapp', 'recipient' => '+919876543210', 'body' => 'Hi', 'idempotency_key' => 'test:2',
        ]));

        $this->postWebhook($channel, $this->whatsappStatus('wamid.out2', 'failed', $message->id, [
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message', 'error_data' => ['details' => 'More than 24 hours have passed.']]],
        ]))->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('Re-engagement message', $message->error);
    }

    public function test_instagram_messages_arrive_in_the_same_inbox(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectInstagram($tenant, '17840000000000001');
        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17840000000000001',
                'time' => now()->getTimestampMs(),
                'messaging' => [
                    ['sender' => ['id' => '9988776655'], 'recipient' => ['id' => '17840000000000001'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'ig.mid.1', 'text' => 'Price for a haircut?']],
                    // Our own reply echoed back: ignored.
                    ['sender' => ['id' => '17840000000000001'], 'recipient' => ['id' => '9988776655'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'ig.mid.2', 'text' => 'echo', 'is_echo' => true]],
                ],
            ]],
        ];

        $this->postWebhook($channel, $payload)->assertOk();

        $this->inTenant($tenant, function () {
            $conversation = Conversation::query()->sole();
            $this->assertSame(['instagram', '9988776655'], [$conversation->channel, $conversation->contact_handle]);
            $this->assertSame('Price for a haircut?', ConversationMessage::query()->sole()->body);
            // No phone number: a lead is still created so the contact can be followed up.
            $lead = Lead::query()->sole();
            $this->assertNull($lead->phone_normalized);
            $this->assertSame('instagram', $lead->source?->code);
        });
    }

    public function test_webhooks_for_a_business_without_messaging_are_acknowledged_and_dropped(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);
        $this->disableModule($tenant, 'messaging');

        $this->postWebhook($channel, $this->whatsappText('919876543210', 'Hi', 'wamid.off'))->assertOk();

        $this->assertSame(0, WebhookCall::withoutTenantScope()->count());
    }

    public function test_calls_are_stored_before_processing_and_retried_by_the_dispatcher(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);
        Queue::fake();

        $this->postWebhook($channel, $this->whatsappText('919876543210', 'Hi', 'wamid.q'))->assertOk();
        $call = WebhookCall::withoutTenantScope()->sole();
        $this->assertSame(WebhookCall::PENDING, $call->status);
        $this->assertSame(0, ConversationMessage::withoutTenantScope()->count());

        // A worker outage leaves it pending; the dispatcher picks it up once it is stuck.
        $this->artisan('messaging:dispatch-pending')->assertSuccessful();
        Queue::assertPushed(ProcessWebhookCall::class, 1);

        WebhookCall::withoutTenantScope()->whereKey($call->id)->update(['updated_at' => now()->subMinutes(30)]);
        $this->artisan('messaging:dispatch-pending')->assertSuccessful();
        Queue::assertPushed(ProcessWebhookCall::class, 2);
    }

    public function test_old_webhook_calls_are_pruned(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);
        $this->postWebhook($channel, $this->whatsappText('919876543210', 'Old', 'wamid.old'))->assertOk();
        $this->postWebhook($channel, $this->whatsappText('919876543210', 'New', 'wamid.new'))->assertOk();
        $old = WebhookCall::withoutTenantScope()->orderBy('id')->first();
        WebhookCall::withoutTenantScope()->whereKey($old->id)->update(['created_at' => now()->subDays(30)]);

        $this->artisan('messaging:prune-webhooks')->assertSuccessful();

        $this->assertSame(1, WebhookCall::withoutTenantScope()->count());
        $this->assertSame(2, ConversationMessage::withoutTenantScope()->count());
    }
}
