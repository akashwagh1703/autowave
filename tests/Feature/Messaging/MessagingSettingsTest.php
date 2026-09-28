<?php

namespace Tests\Feature\Messaging;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Messaging\Enums\ChannelStatus;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Models\MessagingChannel;
use App\Domain\Messaging\Support\MessagingSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Settings → Messaging: manual connection, write-only secrets, template sync, preferences (ADR-018). */
class MessagingSettingsTest extends TestCase
{
    use CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_the_owner_connects_whatsapp_after_meta_confirms_the_number(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+91 98765 43210', 'verified_name' => 'ABC Salon', 'id' => '1234567890'])]);
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/settings/messaging/whatsapp'), [
            'phone_number_id' => '1234567890',
            'business_account_id' => '9876543210',
            'access_token' => 'EAAG-secret-token',
            'app_secret' => 'shh-app-secret',
        ])->assertSessionHasNoErrors()->assertRedirect();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/1234567890') && $request->hasHeader('Authorization', 'Bearer EAAG-secret-token'));

        $channel = MessagingChannel::withoutTenantScope()->where('channel', 'whatsapp')->sole();
        $this->assertSame([ChannelStatus::Connected, '1234567890', '9876543210', 'ABC Salon'], [$channel->status, $channel->external_id, $channel->business_account_id, $channel->display_name]);
        $this->assertSame('EAAG-secret-token', $channel->credential('access_token'));
        // Stored encrypted, never in plain text.
        $raw = (string) DB::table('messaging_channels')->where('id', $channel->id)->value('credentials');
        $this->assertStringNotContainsString('EAAG-secret-token', $raw);
        $this->assertTrue(AuditLog::query()->where('action', 'messaging.channel_connected')->exists());
    }

    public function test_secrets_never_reach_the_page_and_a_blank_secret_keeps_the_saved_one(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+91 98765 43210', 'verified_name' => 'ABC Salon'])]);
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant, phoneNumberId: '1111111111');
        $this->actingAs($this->ownerOf($tenant));

        $response = $this->get($this->appUrl('/settings/messaging'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/settings/Messaging')
            ->where('channels.whatsapp.connected', true)
            ->where('channels.whatsapp.has_token', true)
            ->where('channels.whatsapp.has_secret', true)
            ->where('channels.whatsapp.verify_token', $channel->verifyToken())
            ->where('channels.whatsapp.callback_url', route('webhooks.meta.receive', $channel->webhook_key))
            ->missing('channels.whatsapp.access_token')
            ->missing('channels.whatsapp.credentials'));
        $this->assertStringNotContainsString('wa-token', $response->getContent());
        $this->assertStringNotContainsString(self::APP_SECRET, $response->getContent());

        // Updating the ids with blank secret fields keeps the saved token and secret.
        $this->post($this->appUrl('/settings/messaging/whatsapp'), [
            'phone_number_id' => '1111111111', 'business_account_id' => '3333333333', 'access_token' => '', 'app_secret' => '',
        ])->assertSessionHasNoErrors();

        $channel->refresh();
        $this->assertSame(['3333333333', 'wa-token', self::APP_SECRET], [$channel->business_account_id, $channel->credential('access_token'), $channel->credential('app_secret')]);
    }

    public function test_a_manager_sees_the_connection_but_not_the_webhook_setup_and_cannot_change_it(): void
    {
        $tenant = $this->createTenant();
        $this->connectWhatsApp($tenant);
        $manager = User::factory()->create();
        $this->addMember($tenant, $manager, 'manager');
        $this->actingAs($manager);

        $this->get($this->appUrl('/settings/messaging'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('channels.whatsapp.connected', true)
            ->where('channels.whatsapp.verify_token', null)
            ->where('channels.whatsapp.callback_url', null));

        $this->post($this->appUrl('/settings/messaging/whatsapp'), ['phone_number_id' => '1111111111', 'business_account_id' => '2222222222'])->assertForbidden();
        $this->post($this->appUrl('/settings/messaging/disconnect'), ['channel' => 'whatsapp'])->assertForbidden();
        $this->post($this->appUrl('/settings/messaging/templates/sync'))->assertForbidden();
        $this->put($this->appUrl('/settings/messaging'), [])->assertForbidden();
    }

    public function test_meta_rejecting_the_token_shows_an_error_and_saves_nothing(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/settings/messaging/whatsapp'), [
            'phone_number_id' => '1234567890', 'business_account_id' => '9876543210', 'access_token' => 'bad', 'app_secret' => 'x',
        ])->assertSessionHasErrors('access_token');

        $this->assertFalse(MessagingChannel::withoutTenantScope()->where('channel', 'whatsapp')->sole()->isConnected());
    }

    public function test_a_number_connected_to_another_business_cannot_be_connected_again(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+91', 'verified_name' => 'X'])]);
        $theirs = $this->createTenant('Tenant B');
        $this->connectWhatsApp($theirs, phoneNumberId: '5555555555');
        $mine = $this->createTenant('Tenant A');
        $this->actingAs($this->ownerOf($mine));

        $this->post($this->appUrl('/settings/messaging/whatsapp'), [
            'phone_number_id' => '5555555555', 'business_account_id' => '9876543210', 'access_token' => 't', 'app_secret' => 's',
        ])->assertSessionHasErrors('phone_number_id');
        Http::assertNothingSent();
    }

    public function test_the_owner_connects_instagram(): void
    {
        Http::fake(['graph.instagram.com/*' => Http::response(['user_id' => '17840000000000009', 'username' => 'abcsalon', 'name' => 'ABC Salon'])]);
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/settings/messaging/instagram'), ['access_token' => 'IGQ-token', 'app_secret' => 'ig-secret'])->assertSessionHasNoErrors();

        $channel = MessagingChannel::withoutTenantScope()->where('channel', 'instagram')->sole();
        $this->assertTrue($channel->isConnected());
        $this->assertSame(['17840000000000009', '@abcsalon'], [$channel->external_id, $channel->display_handle]);
    }

    public function test_disconnecting_keeps_the_verify_token_but_forgets_the_credentials(): void
    {
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);
        $token = $channel->verifyToken();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/settings/messaging/disconnect'), ['channel' => 'whatsapp'])->assertSessionHasNoErrors();

        $channel->refresh();
        $this->assertFalse($channel->isConnected());
        $this->assertNull($channel->credential('access_token'));
        $this->assertNull($channel->credential('app_secret'));
        $this->assertNull($channel->external_id);
        $this->assertSame($token, $channel->verifyToken());
    }

    public function test_templates_are_synced_from_the_business_account(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['id' => 't1', 'name' => 'appointment_reminder', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Reminder'],
                ['type' => 'BODY', 'text' => 'Hi {{1}}, see you on {{2}} at {{3}}.'],
            ]],
            ['id' => 't2', 'name' => 'offer', 'language' => 'hi', 'status' => 'PENDING', 'category' => 'MARKETING', 'components' => [['type' => 'BODY', 'text' => 'Offer!']]],
        ]])]);
        $tenant = $this->createTenant();
        $this->connectWhatsApp($tenant, businessAccountId: '2222222222');
        $this->makeTemplate($tenant, ['name' => 'deleted_in_meta']);
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/settings/messaging/templates/sync'))->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/2222222222/message_templates'));
        $templates = MessageTemplate::withoutTenantScope()->orderBy('name')->get();
        $this->assertSame(['appointment_reminder', 'offer'], $templates->pluck('name')->all());
        $this->assertSame(['Hi {{1}}, see you on {{2}} at {{3}}.', 3, true], [$templates[0]->body, $templates[0]->variables, $templates[0]->isApproved()]);
        $this->assertFalse($templates[1]->isApproved());
    }

    public function test_syncing_without_whatsapp_explains_what_to_do(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/settings/messaging/templates/sync'))->assertSessionHasErrors('templates');
    }

    public function test_quiet_hours_and_the_email_sender_are_saved_and_validated(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->put($this->appUrl('/settings/messaging'), [
            'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '22:00'],
            'email' => ['from_name' => "Evil\r\nBcc: x", 'reply_to' => 'not-an-email'],
        ])->assertSessionHasErrors(['quiet_hours.end', 'email.from_name', 'email.reply_to']);

        $this->put($this->appUrl('/settings/messaging'), [
            'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '08:30'],
            'email' => ['from_name' => 'ABC Salon Team', 'reply_to' => 'hello@abc-salon.test'],
        ])->assertSessionHasNoErrors();

        $this->inTenant($tenant, function () {
            $settings = app(MessagingSettings::class);
            $this->assertSame(['enabled' => true, 'start' => '22:00', 'end' => '08:30'], $settings->quietHours());
            $this->assertSame(['from_name' => 'ABC Salon Team', 'reply_to' => 'hello@abc-salon.test'], $settings->email());
        });
    }
}
