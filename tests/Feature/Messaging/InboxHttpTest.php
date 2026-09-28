<?php

namespace Tests\Feature\Messaging;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\RBAC\Actions\ProvisionTenantRoles;
use App\Domain\RBAC\Models\Permission;
use App\Domain\RBAC\Models\Role;
use App\Domain\Tenant\Models\TenantSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** The shared inbox over HTTP: listing, replies, templates, assignment, permissions (ADR-018). */
class InboxHttpTest extends TestCase
{
    use CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_the_inbox_lists_conversations_and_opening_one_marks_it_read(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'Hi there', ['name' => 'Priya']);
        $this->receive($tenant, '9876543211', 'Second', ['name' => 'Asha']);
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/inbox'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/inbox/Index')
            ->where('conversations.meta.total', 2)
            ->where('counts.open', 2)
            ->where('counts.unread', 2)
            ->where('counts.unassigned', 2)
            ->where('inbox.unread', 2)
            ->where('conversation', null));

        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('conversation.id', $conversation->id)
            ->where('conversation.name', 'Priya')
            ->where('conversation.messages.0.body', 'Hi there')
            ->where('conversation.messages.0.direction', 'inbound')
            ->where('conversation.simulated', true)
            ->where('conversation.text_blocked', null));

        $this->assertSame(0, $conversation->refresh()->unread_count);

        $this->get($this->appUrl('/inbox?search=Asha'))->assertInertia(fn (Assert $page) => $page
            ->where('conversations.meta.total', 1)
            ->where('conversations.data.0.name', 'Asha'));
    }

    public function test_a_reply_is_queued_threaded_and_sent_once_per_client_id(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $conversation = $this->receive($tenant, '9876543210');
        $this->actingAs($owner);
        $clientId = (string) Str::uuid();

        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => 'Yes, 4 pm works.', 'client_id' => $clientId])->assertSessionHasNoErrors()->assertRedirect();
        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => 'Yes, 4 pm works.', 'client_id' => $clientId])->assertSessionHasNoErrors();

        $message = OutboundMessage::withoutTenantScope()->sole();
        $this->assertSame([$conversation->id, $owner->id, '+919876543210', 'Yes, 4 pm works.'], [$message->conversation_id, $message->sent_by_user_id, $message->recipient, $message->body]);
        $conversation->refresh();
        $this->assertSame(['outbound', 'Yes, 4 pm works.'], [$conversation->last_message_direction, $conversation->last_message_preview]);

        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.1.direction', 'outbound')
            ->where('conversation.messages.1.sender', $owner->name)
            ->where('conversation.messages.1.status', 'sent'));
    }

    public function test_outside_the_window_only_approved_templates_can_be_sent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.tpl']]])]);
        $tenant = $this->createTenant();
        $this->connectWhatsApp($tenant);
        $template = $this->makeTemplate($tenant);
        $conversation = $this->receive($tenant, '9876543210', 'Hi', ['at' => CarbonImmutable::now('UTC')->subHours(30)]);
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('conversation.window.enforced', true)
            ->where('conversation.window.closes_at', null)
            ->where('conversation.text_blocked', fn ($reason) => str_contains($reason, '24 hours'))
            ->where('conversation.templates.0.id', $template->id));

        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => 'Hello?'])->assertSessionHasErrors('body');
        $this->post($this->appUrl("/inbox/{$conversation->id}/template"), ['template_id' => $template->id, 'params' => ['Priya']])->assertSessionHasErrors('params');
        $this->post($this->appUrl("/inbox/{$conversation->id}/template"), ['template_id' => $template->id, 'params' => ['Priya', 'Monday 4 pm']])->assertSessionHasNoErrors();

        $message = OutboundMessage::withoutTenantScope()->sole();
        $this->assertSame('Hi Priya, see you on Monday 4 pm.', $message->body);
        $this->assertSame('appointment_reminder', $message->template['name']);
        Http::assertSent(fn ($request) => $request['type'] === 'template');
    }

    public function test_opted_out_contacts_cannot_be_sent_templates(): void
    {
        $tenant = $this->createTenant();
        $template = $this->makeTemplate($tenant);
        $conversation = $this->receive($tenant, '9876543210', 'STOP');
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl("/inbox/{$conversation->id}/template"), ['template_id' => $template->id, 'params' => ['a', 'b']])->assertSessionHasErrors('template');

        $this->patch($this->appUrl("/inbox/{$conversation->id}/opt-out"), ['opted_out' => false])->assertSessionHasNoErrors();
        $this->assertFalse($conversation->refresh()->isOptedOut());
        $this->assertTrue(AuditLog::query()->where('action', 'conversation.opted_in')->exists());
    }

    public function test_conversations_can_be_assigned_closed_and_reopened(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210');
        $receptionist = User::factory()->create(['name' => 'Rita']);
        $membership = $this->addMember($tenant, $receptionist, 'receptionist');
        $staff = $this->addMember($tenant, User::factory()->create(), 'staff');
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/inbox'))->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members) => collect($members)->pluck('id')->contains($membership->id) && ! collect($members)->pluck('id')->contains($staff->id)));

        $this->patch($this->appUrl("/inbox/{$conversation->id}/assign"), ['tenant_user_id' => $staff->id])->assertSessionHasErrors('tenant_user_id');
        $this->patch($this->appUrl("/inbox/{$conversation->id}/assign"), ['tenant_user_id' => $membership->id])->assertSessionHasNoErrors();
        $this->assertSame($membership->id, $conversation->refresh()->assigned_tenant_user_id);

        $this->patch($this->appUrl("/inbox/{$conversation->id}/status"), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertSame([ConversationStatus::Closed, 0], [$conversation->refresh()->status, $conversation->unread_count]);

        // The assignee sees it under "mine" once it is open again; a new message reopens it.
        $this->receive($tenant, '9876543210', 'One more thing');
        $this->assertSame(ConversationStatus::Open, $conversation->refresh()->status);
        $this->actingAs($receptionist);
        $this->get($this->appUrl('/inbox?view=mine'))->assertInertia(fn (Assert $page) => $page->where('conversations.meta.total', 1)->where('counts.mine', 1));
    }

    public function test_receptionists_reply_but_cannot_assign_and_staff_cannot_see_the_inbox(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210');
        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');

        $this->actingAs($receptionist);
        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('members', []));
        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => 'On it'])->assertSessionHasNoErrors();
        $this->patch($this->appUrl("/inbox/{$conversation->id}/assign"), ['tenant_user_id' => null])->assertForbidden();

        $this->actingAs($staff);
        $this->get($this->appUrl('/inbox'))->assertForbidden();
        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertForbidden();
        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => 'x'])->assertForbidden();
        $this->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page->where('inbox', null));
    }

    public function test_chat_buttons_open_the_contacts_whatsapp_conversation(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant, ['name' => 'Asha', 'phone' => '9876543210']);
        $lead = $this->makeLead($tenant, ['name' => 'Ravi', 'phone' => '9123456780']);
        $noPhone = $this->makeCustomer($tenant, ['name' => 'No phone', 'phone' => null, 'email' => 'np@example.test']);
        $this->actingAs($this->ownerOf($tenant));

        $response = $this->post($this->appUrl("/customers/{$customer->id}/chat"));
        $conversation = Conversation::withoutTenantScope()->where('contact_handle', '+919876543210')->sole();
        $response->assertRedirect(route('inbox.show', $conversation));
        $this->assertSame($customer->id, $conversation->customer_id);

        // Opening it again reuses the same conversation.
        $this->post($this->appUrl("/customers/{$customer->id}/chat"))->assertRedirect(route('inbox.show', $conversation));

        $this->post($this->appUrl("/leads/{$lead->id}/chat"));
        $this->assertSame($lead->id, Conversation::withoutTenantScope()->where('contact_handle', '+919123456780')->sole()->lead_id);

        $this->post($this->appUrl("/customers/{$noPhone->id}/chat"))->assertSessionHasErrors();
        $this->assertSame(2, Conversation::withoutTenantScope()->count());
    }

    public function test_the_inbox_is_hidden_when_messaging_is_off(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210');
        $this->disableModule($tenant, 'messaging');
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/inbox'))->assertNotFound();
        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertNotFound();
        $this->get($this->appUrl('/settings/messaging'))->assertNotFound();
        $this->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page->where('inbox', null));
    }

    public function test_the_backfill_grants_inbox_permissions_to_existing_roles(): void
    {
        // A tenant created before Phase 8.
        $tenant = $this->createTenant();
        TenantSetting::withoutTenantScope()->where('tenant_id', $tenant->id)->where('key', ProvisionTenantRoles::BACKFILLED_GROUPS_KEY)->delete();
        $conversationPermissions = Permission::query()->where('group', 'conversations')->pluck('id');
        Role::withoutTenantScope()->where('tenant_id', $tenant->id)->get()->each(fn (Role $role) => $role->permissions()->detach($conversationPermissions));

        $this->seed(TenantBackfillSeeder::class);

        $this->inTenant($tenant, function () {
            $keys = Role::query()->where('slug', 'receptionist')->sole()->permissions()->pluck('key')->all();
            $this->assertContains('conversations.view', $keys);
            $this->assertContains('conversations.reply', $keys);
            $this->assertNotContains('conversations.assign', $keys);
        });
    }

    public function test_the_simulate_command_feeds_the_inbox(): void
    {
        $tenant = $this->createTenant();

        $this->artisan('messaging:simulate-inbound', ['tenant' => $tenant->slug, 'from' => '9876543210', 'text' => 'Test message', '--name' => 'Sim'])->assertSuccessful();
        $this->artisan('messaging:simulate-inbound', ['tenant' => 'no-such-business', 'from' => '9876543210', 'text' => 'x'])->assertFailed();

        $conversation = Conversation::withoutTenantScope()->sole();
        $this->assertSame(['+919876543210', 'Sim', 1], [$conversation->contact_handle, $conversation->contact_name, $conversation->unread_count]);
    }
}
