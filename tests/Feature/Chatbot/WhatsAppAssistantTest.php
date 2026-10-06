<?php

namespace Tests\Feature\Chatbot;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Support\ChatbotSettings;
use App\Domain\Lead\Models\Lead;
use App\Domain\Media\Models\Media;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Notifications\WebsiteActivityAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesEducationRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** The WhatsApp assistant (ADR-021): menu from the business, taps and typed choices, hand-over, settings. */
class WhatsAppAssistantTest extends TestCase
{
    use CreatesAutomations, CreatesBookingRecords, CreatesCrmRecords, CreatesEducationRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    private const PHONE = '9876543210';

    private Tenant $tenant;

    private Service $haircut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToBookingDay();
        $this->tenant = $this->createTenant();
        $resource = $this->makeResource($this->tenant, ['name' => 'Sana']);
        $this->haircut = $this->makeService($this->tenant, ['name' => 'Haircut', 'duration_minutes' => 45, 'price' => 500, 'description' => 'Wash, cut and style.'], [$resource]);
        $this->makeService($this->tenant, ['name' => 'Facial', 'duration_minutes' => 60, 'price' => 1200], [$resource]);
    }

    public function test_the_assistant_is_off_until_the_owner_switches_it_on(): void
    {
        $this->receive($this->tenant, self::PHONE, 'Hi', ['name' => 'Priya Sharma']);

        $this->assertSame(0, OutboundMessage::withoutTenantScope()->count());
    }

    public function test_hi_gets_a_welcome_with_buttons_built_from_what_the_business_offers(): void
    {
        $this->enable();

        $this->receive($this->tenant, self::PHONE, 'Hi', ['name' => 'Priya Sharma']);

        $reply = $this->lastReply();
        $this->assertTrue($reply->assistant);
        $this->assertStringContainsString('Hi Priya!', $reply->body);
        $this->assertStringContainsString('ABC Salon', $reply->body);
        $this->assertSame('buttons', $reply->interactive['kind']);
        $this->assertSame(['aw.book', 'aw.services', 'aw.menu'], array_column($reply->interactive['buttons'], 'id'));
        $this->assertSame(['Book now', 'Services & prices', 'More options'], array_column($reply->interactive['buttons'], 'title'));

        $this->inTenant($this->tenant, function () use ($reply) {
            $entry = ConversationMessage::query()->where('outbound_message_id', $reply->id)->sole();
            $this->assertSame('interactive', $entry->type);
            $this->assertSame(['Book now', 'Services & prices', 'More options'], $entry->meta['options']);
            $this->assertSame(ChatbotSession::MENU, ChatbotSession::query()->sole()->state);
        });
    }

    public function test_the_cloud_api_gets_reply_buttons_and_a_list_and_taps_come_back_with_their_ids(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.out1']]])
            ->push(['messages' => [['id' => 'wamid.out2']]])]);
        $channel = $this->connectWhatsApp($this->tenant);
        $this->enable();

        $this->postWebhook($channel, $this->whatsappText('919876543210', 'hello', 'wamid.in1'))->assertOk();

        Http::assertSent(fn (Request $request) => $request['type'] === 'interactive'
            && $request['interactive']['type'] === 'button'
            && $request['interactive']['action']['buttons'][1] === ['type' => 'reply', 'reply' => ['id' => 'aw.services', 'title' => 'Services & prices']]);

        $this->postWebhook($channel, $this->whatsappPayload([
            'contacts' => [['profile' => ['name' => 'Priya'], 'wa_id' => '919876543210']],
            'messages' => [[
                'from' => '919876543210', 'id' => 'wamid.in2', 'timestamp' => (string) now()->getTimestamp(), 'type' => 'interactive',
                'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'aw.menu', 'title' => 'More options']],
            ]],
        ]))->assertOk();

        $this->inTenant($this->tenant, function () {
            $tap = ConversationMessage::query()->where('provider_message_id', 'wamid.in2')->sole();
            $this->assertSame(['interactive', 'More options', 'aw.menu'], [$tap->type, $tap->body, $tap->meta['reply_id']]);
        });

        Http::assertSent(fn (Request $request) => $request['type'] === 'interactive'
            && $request['interactive']['type'] === 'list'
            && $request['interactive']['action']['button'] === 'See options'
            && array_column($request['interactive']['action']['sections'][0]['rows'], 'id') === ['aw.book', 'aw.services', 'aw.info', 'aw.human']);
    }

    public function test_the_welcome_photo_is_uploaded_once_and_long_titles_are_cut_to_whatsapp_limits(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tenant/hero.jpg', 'jpeg-bytes');
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'media-123']),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.out']]]),
        ]);
        $this->connectWhatsApp($this->tenant);
        $this->inTenant($this->tenant, fn () => Media::query()->create([
            'collection' => 'hero', 'disk' => 'public', 'path' => 'tenant/hero.jpg', 'original_name' => 'hero.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'width' => 1600, 'height' => 900,
        ]));
        $this->makeService($this->tenant, ['name' => 'Keratin smoothening treatment for long hair', 'price' => 4500]);
        $this->enable();

        $this->receive($this->tenant, self::PHONE, 'Hi');
        $this->travel(31)->minutes();
        $this->receive($this->tenant, self::PHONE, 'Hello');
        $this->receive($this->tenant, self::PHONE, 'Services', ['reply' => 'aw.services']);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages')
            && $request['type'] === 'interactive'
            && ($request['interactive']['header'] ?? null) === ['type' => 'image', 'image' => ['id' => 'media-123']]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages')
            && ($request['interactive']['type'] ?? null) === 'list'
            && $request['interactive']['action']['sections'][0]['rows'][2]['title'] === 'Keratin smoothening tre…');
    }

    public function test_services_list_then_details_with_book_and_ask_buttons(): void
    {
        $this->enable();

        $this->receive($this->tenant, self::PHONE, 'Services & prices', ['reply' => 'aw.services']);
        $list = $this->lastReply();
        $this->assertSame('list', $list->interactive['kind']);
        $this->assertSame(['id' => 'aw.svc.'.$this->haircut->id, 'title' => 'Haircut', 'description' => '₹500 · 45 min'], $list->interactive['rows'][0]);

        $this->receive($this->tenant, self::PHONE, 'Haircut', ['reply' => 'aw.svc.'.$this->haircut->id]);
        $detail = $this->lastReply();
        $this->assertStringContainsString('*Haircut*', $detail->body);
        $this->assertStringContainsString('Wash, cut and style.', $detail->body);
        $this->assertSame(['aw.book.'.$this->haircut->id, 'aw.ask.svc.'.$this->haircut->id, 'aw.menu'], array_column($detail->interactive['buttons'], 'id'));

        $this->receive($this->tenant, self::PHONE, 'Book this', ['reply' => 'aw.book.'.$this->haircut->id]);
        $this->assertStringContainsString('Which day suits you?', $this->lastReply()->body);
        $this->assertStringStartsWith('aw.bk.day.'.$this->haircut->id.'.', $this->lastReply()->interactive['rows'][0]['id']);
    }

    public function test_typed_numbers_and_keywords_pick_options(): void
    {
        $this->enable();
        $this->inTenant($this->tenant, fn () => TenantSetting::query()->updateOrCreate(['key' => 'business_profile'], ['value' => [
            'address' => '12 MG Road', 'city' => 'Pune', 'phone' => '+91 90000 11111', 'opening_hours' => 'Mon–Sat 10am–8pm',
        ]]));

        $this->receive($this->tenant, self::PHONE, 'Hi');
        $this->receive($this->tenant, self::PHONE, '2');
        $this->assertSame('list', $this->lastReply()->interactive['kind']);
        $this->assertStringContainsString('Tap a service', $this->lastReply()->body);

        $this->receive($this->tenant, self::PHONE, 'Where are you located?');
        $info = $this->lastReply();
        $this->assertStringContainsString('📍 12 MG Road, Pune', $info->body);
        $this->assertStringContainsString('🕒 Mon–Sat 10am–8pm', $info->body);
        $this->assertStringContainsString('https://www.google.com/maps/search/?api=1&query=', $info->body);
    }

    public function test_a_question_goes_to_the_team_notes_the_interest_and_pauses_the_assistant(): void
    {
        Notification::fake();
        $this->enable();

        $this->receive($this->tenant, self::PHONE, 'Hi', ['name' => 'Priya Sharma']);
        $this->receive($this->tenant, self::PHONE, 'Ask about this', ['reply' => 'aw.ask.svc.'.$this->haircut->id]);
        $this->assertStringContainsString('What would you like to know about Haircut?', $this->lastReply()->body);

        $this->receive($this->tenant, self::PHONE, 'Do you also colour hair?');
        $this->assertStringContainsString('passed this to our team', $this->lastReply()->body);

        $this->inTenant($this->tenant, function () {
            $this->assertSame('Haircut', Lead::query()->sole()->interest);
            $session = ChatbotSession::query()->sole();
            $this->assertTrue($session->isPaused());
            $this->assertEqualsWithDelta(now()->addHours(12)->getTimestamp(), $session->paused_until->getTimestamp(), 5);
        });

        Notification::assertSentTo($this->ownerOf($this->tenant), WebsiteActivityAlert::class, fn (WebsiteActivityAlert $alert) => $alert->kind === 'whatsapp_handover'
            && $alert->details['Message'] === 'Do you also colour hair?'
            && $alert->details['Interested in'] === 'Haircut'
            && str_ends_with($alert->actionUrl, '/inbox/'.$this->conversation()->id));

        $count = OutboundMessage::withoutTenantScope()->count();
        $this->receive($this->tenant, self::PHONE, 'Any update?');
        $this->assertSame($count, OutboundMessage::withoutTenantScope()->count());
    }

    public function test_talk_to_a_person_pauses_until_the_contact_types_menu(): void
    {
        Notification::fake();
        $this->enable();

        $this->receive($this->tenant, self::PHONE, 'Talk to us', ['reply' => 'aw.human']);
        $this->assertStringContainsString('Someone from our team will reply here soon', $this->lastReply()->body);
        $count = OutboundMessage::withoutTenantScope()->count();

        $this->receive($this->tenant, self::PHONE, 'ok thanks');
        $this->assertSame($count, OutboundMessage::withoutTenantScope()->count());

        $this->receive($this->tenant, self::PHONE, 'Menu');
        $this->assertSame($count + 1, OutboundMessage::withoutTenantScope()->count());
        $this->assertSame('list', $this->lastReply()->interactive['kind']);
        $this->assertFalse($this->inTenant($this->tenant, fn () => ChatbotSession::query()->sole()->isPaused()));
        Notification::assertSentToTimes($this->ownerOf($this->tenant), WebsiteActivityAlert::class, 1);
    }

    public function test_a_team_reply_keeps_the_assistant_quiet_in_that_chat(): void
    {
        $this->enable();
        $conversation = $this->receive($this->tenant, self::PHONE, 'Hi');
        $this->actingAs($this->ownerOf($this->tenant));

        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => 'Hi Priya, how can I help?'])->assertSessionHasNoErrors();
        $count = OutboundMessage::withoutTenantScope()->count();

        $this->receive($this->tenant, self::PHONE, 'menu');
        $this->assertSame($count, OutboundMessage::withoutTenantScope()->count());

        $this->travel(13)->hours();
        $this->receive($this->tenant, self::PHONE, 'Hi again');
        $this->assertSame($count + 1, OutboundMessage::withoutTenantScope()->count());
    }

    public function test_after_two_messages_it_does_not_understand_it_hands_over(): void
    {
        Notification::fake();
        $this->enable();

        $this->receive($this->tenant, self::PHONE, 'Hi');
        $this->receive($this->tenant, self::PHONE, 'Can I bring my dog along with me tomorrow evening?');
        $this->assertStringContainsString('automatic assistant', $this->lastReply()->body);
        $this->assertSame(['aw.menu', 'aw.human'], array_column($this->lastReply()->interactive['buttons'], 'id'));

        $this->receive($this->tenant, self::PHONE, 'Please answer my question about the dog');
        $this->assertStringContainsString('get someone from our team', $this->lastReply()->body);
        $this->assertTrue($this->inTenant($this->tenant, fn () => ChatbotSession::query()->sole()->isPaused()));
    }

    public function test_stop_opted_out_contacts_and_old_messages_get_no_answer(): void
    {
        $this->enable();

        $this->receive($this->tenant, self::PHONE, 'Hi', ['at' => now()->subHour()->toImmutable()]);
        $this->assertSame(0, OutboundMessage::withoutTenantScope()->count());

        $this->receive($this->tenant, self::PHONE, 'STOP');
        $this->receive($this->tenant, self::PHONE, 'Hi');
        $this->assertSame(0, OutboundMessage::withoutTenantScope()->count());
    }

    public function test_offers_and_faq_come_from_the_website_sections(): void
    {
        $this->enable();
        $this->inTenant($this->tenant, function () {
            foreach (['offers' => ['items' => [['title' => 'Diwali glow', 'price' => '20% off', 'description' => 'On all facials.', 'valid_until' => '31 October']]],
                'faq' => ['items' => [['question' => 'Do you take walk-ins?', 'answer' => 'Yes, when a stylist is free.']]]] as $type => $configuration) {
                WebsiteSection::query()->updateOrCreate(['type' => $type], ['enabled' => true, 'sort_order' => 50, 'configuration' => $configuration]);
            }
        });

        $this->receive($this->tenant, self::PHONE, 'offers');
        $offers = $this->lastReply();
        $this->assertStringContainsString('*Diwali glow* — 20% off', $offers->body);
        $this->assertStringContainsString('Valid until 31 October', $offers->body);
        $this->assertSame('aw.book', $offers->interactive['buttons'][0]['id']);

        $this->receive($this->tenant, self::PHONE, 'Questions', ['reply' => 'aw.faq']);
        $this->assertSame('Do you take walk-ins?', $this->lastReply()->interactive['rows'][0]['title'] ?? null);
        $this->receive($this->tenant, self::PHONE, 'Do you take walk-ins?', ['reply' => 'aw.faq.0']);
        $this->assertStringContainsString('Yes, when a stylist is free.', $this->lastReply()->body);
    }

    public function test_a_coaching_centre_offers_courses_and_a_free_demo(): void
    {
        $coaching = $this->createCoaching();
        $course = $this->makeCourse($coaching, ['name' => 'JEE Foundation', 'duration_label' => '1 year']);
        $this->makeBatch($coaching, $course, ['name' => 'Evening']);
        $this->enable($coaching);

        $this->receive($coaching, self::PHONE, 'Hi');
        $this->assertContains('aw.courses', array_column($this->lastReply()->interactive['buttons'], 'id'));

        $this->receive($coaching, self::PHONE, 'JEE Foundation', ['reply' => 'aw.course.'.$course->id]);
        $detail = $this->lastReply();
        $this->assertStringContainsString('₹12,000 · 1 year', $detail->body);
        $this->assertStringContainsString('Evening', $detail->body);
        $this->assertSame('aw.dm.c.'.$course->id, $detail->interactive['buttons'][0]['id']);

        $this->receive($coaching, self::PHONE, 'Free demo class', ['reply' => 'aw.ask.demo.'.$course->id]);
        $this->receive($coaching, self::PHONE, 'Saturday 11 am please');
        $this->assertSame('Free demo: JEE Foundation', $this->inTenant($coaching, fn () => Lead::query()->sole()->interest));
    }

    public function test_the_simulated_channel_records_options_in_the_inbox(): void
    {
        $this->enable();

        $this->artisan('messaging:simulate-inbound', ['tenant' => $this->tenant->slug, 'from' => self::PHONE, 'text' => 'More options', '--reply' => 'aw.menu'])->assertSuccessful();

        $reply = $this->lastReply();
        $this->assertTrue($reply->simulated);
        $this->actingAs($this->ownerOf($this->tenant));
        $this->get($this->appUrl('/inbox/'.$reply->conversation_id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.1.sender', 'Assistant')
            ->where('conversation.messages.1.options', ['Book an appointment', 'Services & prices', 'Timings & location', 'Talk to a person']));
    }

    public function test_the_owner_manages_the_settings_and_staff_cannot(): void
    {
        $owner = $this->ownerOf($this->tenant);
        $this->actingAs($owner);

        $this->get($this->appUrl('/settings/whatsapp-assistant'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/settings/WhatsAppAssistant')
            ->where('settings.enabled', false)
            ->where('connected', false)
            ->where('items.0.item', 'book')
            ->where('items.0.available', true)
            ->where('items', fn ($items) => collect($items)->firstWhere('item', 'services')['hint'] === null
                && str_contains(collect($items)->firstWhere('item', 'order')['hint'], 'online ordering'))
            ->where('overlapping', []));

        $welcome = $this->template($this->tenant, 'new_lead_welcome');
        $this->inTenant($this->tenant, fn () => $welcome->update(['is_active' => true]));
        $this->get($this->appUrl('/settings/whatsapp-assistant'))->assertInertia(fn (Assert $page) => $page
            ->where('overlapping', [['id' => $welcome->id, 'name' => 'Welcome new leads on WhatsApp']]));
        $this->inTenant($this->tenant, fn () => $welcome->update(['is_active' => false]));

        $this->put($this->appUrl('/settings/whatsapp-assistant'), [
            'enabled' => true, 'welcome' => '  Namaste {name}! ', 'show_image' => false, 'items' => ['services'], 'pause_hours' => 6, 'alert_team' => false,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $settings = $this->inTenant($this->tenant, fn () => app(ChatbotSettings::class)->all());
        $this->assertSame([true, 'Namaste {name}!', false, ['services', 'human'], 6, false], [
            $settings['enabled'], $settings['welcome'], $settings['show_image'], $settings['items'], $settings['pause_hours'], $settings['alert_team'],
        ]);
        $this->assertTrue(AuditLog::query()->where('action', 'chatbot.settings_updated')->where('tenant_id', $this->tenant->id)->exists());

        $this->receive($this->tenant, self::PHONE, 'Hi', ['name' => 'Asha']);
        $this->assertStringStartsWith('Namaste Asha!', $this->lastReply()->body);
        $this->assertSame(['aw.services', 'aw.human'], array_column($this->lastReply()->interactive['buttons'], 'id'));

        $this->put($this->appUrl('/settings/whatsapp-assistant'), ['enabled' => true, 'show_image' => true, 'items' => ['teleport'], 'pause_hours' => 500, 'alert_team' => true])
            ->assertSessionHasErrors(['items.0', 'pause_hours']);

        $staff = User::factory()->create();
        $this->addMember($this->tenant, $staff, 'staff');
        $this->actingAs($staff);
        $this->put($this->appUrl('/settings/whatsapp-assistant'), ['enabled' => false, 'show_image' => true, 'items' => [], 'pause_hours' => 12, 'alert_team' => true])->assertForbidden();
    }

    public function test_sessions_and_settings_belong_to_one_business(): void
    {
        $other = $this->createTenant('Other Salon');
        $this->enable();

        $this->receive($other, self::PHONE, 'Hi');
        $this->assertSame(0, OutboundMessage::withoutTenantScope()->count());

        $this->receive($this->tenant, self::PHONE, 'Hi');
        $this->assertSame(1, ChatbotSession::withoutTenantScope()->count());
        $this->assertSame($this->tenant->id, ChatbotSession::withoutTenantScope()->sole()->tenant_id);
        $this->assertSame(0, $this->inTenant($other, fn () => ChatbotSession::query()->count()));
    }

    private function enable(?Tenant $tenant = null, array $values = []): void
    {
        $this->inTenant($tenant ?? $this->tenant, fn () => app(ChatbotSettings::class)->update([
            ...app(ChatbotSettings::class)->all(),
            'enabled' => true,
            ...$values,
        ]));
    }

    private function lastReply(): OutboundMessage
    {
        return OutboundMessage::withoutTenantScope()->where('assistant', true)->latest('id')->firstOrFail();
    }

    private function conversation(): Conversation
    {
        return Conversation::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
    }
}
