<?php

namespace Tests\Feature\Commerce;

use App\Domain\Billing\Support\QrCode;
use App\Domain\Commerce\Actions\ChangeOrderStatus;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Commerce\Support\OrderStatusLink;
use App\Domain\Messaging\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OrderConfirmationWhatsAppTest extends TestCase
{
    use CreatesAutomations, CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const SALON = 'abc-salon.autowave.test';

    public function test_confirmation_whatsapp_with_qr_is_sent_when_enabled(): void
    {
        Storage::fake((string) config('files.disks.private'));

        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $product = $this->makeProduct($tenant, ['name' => 'Hair serum', 'price' => '450.00']);
        $customer = $this->makeCustomer($tenant, ['name' => 'Asha Rao', 'phone' => '+91 98765 43210']);

        $this->inTenant($tenant, function () {
            $settings = app(CommerceSettings::class);
            $settings->updateConfirmation([
                ...$settings->confirmation(),
                'whatsapp' => true,
                'include_qr' => true,
                'qr_payload' => 'reference',
                'message' => 'Hi {{customer.first_name}}, order {{order.number}} confirmed ({{order.total}}).',
            ]);
        });

        $order = $this->placeOrder($tenant, [[$product, 2]], ['customer_id' => $customer->id]);

        $message = $this->inTenant($tenant, fn () => OutboundMessage::query()->sole());
        $this->assertSame('+919876543210', $message->recipient);
        $this->assertSame('Hi Asha, order #1001 confirmed (900.00).', $message->body);
        $this->assertSame('image', $message->interactive['kind'] ?? null);
        $this->assertSame('image/png', $message->interactive['image']['mime'] ?? null);
        Storage::disk((string) config('files.disks.private'))->assertExists($message->interactive['image']['path']);
        $this->assertStringStartsWith("\x89PNG", Storage::disk((string) config('files.disks.private'))->get($message->interactive['image']['path']));
    }

    public function test_confirmation_is_skipped_when_setting_is_off(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $product = $this->makeProduct($tenant, ['name' => 'Soap', 'price' => '35.00']);
        $customer = $this->makeCustomer($tenant, ['phone' => '9876543210']);

        $this->placeOrder($tenant, [[$product, 1]], ['customer_id' => $customer->id]);

        $this->assertSame(0, $this->inTenant($tenant, fn () => OutboundMessage::query()->count()));
    }

    public function test_pending_website_order_sends_on_confirm_not_on_place(): void
    {
        Storage::fake((string) config('files.disks.private'));

        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $this->setOnlineOrdering($tenant, ['auto_confirm' => false]);
        $product = $this->makeProduct($tenant, ['name' => 'Oil', 'price' => '100.00']);
        $customer = $this->makeCustomer($tenant, ['name' => 'Ravi', 'phone' => '9988776655']);

        $this->inTenant($tenant, function () {
            $settings = app(CommerceSettings::class);
            $settings->updateConfirmation([
                ...$settings->confirmation(),
                'whatsapp' => true,
                'include_qr' => false,
                'message' => 'Confirmed {{order.number}} for {{customer.first_name}}.',
            ]);
        });

        $order = $this->placeOrder($tenant, [[$product, 1]], [
            'customer_id' => $customer->id,
            'source' => 'website',
            'fulfilment' => 'pickup',
        ]);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(0, $this->inTenant($tenant, fn () => OutboundMessage::query()->count()));

        $this->inTenant($tenant, fn () => app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed));

        $message = $this->inTenant($tenant, fn () => OutboundMessage::query()->sole());
        $this->assertSame('Confirmed #1001 for Ravi.', $message->body);
        $this->assertNull($message->interactive);
    }

    public function test_signed_order_status_page_opens_from_qr_link(): void
    {
        $tenant = $this->createTenant();
        $product = $this->makeProduct($tenant, ['name' => 'Shampoo', 'price' => '200.00']);
        $customer = $this->makeCustomer($tenant, ['name' => 'Meera']);
        $order = $this->placeOrder($tenant, [[$product, 1]], ['customer_id' => $customer->id, 'fulfilment' => 'pickup']);

        $url = $this->inTenant($tenant, fn () => OrderStatusLink::url($order));
        $this->assertNotEmpty($url);
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $this->get($this->siteUrl(self::SALON, $path))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('website/OrderStatus')
                ->where('order.number', '#1001')
                ->where('order.customer', 'Meera')
                ->where('business.name', 'ABC Salon'));

        $this->get($this->siteUrl(self::SALON, '/orders/'.$order->id.'/status'))->assertForbidden();
    }

    public function test_qr_png_helper_returns_png_bytes(): void
    {
        $png = QrCode::png('AW-1-#1001', 200);
        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertStringStartsWith('data:image/png;base64,', QrCode::pngDataUri('AW-1-#1001', 200));
    }
}
