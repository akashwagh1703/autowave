<?php

namespace Tests\Feature\Chatbot;

use App\Domain\AI\Models\AIUsage;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Support\ChatbotSettings;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Notifications\WebsiteActivityAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAi;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** AI answers to typed questions in the WhatsApp assistant (ADR-021, step 3). */
class WhatsAppAssistantAiTest extends TestCase
{
    use CreatesAi, CreatesBookingRecords, CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    private const PHONE = '9876543210';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelToBookingDay();
        $this->tenant = $this->createTenant();
        $resource = $this->makeResource($this->tenant, ['name' => 'Sana']);
        $this->makeService($this->tenant, ['name' => 'Haircut', 'duration_minutes' => 45, 'price' => 500], [$resource]);
    }

    public function test_ai_answers_are_off_by_default(): void
    {
        $this->useOpenRouter();
        $this->enable();

        $this->say('Hi');
        $this->say('Do you have parking near the salon?');

        $this->assertStringContainsString('I’m the automatic assistant', $this->lastReply()->body);
        Http::assertNothingSent();
    }

    public function test_a_typed_question_gets_a_grounded_answer_with_buttons(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::response($this->completion(json_encode([
            'answer' => 'Yes, there is free parking right outside.',
            'confident' => true,
            'topic' => 'info',
        ])))]);
        $this->enable(['ai_answers' => true]);

        $this->say('Hi');
        $this->say('Do you have parking near the salon?');

        $reply = $this->lastReply();
        $this->assertSame('Yes, there is free parking right outside.', $reply->body);
        $this->assertSame('Automatic answer', $reply->interactive['footer']);
        $this->assertSame(['aw.info', 'aw.human', 'aw.menu'], array_column($reply->interactive['buttons'], 'id'));
        $this->assertNull($this->chatSession()->paused_until);

        Http::assertSent(function (Request $request) {
            $system = $request['messages'][0]['content'];
            $user = $request['messages'][1]['content'];

            return str_contains($system, 'Haircut: INR 500, 45 min')
                && str_contains($system, 'Never confirm or promise a booking')
                && str_contains($system, '"Book now"')
                && str_contains($system, 'Do not greet again')
                && str_contains($user, "Customer's message to answer:\nDo you have parking near the salon?");
        });
        $usage = AIUsage::withoutTenantScope()->sole();
        $this->assertSame(['chatbot', null], [$usage->feature, $usage->user_id]);
        Notification::assertNotSentTo($this->ownerOf($this->tenant), WebsiteActivityAlert::class);

        $this->actingAs($this->ownerOf($this->tenant));
        $this->get($this->appUrl('/inbox/'.$reply->conversation_id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.3.footer', 'Automatic answer')
            ->where('conversation.messages.3.sender', 'Assistant'));
    }

    public function test_a_first_message_that_is_a_question_is_answered_with_a_greeting(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::response($this->completion(json_encode([
            'answer' => 'Hi Priya! A haircut is ₹500 and takes 45 minutes.',
            'confident' => true,
            'topic' => 'book',
        ])))]);
        $this->enable(['ai_answers' => true]);

        $this->say('How much is a haircut?', 'Priya Sharma');

        $reply = $this->lastReply();
        $this->assertSame('Hi Priya! A haircut is ₹500 and takes 45 minutes.', $reply->body);
        $this->assertSame(['Book now', 'Talk to us', 'Main menu'], array_column($reply->interactive['buttons'], 'title'));
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'This is the customer\'s first message'));
    }

    public function test_greetings_keywords_and_taps_never_go_to_ai(): void
    {
        $this->useOpenRouter();
        $this->enable(['ai_answers' => true]);

        $this->say('ok');
        $this->assertSame('buttons', $this->lastReply()->interactive['kind']);
        $this->say('prices');
        $this->say('Haircut', null, 'aw.services');

        Http::assertNothingSent();
    }

    public function test_when_ai_is_not_sure_the_chat_goes_to_the_team(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::response($this->completion(json_encode([
            'answer' => 'Maybe on Sunday?',
            'confident' => false,
            'topic' => null,
        ])))]);
        $this->enable(['ai_answers' => true]);

        $this->say('Hi');
        $this->say('Can I change my booking from last week to Sunday?');

        $this->assertStringContainsString('We have passed this to our team', $this->lastReply()->body);
        $this->assertStringNotContainsString('Maybe on Sunday', $this->lastReply()->body);
        $this->assertNotNull($this->chatSession()->paused_until);
        Notification::assertSentTo($this->ownerOf($this->tenant), WebsiteActivityAlert::class, fn (WebsiteActivityAlert $alert) => $alert->kind === 'whatsapp_handover'
            && str_contains($alert->intro, 'could not answer')
            && ($alert->details['Message'] ?? null) === 'Can I change my booking from last week to Sunday?');
    }

    public function test_a_question_after_ask_us_is_answered_by_ai(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::response($this->completion(json_encode([
            'answer' => 'Yes, we use sulphate-free products.',
            'confident' => true,
            'topic' => 'services',
        ])))]);
        $this->enable(['ai_answers' => true]);

        $this->say('Ask a question', null, 'aw.ask');
        $this->assertSame(ChatbotSession::QUESTION, $this->chatSession()->state);
        $this->say('Are your products sulphate free');

        $this->assertSame('Yes, we use sulphate-free products.', $this->lastReply()->body);
        $this->assertSame('aw.services', $this->lastReply()->interactive['buttons'][0]['id']);
        $this->assertSame(ChatbotSession::MENU, $this->chatSession()->state);
        Notification::assertNotSentTo($this->ownerOf($this->tenant), WebsiteActivityAlert::class);
    }

    public function test_without_ai_the_menu_falls_back_to_its_usual_replies(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Overloaded']], 503)]);
        $this->enable(['ai_answers' => true]);

        $this->say('Hi');
        $this->say('Do you have parking near the salon?');
        $this->assertStringContainsString('I’m the automatic assistant', $this->lastReply()->body);

        $this->recordUsage($this->tenant, 10_000_000);
        $this->say('Ask a question', null, 'aw.ask');
        $this->say('Are your products sulphate free');

        $this->assertStringContainsString('We have passed this to our team', $this->lastReply()->body);
        Http::assertSentCount(1);
    }

    public function test_the_owner_turns_ai_answers_on_and_sees_whether_ai_is_available(): void
    {
        $owner = $this->ownerOf($this->tenant);
        $this->actingAs($owner);

        $this->get($this->appUrl('/settings/whatsapp-assistant'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('settings.ai_answers', false)
            ->where('ai.module', true)
            ->where('ai.available', true));

        $this->put($this->appUrl('/settings/whatsapp-assistant'), [
            ...$this->inTenant($this->tenant, fn () => app(ChatbotSettings::class)->all()),
            'ai_answers' => true,
        ])->assertRedirect();

        $this->assertTrue($this->inTenant($this->tenant, fn () => app(ChatbotSettings::class)->all()['ai_answers']));

        $this->setAiSettings($this->tenant, ['enabled' => false]);
        $this->get($this->appUrl('/settings/whatsapp-assistant'))->assertInertia(fn (Assert $page) => $page
            ->where('ai.available', false)
            ->where('ai.reason', 'disabled'));
    }

    private function enable(array $values = []): void
    {
        $this->inTenant($this->tenant, fn () => app(ChatbotSettings::class)->update([
            ...app(ChatbotSettings::class)->all(),
            'enabled' => true,
            ...$values,
        ]));
    }

    private function say(string $text, ?string $name = null, ?string $reply = null): void
    {
        $this->travel(10)->seconds();
        $this->receive($this->tenant, self::PHONE, $text, array_filter(['name' => $name, 'reply' => $reply]));
    }

    private function lastReply(): OutboundMessage
    {
        return OutboundMessage::withoutTenantScope()->where('assistant', true)->latest('id')->firstOrFail();
    }

    private function chatSession(): ChatbotSession
    {
        return ChatbotSession::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
    }
}
