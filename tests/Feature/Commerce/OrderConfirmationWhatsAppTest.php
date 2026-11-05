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

    public function test_pending_order_gets_payment_request_with_qr_then_confirmation_without_qr(): void
    {
        Storage::fake((string) config('files.disks.private'));

        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $this->setOnlineOrdering($tenant, ['auto_confirm' => false]);
        $product = $this->makeProduct($tenant, ['name' => 'Oil', 'price' => '100.00']);
        $customer = $this->makeCustomer($tenant, ['name' => 'Ravi Kumar', 'phone' => '9988776655']);

        $this->inTenant($tenant, function () {
            $settings = app(CommerceSettings::class);
            $settings->updateConfirmation([
                ...$settings->confirmation(),
                'whatsapp' => true,
                'include_qr' => true,
                'qr_payload' => 'reference',
                'payment_message' => 'Pay {{order.total}} for {{order.number}}, {{customer.first_name}}.',
                'message' => 'Confirmed {{order.number}} for {{customer.first_name}}.',
            ]);
        });

        $order = $this->placeOrder($tenant, [[$product, 1]], [
            'customer_id' => $customer->id,
            'source' => 'website',
            'fulfilment' => 'pickup',
        ]);

        $this->assertSame(OrderStatus::Pending, $order->status);

        $payment = $this->inTenant($tenant, fn () => OutboundMessage::query()->sole());
        $this->assertSame('+919988776655', $payment->recipient);
        $this->assertSame('Pay 100.00 for #1001, Ravi.', $payment->body);
        $this->assertSame('image', $payment->interactive['kind'] ?? null);
        Storage::disk((string) config('files.disks.private'))->assertExists($payment->interactive['image']['path']);

        $this->inTenant($tenant, fn () => app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed));

        $messages = $this->inTenant($tenant, fn () => OutboundMessage::query()->orderBy('id')->get());
        $this->assertCount(2, $messages);
        $this->assertSame('Confirmed #1001 for Ravi.', $messages[1]->body);
        $this->assertNull($messages[1]->interactive);
    }

    public function test_counter_confirmed_order_sends_confirmation_without_payment_qr(): void
    {
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

        $this->placeOrder($tenant, [[$product, 2]], ['customer_id' => $customer->id]);

        $message = $this->inTenant($tenant, fn () => OutboundMessage::query()->sole());
        $this->assertSame('+919876543210', $message->recipient);
        $this->assertSame('Hi Asha, order #1001 confirmed (900.00).', $message->body);
        $this->assertNull($message->interactive);
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
