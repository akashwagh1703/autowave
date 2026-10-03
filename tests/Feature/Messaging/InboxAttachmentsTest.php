<?php

namespace Tests\Feature\Messaging;

use App\Domain\Files\Models\Attachment;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Files in the inbox: what contacts send is saved privately; staff can send one file on WhatsApp. */
class InboxAttachmentsTest extends TestCase
{
    use CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        config(['files.disks.private' => 'files']);
        Storage::fake('files');
    }

    private function png(): string
    {
        $image = UploadedFile::fake()->image('photo.png', 8, 8);

        return (string) file_get_contents($image->getRealPath());
    }

    /** @param  array<string, mixed>  $message */
    private function whatsappMedia(array $message): array
    {
        return $this->whatsappPayload([
            'contacts' => [['profile' => ['name' => 'Priya'], 'wa_id' => '919876543210']],
            'messages' => [['from' => '919876543210', 'timestamp' => (string) now()->getTimestamp(), ...$message]],
        ]);
    }

    public function test_a_photo_sent_on_whatsapp_is_saved_privately_and_shown_in_the_thread(): void
    {
        $png = $this->png();
        Http::fake([
            'graph.facebook.com/*/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'image/png', 'file_size' => strlen($png)]),
            'lookaside.fbsbx.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);

        $this->postWebhook($channel, $this->whatsappMedia(['id' => 'wamid.img', 'type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/png', 'caption' => 'My hair']]))->assertOk();

        $message = ConversationMessage::withoutTenantScope()->sole();
        $this->assertSame(['image', '[Image] My hair', 'stored'], [$message->type, $message->body, $message->meta['media']['status']]);

        $file = Attachment::withoutTenantScope()->sole();
        $this->assertSame([ConversationMessage::class, $message->id, 'image', Attachment::PRIVATE, 'files', null], [$file->attachable_type, $file->attachable_id, $file->kind, $file->visibility, $file->disk, $file->uploaded_by_user_id]);
        $this->assertStringStartsWith("tenant/{$tenant->id}/inbox/", $file->path);
        Storage::disk('files')->assertExists($file->path);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'lookaside.fbsbx.com') && $request->hasHeader('Authorization', 'Bearer wa-token'));

        $this->actingAs($this->ownerOf($tenant));
        $this->get($this->appUrl("/inbox/{$message->conversation_id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.0.body', 'My hair')
            ->where('conversation.messages.0.attachment.kind', 'image')
            ->where('conversation.messages.0.attachment.public', false)
            ->where('conversation.messages.0.media_status', null)
            ->where('conversation.files.kinds.0.kind', 'image'));
        $this->get($this->appUrl("/attachments/{$file->id}"))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame('[Image] My hair', Conversation::withoutTenantScope()->sole()->last_message_preview);
    }

    public function test_a_file_type_that_is_not_allowed_keeps_its_placeholder_with_the_reason(): void
    {
        Http::fake([
            'graph.facebook.com/*/media-zip' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=2', 'mime_type' => 'application/zip', 'file_size' => 44]),
            'lookaside.fbsbx.com/*' => Http::response("PK\x03\x04".str_repeat("\x00", 40)),
        ]);
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);

        $this->postWebhook($channel, $this->whatsappMedia(['id' => 'wamid.zip', 'type' => 'document', 'document' => ['id' => 'media-zip', 'filename' => 'photos.zip']]))->assertOk();

        $message = ConversationMessage::withoutTenantScope()->sole();
        $this->assertSame(['document', '[Document]', 'skipped'], [$message->type, $message->body, $message->meta['media']['status']]);
        $this->assertNotEmpty($message->meta['media']['error']);
        $this->assertSame(0, Attachment::withoutTenantScope()->count());

        $this->actingAs($this->ownerOf($tenant));
        $this->get($this->appUrl("/inbox/{$message->conversation_id}"))->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.0.body', '[Document]')
            ->where('conversation.messages.0.attachment', null)
            ->where('conversation.messages.0.media_status', 'skipped')
            ->where('conversation.messages.0.media_error', $message->meta['media']['error']));
    }

    public function test_oversized_files_and_links_off_metas_servers_are_never_downloaded(): void
    {
        Http::fake([
            'graph.facebook.com/*/media-big' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=3', 'mime_type' => 'video/mp4', 'file_size' => 40 * 1024 * 1024]),
            'graph.facebook.com/*/media-evil' => Http::response(['url' => 'https://evil.example.com/steal', 'mime_type' => 'image/png', 'file_size' => 10]),
            '*' => Http::response('nope', 500),
        ]);
        $tenant = $this->createTenant();
        $channel = $this->connectWhatsApp($tenant);

        $this->postWebhook($channel, $this->whatsappMedia(['id' => 'wamid.big', 'type' => 'video', 'video' => ['id' => 'media-big']]))->assertOk();
        $this->postWebhook($channel, $this->whatsappMedia(['id' => 'wamid.evil', 'type' => 'image', 'image' => ['id' => 'media-evil']]))->assertOk();

        $errors = ConversationMessage::withoutTenantScope()->orderBy('id')->get()->map(fn (ConversationMessage $message) => [$message->meta['media']['status'], $message->meta['media']['error']])->all();
        $this->assertSame('skipped', $errors[0][0]);
        $this->assertStringContainsString('larger than 16 MB', $errors[0][1]);
        $this->assertSame('skipped', $errors[1][0]);
        $this->assertStringContainsString('not on a Meta media server', $errors[1][1]);
        $this->assertSame(0, Attachment::withoutTenantScope()->count());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'lookaside.fbsbx.com') || str_contains($request->url(), 'evil.example.com'));
    }

    public function test_an_instagram_photo_is_downloaded_from_its_signed_link_without_the_token(): void
    {
        Http::fake(['lookaside.fbsbx.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $tenant = $this->createTenant();
        $channel = $this->connectInstagram($tenant, '17840000000000001');

        $this->postWebhook($channel, [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17840000000000001',
                'time' => now()->getTimestampMs(),
                'messaging' => [[
                    'sender' => ['id' => '9988776655'],
                    'recipient' => ['id' => '17840000000000001'],
                    'timestamp' => now()->getTimestampMs(),
                    'message' => ['mid' => 'ig.mid.photo', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1']]]],
                ]],
            ]],
        ])->assertOk();

        $message = ConversationMessage::withoutTenantScope()->sole();
        $this->assertSame(['image', 'stored'], [$message->type, $message->meta['media']['status']]);
        $this->assertSame('image', Attachment::withoutTenantScope()->sole()->kind);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'lookaside.fbsbx.com') && ! $request->hasHeader('Authorization'));
    }

    public function test_a_whatsapp_reply_can_carry_a_document_with_a_caption_and_sends_once(): void
    {
        Http::fake([
            'graph.facebook.com/*/1111111111/media' => Http::response(['id' => 'media-out']),
            'graph.facebook.com/*/1111111111/messages' => Http::response(['messages' => [['id' => 'wamid.file']]]),
        ]);
        $tenant = $this->createTenant();
        $this->connectWhatsApp($tenant);
        $conversation = $this->receive($tenant, '9876543210');
        $owner = $this->ownerOf($tenant);
        $this->actingAs($owner);
        $reply = ['body' => 'Here is our price list', 'file' => UploadedFile::fake()->createWithContent('Price list.pdf', self::PDF), 'client_id' => (string) Str::uuid()];

        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), $reply)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), $reply)->assertSessionHasNoErrors();

        $outbound = OutboundMessage::withoutTenantScope()->sole();
        $this->assertSame([MessageStatus::Sent, 'wamid.file'], [$outbound->status, $outbound->provider_message_id]);
        $entry = ConversationMessage::withoutTenantScope()->where('direction', 'outbound')->sole();
        $this->assertSame('document', $entry->type);

        $file = Attachment::withoutTenantScope()->sole();
        $this->assertSame([$entry->id, 'document', Attachment::PRIVATE, $owner->id], [$file->attachable_id, $file->kind, $file->visibility, $file->uploaded_by_user_id]);
        $this->assertStringStartsWith("tenant/{$tenant->id}/inbox/", $file->path);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/1111111111/media')
            && $request->isMultipart()
            && collect($request->data())->firstWhere('name', 'type')['contents'] === 'application/pdf'
            && collect($request->data())->firstWhere('name', 'file')['contents'] === self::PDF);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/1111111111/messages')
            && $request['type'] === 'document'
            && $request['document'] === ['id' => 'media-out', 'caption' => 'Here is our price list', 'filename' => 'Price list.pdf']);
        Http::assertSentCount(2);

        $this->assertSame('[Document] Here is our price list', $conversation->refresh()->last_message_preview);
        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.1.direction', 'outbound')
            ->where('conversation.messages.1.body', 'Here is our price list')
            ->where('conversation.messages.1.attachment.name', 'Price list.pdf')
            ->where('conversation.messages.1.attachment.uploaded_by', $owner->name));
    }

    public function test_a_photo_can_be_sent_without_a_caption(): void
    {
        Http::fake([
            'graph.facebook.com/*/1111111111/media' => Http::response(['id' => 'media-photo']),
            'graph.facebook.com/*/1111111111/messages' => Http::response(['messages' => [['id' => 'wamid.photo']]]),
        ]);
        $tenant = $this->createTenant();
        $this->connectWhatsApp($tenant);
        $conversation = $this->receive($tenant, '9876543210');
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => '', 'file' => UploadedFile::fake()->image('salon.png', 20, 20)])->assertSessionHasNoErrors();

        $this->assertSame('image', ConversationMessage::withoutTenantScope()->where('direction', 'outbound')->sole()->type);
        $this->assertSame('[Image]', $conversation->refresh()->last_message_preview);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages') && $request['type'] === 'image' && $request['image'] === ['id' => 'media-photo']);

        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => ''])->assertSessionHasErrors('body');
    }

    public function test_files_cannot_be_sent_on_instagram_yet(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9988776655', 'Hi', ['channel' => 'instagram']);
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('conversation.files', null));

        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => 'Menu', 'file' => UploadedFile::fake()->createWithContent('menu.pdf', self::PDF)])
            ->assertSessionHasErrors(['file' => 'Files can only be sent on WhatsApp for now.']);

        $this->assertSame(0, OutboundMessage::withoutTenantScope()->count());
        $this->assertSame(0, Attachment::withoutTenantScope()->count());
    }
}
