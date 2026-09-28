<?php

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Models\MessagingChannel;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Tenant\Exceptions\CrossTenantWrite;
use App\Domain\Tenant\Exceptions\MissingTenantContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Mandatory cross-tenant tests (master prompt §86) for channels, conversations and templates. */
class MessagingIsolationTest extends TestCase
{
    use CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_tenant_a_cannot_see_or_act_on_tenant_b_conversations(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirs = $this->receive($b, '9876543210', 'Secret question', ['name' => 'Their contact']);
        $theirCustomer = $this->makeCustomer($b, ['phone' => '9123456780']);
        $theirLead = $this->makeLead($b, ['phone' => '9123456781']);
        $this->actingAs($this->ownerOf($a));

        $this->get($this->appUrl("/inbox/{$theirs->id}"))->assertNotFound();
        $this->post($this->appUrl("/inbox/{$theirs->id}/messages"), ['body' => 'Hijack'])->assertNotFound();
        $this->post($this->appUrl("/inbox/{$theirs->id}/template"), ['template_id' => 1, 'params' => []])->assertNotFound();
        $this->patch($this->appUrl("/inbox/{$theirs->id}/assign"), ['tenant_user_id' => null])->assertNotFound();
        $this->patch($this->appUrl("/inbox/{$theirs->id}/status"), ['status' => 'closed'])->assertNotFound();
        $this->patch($this->appUrl("/inbox/{$theirs->id}/opt-out"), ['opted_out' => true])->assertNotFound();
        $this->post($this->appUrl("/customers/{$theirCustomer->id}/chat"))->assertNotFound();
        $this->post($this->appUrl("/leads/{$theirLead->id}/chat"))->assertNotFound();

        $this->get($this->appUrl('/inbox?view=all&search=Their'))->assertInertia(fn (Assert $page) => $page
            ->where('conversations.meta.total', 0)
            ->where('counts.open', 0)
            ->where('inbox.unread', 0));

        $fresh = Conversation::withoutTenantScope()->findOrFail($theirs->id);
        $this->assertSame([1, null, null], [$fresh->unread_count, $fresh->opted_out_at, $fresh->assigned_tenant_user_id]);
        $this->assertSame(0, OutboundMessage::withoutTenantScope()->count());
    }

    public function test_tenant_a_cannot_use_tenant_b_templates_or_members(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirTemplate = $this->makeTemplate($b, ['name' => 'their_template']);
        $theirMember = $this->addMember($b, User::factory()->create(), 'manager');
        $mine = $this->receive($a, '9876543210');
        $this->actingAs($this->ownerOf($a));

        $this->post($this->appUrl("/inbox/{$mine->id}/template"), ['template_id' => $theirTemplate->id, 'params' => ['a', 'b']])->assertSessionHasErrors('template');
        $this->patch($this->appUrl("/inbox/{$mine->id}/assign"), ['tenant_user_id' => $theirMember->id])->assertSessionHasErrors('tenant_user_id');
        $this->get($this->appUrl("/inbox/{$mine->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('conversation.templates', [])
            ->where('members', fn ($members) => ! collect($members)->pluck('id')->contains($theirMember->id)));
        $this->get($this->appUrl('/settings/messaging'))->assertInertia(fn (Assert $page) => $page->where('templates', []));

        $this->assertSame(0, OutboundMessage::withoutTenantScope()->count());
        $this->assertNull($mine->refresh()->assigned_tenant_user_id);
    }

    public function test_the_same_contact_messaging_two_businesses_gets_two_separate_conversations(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $channelA = $this->connectWhatsApp($a, phoneNumberId: '1111111111');
        $this->connectWhatsApp($b, phoneNumberId: '3333333333');

        $this->postWebhook($channelA, $this->whatsappText('919876543210', 'For A', 'wamid.a'))->assertOk();
        // B's number in a payload posted to A's URL (and signed with A's secret) is ignored.
        $this->postWebhook($channelA, $this->whatsappPayload([
            'messages' => [['from' => '919876543210', 'id' => 'wamid.b', 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => 'For B']]],
        ], phoneNumberId: '3333333333'))->assertOk();
        $this->receive($b, '9876543210', 'Really for B');

        $this->assertSame(['For A'], $this->inTenant($a, fn () => ConversationMessage::query()->pluck('body')->all()));
        $this->assertSame(['Really for B'], $this->inTenant($b, fn () => ConversationMessage::query()->pluck('body')->all()));
        $this->assertSame(2, Conversation::withoutTenantScope()->where('contact_handle', '+919876543210')->count());
    }

