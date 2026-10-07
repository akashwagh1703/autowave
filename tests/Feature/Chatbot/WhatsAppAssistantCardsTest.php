<?php

namespace Tests\Feature\Chatbot;

use App\Domain\Chatbot\Support\ChatbotSettings;
use App\Domain\Media\Models\Media;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Support\Interactive;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesEducationRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Products, services and courses as swipeable photo cards (WhatsApp carousel) in the assistant. */
class WhatsAppAssistantCardsTest extends TestCase
{
    use CreatesBookingRecords, CreatesCommerceRecords, CreatesCrmRecords, CreatesEducationRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    private const PHONE = '9876543210';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');
        $this->travelToBookingDay();
        $this->tenant = $this->createTenant();
    }

    public function test_products_with_photos_come_as_cards_through_the_cloud_api(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]])]);
        $this->connectWhatsApp($this->tenant);
        $serum = $this->makeProduct($this->tenant, ['name' => 'Hair serum', 'price' => '450.00', 'description' => "Light,\n\nnon-sticky   serum for frizzy hair."], stock: 5);
        $this->makeProduct($this->tenant, ['name' => 'Wooden comb', 'price' => '120.00']);
        $this->photo($this->tenant, $serum, 'product', 'serum.jpg');
        $this->media($this->tenant, 'logo', 'logo.png', 'image/png');
        $this->setOnlineOrdering($this->tenant, ['delivery' => true]);
        $this->enable($this->tenant);

        $this->tap($this->tenant, 'Order online', 'aw.order');

        $reply = $this->lastReply();
        $this->assertSame('carousel', $reply->interactive['kind']);
        $this->assertStringContainsString('Swipe through the items', $reply->body);
        [$first, $second] = $reply->interactive['cards'];
        $this->assertSame("*Hair serum*\n₹450\nLight, non-sticky serum for frizzy hair.", $first['text']);
        $this->assertSame('tenant/serum.jpg', $first['image']['path']);
        $this->assertSame('tenant/logo.png', $second['image']['path'], 'an item without a photo shows the logo');
        $this->assertSame([['id' => 'aw.or.add.'.$serum->id, 'title' => 'Add to cart'], ['id' => 'aw.or.p.'.$serum->id, 'title' => 'Details']], $first['buttons']);

        Http::assertSent(function (Request $request) use ($serum) {
            $card = $request['interactive']['action']['cards'][0] ?? null;

            return ($request['interactive']['type'] ?? null) === 'carousel'
                && $request['interactive']['body']['text'] !== ''
                && count($request['interactive']['action']['cards']) === 2
                && $card['card_index'] === 0
                && $card['header'] === ['type' => 'image', 'image' => ['link' => rtrim(config('app.url'), '/').'/storage/tenant/serum.jpg']]
                && $card['body']['text'] === "*Hair serum*\n₹450\nLight, non-sticky serum for frizzy hair."
                && $card['action']['buttons'][0] === ['type' => 'quick_reply', 'quick_reply' => ['id' => 'aw.or.add.'.$serum->id, 'title' => 'Add to cart']]
                && $request['interactive']['action']['cards'][1]['card_index'] === 1;
        });

        $this->inTenant($this->tenant, function () use ($reply) {
            $entry = ConversationMessage::query()->where('outbound_message_id', $reply->id)->sole();
            $this->assertSame(['Add to cart', 'Details'], $entry->meta['cards'][0]['buttons']);
            $this->assertSame('/storage/tenant/serum.jpg', $entry->meta['cards'][0]['image']);
            $this->assertArrayNotHasKey('options', $entry->meta);
        });

        $this->say($this->tenant, '2');
        $this->assertStringContainsString('*Wooden comb*', $this->lastReply()->body, 'a typed number picks a card');

        $this->tap($this->tenant, 'Add to cart', 'aw.or.add.'.$serum->id);
        $this->assertStringContainsString('How many *Hair serum*?', $this->lastReply()->body);
    }

    public function test_the_list_stays_without_photos_or_with_more_than_ten_items(): void
    {
        $this->media($this->tenant, 'logo', 'logo.png', 'image/png');
        $products = collect(range(1, 11))->map(fn (int $n) => $this->makeProduct($this->tenant, ['name' => "Item {$n}", 'price' => '10.00']));
        $this->setOnlineOrdering($this->tenant, ['delivery' => true]);
        $this->enable($this->tenant);

        $this->tap($this->tenant, 'Order online', 'aw.order');
        $this->assertSame('list', $this->lastReply()->interactive['kind'], 'more than ten items');

        $products->slice(2)->each(fn ($product) => $this->inTenant($this->tenant, fn () => $product->delete()));
        $this->tap($this->tenant, 'Order online', 'aw.order');
        $this->assertSame('list', $this->lastReply()->interactive['kind'], 'only the logo, no photo of their own');

        $this->photo($this->tenant, $products[0], 'product', 'one.jpg');
        $this->tap($this->tenant, 'Order online', 'aw.order');
        $this->assertSame('carousel', $this->lastReply()->interactive['kind']);
    }

    public function test_services_come_as_cards_in_services_and_in_booking(): void
    {
        $sana = $this->makeResource($this->tenant, ['name' => 'Sana']);
        $haircut = $this->makeService($this->tenant, ['name' => 'Haircut', 'duration_minutes' => 45, 'price' => 500], [$sana]);
        $facial = $this->makeService($this->tenant, ['name' => 'Facial', 'duration_minutes' => 60, 'price' => 1200], [$sana]);
        $this->photo($this->tenant, $haircut, 'service', 'haircut.jpg');
        $this->photo($this->tenant, $facial, 'service', 'facial.png', 'image/png');
        $this->enable($this->tenant);

        $this->tap($this->tenant, 'Services & prices', 'aw.services');
        $cards = $this->lastReply()->interactive['cards'];
        $this->assertSame("*Haircut*\n₹500 · 45 min", $cards[0]['text']);
        $this->assertSame([['id' => 'aw.svc.'.$haircut->id, 'title' => 'Details'], ['id' => 'aw.book.'.$haircut->id, 'title' => 'Book this']], $cards[0]['buttons']);

        $this->tap($this->tenant, 'Book now', 'aw.book');
        $cards = $this->lastReply()->interactive['cards'];
        $this->assertSame([['id' => 'aw.bk.svc.'.$facial->id, 'title' => 'Book this'], ['id' => 'aw.ask.svc.'.$facial->id, 'title' => 'Ask about this']], $cards[1]['buttons']);

        $this->tap($this->tenant, 'Book this', 'aw.bk.svc.'.$haircut->id);
        $this->assertStringContainsString('Which day suits you?', $this->lastReply()->body);
    }

    public function test_courses_come_as_cards_with_a_free_demo_button(): void
    {
        $coaching = $this->createCoaching();
        $jee = $this->makeCourse($coaching, ['name' => 'JEE Foundation']);
        $neet = $this->makeCourse($coaching, ['name' => 'NEET Prep']);
        $this->makeBatch($coaching, $jee, ['name' => 'Evening']);
        $this->photo($coaching, $jee, 'course', 'jee.jpg');
        $this->photo($coaching, $neet, 'course', 'neet.jpg');
        $this->enable($coaching);

        $this->tap($coaching, 'Courses & fees', 'aw.courses', 'Anil Kumar');

        $cards = $this->lastReply()->interactive['cards'];
        $this->assertSame(['JEE Foundation', 'NEET Prep'], array_column($cards, 'title'));
        $this->assertSame([['id' => 'aw.course.'.$jee->id, 'title' => 'Details'], ['id' => 'aw.dm.c.'.$jee->id, 'title' => 'Free demo class']], $cards[0]['buttons']);
        $this->assertSame('aw.ask.demo.'.$neet->id, $cards[1]['buttons'][1]['id'], 'no batch timetable: the request goes to the team');
    }

    public function test_cards_follow_whatsapp_rules_and_other_channels_get_numbered_text(): void
    {
        $photo = new Media(['disk' => 'public', 'path' => 'p.jpg', 'mime_type' => 'image/jpeg']);
        $webp = new Media(['disk' => 'public', 'path' => 'p.webp', 'mime_type' => 'image/webp']);
        $card = fn (string $title, ?Media $image, int $buttons = 2) => [
            'id' => "aw.x.{$title}", 'title' => $title, 'text' => str_repeat('Long text ', 30)."\nline 2\nline 3\nline 4", 'image' => $image,
            'buttons' => array_slice([['id' => 'a', 'title' => 'A button with a long title'], ['id' => 'b', 'title' => 'B'], ['id' => 'c', 'title' => 'C']], 0, $buttons),
        ];

        $this->assertNull(Interactive::carousel([$card('One', $photo)]), 'at least two cards');
        $this->assertNull(Interactive::carousel(array_fill(0, 11, $card('Many', $photo))), 'at most ten cards');
        $this->assertNull(Interactive::carousel([$card('One', $photo), $card('Two', null)]), 'every card needs a photo');
        $this->assertNull(Interactive::carousel([$card('One', $webp), $card('Two', $webp)], $photo), 'WebP is not shown by WhatsApp');
        $this->assertNull(Interactive::carousel([$card('One', $photo, 2), $card('Two', $photo, 1)]), 'same buttons on every card');

        $carousel = Interactive::carousel([$card('One', $photo, 3), $card('Two', $photo, 3)]);
        $this->assertCount(2, $carousel['cards'][0]['buttons']);
        $this->assertSame('A button with a lon…', $carousel['cards'][0]['buttons'][0]['title']);
        $this->assertLessThanOrEqual(160, mb_strlen($carousel['cards'][0]['text']));
        $this->assertLessThanOrEqual(2, substr_count($carousel['cards'][0]['text'], "\n"));
        $this->assertSame("Pick one\n\n1. One\n2. Two", Interactive::fallbackText('Pick one', $carousel));
    }

    public function test_photos_of_services_and_courses_are_uploaded_and_removed_on_their_pages(): void
    {
        $owner = $this->ownerOf($this->tenant);
        $service = $this->makeService($this->tenant, ['name' => 'Haircut']);
        $this->actingAs($owner);

        $this->post($this->appUrl("/services/{$service->id}/image"), ['image' => UploadedFile::fake()->image('cut.jpg', 600, 600)])->assertSessionHasNoErrors();
        $media = $this->inTenant($this->tenant, fn () => $service->fresh()->image);
        $this->assertSame('service', $media->collection);
        Storage::disk('public')->assertExists($media->path);
        $this->get($this->appUrl("/services/{$service->id}/edit"))->assertInertia(fn (Assert $page) => $page->where('service.image.url', $media->url()));

        $this->post($this->appUrl("/services/{$service->id}/image"), ['image' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');
        $this->delete($this->appUrl("/services/{$service->id}/image"))->assertSessionHasNoErrors();
        $this->assertNull($this->inTenant($this->tenant, fn () => $service->fresh()->image_media_id));
        Storage::disk('public')->assertMissing($media->path);

        $coaching = $this->createCoaching();
        $course = $this->makeCourse($coaching, ['name' => 'JEE Foundation']);
        $this->actingAs($this->ownerOf($coaching));
        $this->post($this->appUrl("/courses/{$course->id}/image"), ['image' => UploadedFile::fake()->image('jee.png', 800, 450)])->assertSessionHasNoErrors();
        $this->get($this->appUrl('/courses'))->assertInertia(fn (Assert $page) => $page->whereNot('courses.0.image', null));

        $this->post($this->appUrl("/services/{$service->id}/image"), ['image' => UploadedFile::fake()->image('x.jpg', 600, 600)])->assertNotFound();
        $this->delete($this->appUrl("/services/{$service->id}/image"))->assertNotFound();
    }

    private function enable(Tenant $tenant): void
    {
        $this->inTenant($tenant, fn () => app(ChatbotSettings::class)->update([...app(ChatbotSettings::class)->all(), 'enabled' => true]));
    }

    private function media(Tenant $tenant, string $collection, string $file, string $mime = 'image/jpeg'): Media
    {
        return $this->inTenant($tenant, fn () => Media::query()->create([
            'collection' => $collection, 'disk' => 'public', 'path' => "tenant/{$file}", 'original_name' => $file, 'mime_type' => $mime, 'size_bytes' => 10, 'width' => 600, 'height' => 600,
        ]));
    }

    private function photo(Tenant $tenant, Model $record, string $collection, string $file, string $mime = 'image/jpeg'): void
    {
        $media = $this->media($tenant, $collection, $file, $mime);
        $this->inTenant($tenant, fn () => $record->forceFill(['image_media_id' => $media->id])->save());
    }

    /** A tapped option, a few seconds after the last one (replies are rate limited per minute). */
    private function tap(Tenant $tenant, string $title, string $id, ?string $name = null): void
    {
        $this->travel(10)->seconds();
        $this->receive($tenant, self::PHONE, $title, array_filter(['reply' => $id, 'name' => $name]));
    }

    private function say(Tenant $tenant, string $text): void
    {
        $this->travel(10)->seconds();
        $this->receive($tenant, self::PHONE, $text);
    }

    private function lastReply(): OutboundMessage
    {
        return OutboundMessage::withoutTenantScope()->where('assistant', true)->latest('id')->firstOrFail();
    }
}
