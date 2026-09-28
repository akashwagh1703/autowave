<?php

namespace Tests\Feature\Messaging;

use App\Domain\Automation\Models\AutomationLog;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Support\MessagingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Providers (Http::fake), the 24-hour window, templates, opt-out and quiet hours (ADR-018). */
class MessageDeliveryTest extends TestCase
{
    use CreatesAutomations, CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_without_a_connected_channel_messages_are_simulated_and_threaded(): void
    {
        Http::fake();
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant, ['name' => 'Priya', 'phone' => '9876543210']);

        $message = $this->inTenant($tenant, fn () => app(MessagingService::class)->queue([
            'channel' => 'whatsapp', 'recipient' => '+919876543210', 'body' => 'Hello', 'idempotency_key' => 'sim:1', 'lead_id' => $lead->id,
        ]));

        $message->refresh();
        $this->assertSame(['log', true, MessageStatus::Sent], [$message->provider, $message->simulated, $message->status]);
        $this->assertNotNull($message->conversation_id);
        $entry = ConversationMessage::withoutTenantScope()->sole();
        $this->assertSame([ConversationMessage::OUTBOUND, $message->id, 'Hello'], [$entry->direction, $entry->outbound_message_id, $entry->body]);
        $this->assertSame($lead->id, $message->conversation()->withoutGlobalScopes()->first()->lead_id);
        Http::assertNothingSent();
    }

    public function test_the_whatsapp_provider_sends_text_through_the_cloud_api(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);
        $tenant = $this->createTenant();
        $this->connectWhatsApp($tenant, phoneNumberId: '1111111111');
        $this->receive($tenant, '9876543210');

        $message = $this->inTenant($tenant, fn () => app(MessagingService::class)->queue([
            'channel' => 'whatsapp', 'recipient' => '+919876543210', 'body' => 'Your table is ready', 'idempotency_key' => 'wa:1',
        ]));

        $message->refresh();
        $this->assertSame(['meta_whatsapp', false, MessageStatus::Sent, 'wamid.sent'], [$message->provider, $message->simulated, $message->status, $message->provider_message_id]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/'.config('messaging.meta.graph_version').'/1111111111/messages'
            && $request->hasHeader('Authorization', 'Bearer wa-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '919876543210'
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Your table is ready'
            && $request['biz_opaque_callback_data'] === (string) $message->id);
    }

    public function test_the_whatsapp_provider_sends_templates_with_body_parameters(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.tpl']]])]);
        $tenant = $this->createTenant();
        $this->connectWhatsApp($tenant);

        $this->inTenant($tenant, fn () => app(MessagingService::class)->queue([
            'channel' => 'whatsapp', 'recipient' => '+919876543210', 'body' => 'Hi Priya, see you on Monday.', 'idempotency_key' => 'wa:tpl',
            'template' => ['name' => 'appointment_reminder', 'language' => 'en', 'params' => ['Priya', 'Monday']],
        ]));

        Http::assertSent(fn (Request $request) => $request['type'] === 'template'
            && $request['template']['name'] === 'appointment_reminder'
            && $request['template']['language'] === ['code' => 'en']
            && $request['template']['components'] === [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Priya'], ['type' => 'text', 'text' => 'Monday']]]]);
        $this->assertSame('template', ConversationMessage::withoutTenantScope()->sole()->type);
    }

    public function test_a_permanent_meta_error_fails_the_message_without_retrying(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Recipient phone number not in allowed list', 'code' => 131030]], 400)]);
        $tenant = $this->createTenant();
        $this->connectWhatsApp($tenant);
        $this->receive($tenant, '9876543210');

        $message = $this->inTenant($tenant, fn () => app(MessagingService::class)->queue([
            'channel' => 'whatsapp', 'recipient' => '+919876543210', 'body' => 'Hi', 'idempotency_key' => 'wa:bad',
        ]));

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertStringContainsString('not in allowed list', $message->error);
        Http::assertSentCount(1);
    }

    public function test_the_instagram_provider_replies_by_user_id(): void
    {
        Http::fake(['graph.instagram.com/*' => Http::response(['recipient_id' => '9988776655', 'message_id' => 'ig.sent'])]);
        $tenant = $this->createTenant();
        $this->connectInstagram($tenant, '17840000000000001');
        $conversation = $this->receive($tenant, '9988776655', 'Hi', ['channel' => 'instagram']);

        $message = $this->inTenant($tenant, fn () => app(MessagingService::class)->queue([
            'channel' => 'instagram', 'recipient' => '9988776655', 'body' => 'Hello from us', 'idempotency_key' => 'ig:1', 'conversation_id' => $conversation->id,
        ]));

        $this->assertSame([MessageStatus::Sent, 'ig.sent'], [$message->refresh()->status, $message->provider_message_id]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/17840000000000001/messages')
            && $request['recipient'] === ['id' => '9988776655']
            && $request['message'] === ['text' => 'Hello from us']
            && $request->hasHeader('Authorization', 'Bearer ig-token'));
    }

    public function test_quiet_hours_delay_automation_messages_but_not_replies(): void
    {
        Http::fake();
        $tenant = $this->createTenant();
        $tenant->forceFill(['timezone' => 'Asia/Kolkata'])->save();
        $this->inTenant($tenant, fn () => app(MessagingSettings::class)->update(['enabled' => true, 'start' => '21:00', 'end' => '09:00'], ['from_name' => null, 'reply_to' => null]));
        $this->travelTo(CarbonImmutable::parse('2026-10-05 22:30', 'Asia/Kolkata'));

        [$automation, $reply] = $this->inTenant($tenant, fn () => [
            app(MessagingService::class)->queue(['channel' => 'whatsapp', 'recipient' => '+919876543210', 'body' => 'Reminder', 'idempotency_key' => 'q:1', 'purpose' => MessagePurpose::Automation]),
            app(MessagingService::class)->queue(['channel' => 'whatsapp', 'recipient' => '+919876543211', 'body' => 'Reply', 'idempotency_key' => 'q:2', 'purpose' => MessagePurpose::Reply]),
        ]);

        $automation->refresh();
        $this->assertSame(MessageStatus::Queued, $automation->status);
        $this->assertTrue($automation->scheduled_for->equalTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Kolkata')));
        $this->assertSame(MessageStatus::Sent, $reply->refresh()->status);

        // The worker that picked it up early did nothing; after 09:00 it is sent.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:01', 'Asia/Kolkata'));
        $this->inTenant($tenant, fn () => app(MessagingService::class)->deliver($automation));
        $this->assertSame(MessageStatus::Sent, $automation->refresh()->status);
    }

    public function test_automation_free_text_is_skipped_outside_the_window_and_templates_are_sent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.auto']]])]);
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $this->connectWhatsApp($tenant);
        $this->makeTemplate($tenant, ['name' => 'welcome', 'body' => 'Hi {{1}}, thanks for contacting {{2}}.', 'variables' => 2]);
        $text = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hello {{lead.name}}']],
        ]);
        $template = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['mode' => 'template', 'template' => ['name' => 'welcome', 'language' => 'en'], 'params' => ['{{lead.first_name}}', '{{business.name}}']]],
        ]);

        $this->makeLead($tenant, ['name' => 'Priya Sharma', 'phone' => '9876543210']);
        $textRun = $this->runOf($tenant, $text);
        $templateRun = $this->runOf($tenant, $template);

        $this->inTenant($tenant, function () use ($textRun, $templateRun) {
            $skipped = AutomationLog::query()->where('automation_run_id', $textRun->id)->where('event', 'action.skipped')->sole();
            $this->assertStringContainsString('24 hours', $skipped->message);

            $message = OutboundMessage::query()->sole();
            $this->assertSame('Hi Priya, thanks for contacting ABC Salon.', $message->body);
            $this->assertEquals(['name' => 'welcome', 'language' => 'en', 'params' => ['Priya', 'ABC Salon']], $message->template);
            $this->assertSame(MessageStatus::Sent, $message->status);
            $this->assertSame($templateRun->id, $message->automation_run_id);
        });
    }

    public function test_automation_free_text_is_sent_inside_the_window(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.win']]])]);
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $this->connectWhatsApp($tenant);
        $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Thanks {{lead.first_name}}!']],
        ]);

        // The contact messages first: the lead is created from the inbound message, inside the window.
        $this->receive($tenant, '9876543210', 'Hi', ['name' => 'Priya Sharma']);

        $message = OutboundMessage::withoutTenantScope()->sole();
        $this->assertSame(['Thanks Priya!', MessageStatus::Sent], [$message->body, $message->status]);
    }

    public function test_opted_out_contacts_get_no_automation_messages(): void
    {
        Http::fake();
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        // The customer texts STOP; a lead later created with the same phone must not be messaged.
        $this->makeCustomer($tenant, ['phone' => '9876543210']);
        $this->receive($tenant, '9876543210', 'STOP');
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hi']],
        ]);

        $this->makeLead($tenant, ['phone' => '9876543210']);

        $this->assertSame(0, OutboundMessage::withoutTenantScope()->count());
        $run = $this->runOf($tenant, $automation);
        $this->assertStringContainsString('opted out', $this->inTenant($tenant, fn () => AutomationLog::query()->where('automation_run_id', $run->id)->where('event', 'action.skipped')->sole()->message));
    }

    public function test_a_template_step_needs_an_approved_template(): void
    {
        $tenant = $this->createTenant();
        $this->makeTemplate($tenant, ['name' => 'pending_one', 'status' => 'PENDING']);
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/automations'), [
            'name' => 'Template test',
            'trigger' => 'lead.created',
            'is_active' => true,
            'steps' => [['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['mode' => 'template', 'template' => ['name' => 'pending_one', 'language' => 'en'], 'params' => []]]],
        ])->assertSessionHasErrors('steps.0.config.template');
    }
}