    public function test_a_webhook_signed_with_another_business_secret_is_refused(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $channelA = $this->connectWhatsApp($a, phoneNumberId: '1111111111');
        $channelB = $this->connectWhatsApp($b, phoneNumberId: '3333333333');
        $this->inTenant($b, fn () => $channelB->forceFill(['credentials' => [...$channelB->credentials, 'app_secret' => 'b-secret']])->save());

        $this->postWebhook($channelA, $this->whatsappText('919876543210', 'x', 'wamid.x'), secret: 'b-secret')->assertForbidden();
        $this->assertSame(0, ConversationMessage::withoutTenantScope()->count());
    }

    public function test_composite_foreign_keys_reject_cross_tenant_references(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $mine = $this->receive($a, '9876543210');
        $theirs = $this->receive($b, '9876543211');
        $theirCustomer = $this->makeCustomer($b);
        $theirLead = $this->makeLead($b);
        $theirMember = $this->addMember($b, User::factory()->create(), 'manager');
        $theirChannel = $this->connectWhatsApp($b);
        $myMessage = $this->inTenant($a, fn () => ConversationMessage::query()->sole());

        $attempts = [
            fn () => DB::table('conversations')->where('id', $mine->id)->update(['customer_id' => $theirCustomer->id]),
            fn () => DB::table('conversations')->where('id', $mine->id)->update(['lead_id' => $theirLead->id]),
            fn () => DB::table('conversations')->where('id', $mine->id)->update(['assigned_tenant_user_id' => $theirMember->id]),
            fn () => DB::table('conversation_messages')->where('id', $myMessage->id)->update(['conversation_id' => $theirs->id]),
            fn () => DB::table('outbound_messages')->insert([
                'tenant_id' => $a->id, 'channel' => 'whatsapp', 'provider' => 'log', 'recipient' => '+919876543210', 'body' => 'x',
                'status' => 'queued', 'idempotency_key' => 'fk-test', 'conversation_id' => $theirs->id, 'created_at' => now(), 'updated_at' => now(),
            ]),
            fn () => DB::table('messaging_webhook_calls')->insert([
                'tenant_id' => $a->id, 'messaging_channel_id' => $theirChannel->id, 'payload' => '{}', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]),
        ];

        foreach ($attempts as $index => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("Cross-tenant write #{$index} was accepted.");
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->errorInfo[0], "Write #{$index}: ".$exception->getMessage());
            }
        }
    }

    public function test_messaging_models_fail_closed_without_a_tenant(): void
    {
        $tenant = $this->createTenant();
        $this->receive($tenant, '9876543210');
        $this->connectWhatsApp($tenant);
        $this->makeTemplate($tenant);

        $this->assertSame(0, Conversation::query()->count());
        $this->assertSame(0, ConversationMessage::query()->count());
        $this->assertSame(0, MessagingChannel::query()->count());
        $this->assertSame(0, MessageTemplate::query()->count());

        $this->expectException(MissingTenantContext::class);
        Conversation::query()->create(['channel' => 'whatsapp', 'contact_handle' => '+919000000000']);
    }

    public function test_writing_a_conversation_into_another_tenant_is_refused(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        $this->expectException(CrossTenantWrite::class);
        $this->inTenant($a, fn () => Conversation::query()->create(['tenant_id' => $b->id, 'channel' => 'whatsapp', 'contact_handle' => '+919000000000']));
    }
}
