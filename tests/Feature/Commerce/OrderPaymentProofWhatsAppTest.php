<?php

namespace Tests\Feature\Commerce;

use App\Domain\Activity\Models\Activity;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Files\Models\Attachment;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Pending order ← WhatsApp payment screenshot → shown on the order for staff review. */
class OrderPaymentProofWhatsAppTest extends TestCase
{
    use CreatesAutomations, CreatesCommerceRecords, CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['files.disks.private' => 'files']);
        Storage::fake('files');
    }

    public function test_whatsapp_payment_screenshot_is_copied_onto_the_pending_order(): void
    {
        $image = UploadedFile::fake()->image('pay.png', 12, 12);
        $png = (string) file_get_contents($image->getRealPath());
        Http::fake([
            'graph.facebook.com/*/media-pay' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=pay',
                'mime_type' => 'image/png',
                'file_size' => strlen($png),
            ]),
            'lookaside.fbsbx.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $this->setOnlineOrdering($tenant, ['auto_confirm' => false]);
        $this->inTenant($tenant, function () {
            $settings = app(CommerceSettings::class);
            $settings->updateConfirmation([
                ...$settings->confirmation(),
                'whatsapp' => true,
                'include_qr' => false,
                'payment_message' => 'Please pay {{order.total}} for {{order.number}}.',
                'message' => 'Confirmed {{order.number}}.',
            ]);
        });

        $product = $this->makeProduct($tenant, ['name' => 'Coffee', 'price' => '80.00']);
        $customer = $this->makeCustomer($tenant, ['name' => 'Priya', 'phone' => '9876543210']);
        $channel = $this->connectWhatsApp($tenant);

        // Customer already chatting (places the order on WhatsApp) — opens the 24-hour window.
        $this->postWebhook($channel, $this->whatsappText('919876543210', 'Hi', 'wamid.hi', 'Priya'))->assertOk();
        $this->inTenant($tenant, function () use ($customer) {
            Conversation::query()->where('channel', 'whatsapp')->where('contact_handle', '+919876543210')
                ->update(['customer_id' => $customer->id]);
        });

        $order = $this->placeOrder($tenant, [[$product, 1]], [
            'customer_id' => $customer->id,
            'source' => 'whatsapp',
            'fulfilment' => 'pickup',
        ]);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(1, $this->inTenant($tenant, fn () => OutboundMessage::query()->where('idempotency_key', 'order-payment-wa:'.$order->id)->count()));

        $this->postWebhook($channel, $this->whatsappPayload([
            'contacts' => [['profile' => ['name' => 'Priya'], 'wa_id' => '919876543210']],
            'messages' => [[
                'from' => '919876543210',
                'id' => 'wamid.pay',
                'timestamp' => (string) now()->getTimestamp(),
                'type' => 'image',
                'image' => ['id' => 'media-pay', 'mime_type' => 'image/png', 'caption' => 'Paid'],
            ]],
        ]))->assertOk();

        $message = ConversationMessage::withoutTenantScope()->where('provider_message_id', 'wamid.pay')->sole();
        $this->assertSame('stored', $message->meta['media']['status']);
        $this->assertSame($order->id, $message->meta['payment_proof_order_id']);

        $proofs = Attachment::withoutTenantScope()
            ->where('attachable_type', \App\Domain\Commerce\Models\Order::class)
            ->where('attachable_id', $order->id)
            ->get();
        $this->assertCount(1, $proofs);
        $this->assertSame('image', $proofs[0]->kind);
        Storage::disk('files')->assertExists($proofs[0]->path);

        $this->assertTrue(Activity::withoutTenantScope()
            ->where('order_id', $order->id)
            ->where('type', 'payment_proof_received')
            ->exists());

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/orders/'.$order->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/orders/Show')
                ->has('order.payment_proofs', 1)
                ->where('order.payment_proofs.0.kind', 'image'));
    }
}
