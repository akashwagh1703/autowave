<?php

namespace Tests\Feature\Chatbot;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Support\ChatbotSettings;
use App\Domain\Commerce\Models\Order;
use App\Domain\Education\Models\DemoClass;
use App\Domain\Food\Actions\SaveDiningTable;
use App\Domain\Food\Models\Reservation;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Notifications\WebsiteActivityAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesEducationRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Booking, reserving, ordering and demo classes inside the WhatsApp chat (ADR-021, step 2). */
class WhatsAppAssistantFlowsTest extends TestCase
{
    use CreatesBookingRecords, CreatesCommerceRecords, CreatesCrmRecords, CreatesEducationRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    private const PHONE = '9876543210';

    private Tenant $tenant;

    private BookingResource $sana;

    private Service $haircut;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelToBookingDay();
        $this->tenant = $this->createTenant();
        $this->sana = $this->makeResource($this->tenant, ['name' => 'Sana']);
        $this->haircut = $this->makeService($this->tenant, ['name' => 'Haircut', 'duration_minutes' => 45, 'price' => 500], [$this->sana]);
        $this->makeService($this->tenant, ['name' => 'Facial', 'duration_minutes' => 60, 'price' => 1200], [$this->sana]);
    }

    public function test_a_customer_books_an_appointment_in_the_chat(): void
    {
        $this->enable($this->tenant);

        $this->tap($this->tenant, 'Book now', 'aw.book', 'Priya Sharma');
        $this->assertSame(['Haircut', 'Facial'], array_column($this->lastReply()->interactive['rows'], 'title'));

        $this->tap($this->tenant, 'Haircut', 'aw.bk.svc.'.$this->haircut->id);
        $days = $this->lastReply();
        $this->assertStringContainsString('Which day suits you?', $days->body);
        $this->assertSame(['Today', 'Tomorrow'], array_slice(array_column($days->interactive['rows'], 'title'), 0, 2));
        $this->assertSame("aw.bk.day.{$this->haircut->id}.{$this->sana->id}.20261006", $days->interactive['rows'][1]['id']);

        $this->tap($this->tenant, 'Tomorrow', $days->interactive['rows'][1]['id']);
        $this->assertSame(['Morning', 'Afternoon', 'Evening'], array_column($this->lastReply()->interactive['rows'], 'title'));

        $this->tap($this->tenant, 'Morning', $this->lastReply()->interactive['rows'][0]['id']);
        $morning = $this->lastReply()->interactive['rows'];
        $this->assertSame('9:00 am', $morning[0]['title']);
        $this->assertCount(10, $morning);
        $this->assertSame('Later times', $morning[9]['title']);

        $at = $this->local($this->tenant, '2026-10-06 11:00')->getTimestamp();
        $this->tap($this->tenant, '11:00 am', "aw.bk.at.{$this->haircut->id}.{$this->sana->id}.{$at}");
        $confirm = $this->lastReply();
        $this->assertStringContainsString('Haircut (45 mins)', $confirm->body);
        $this->assertStringContainsString('Sana', $confirm->body);
        $this->assertStringContainsString('Tomorrow, 11:00 am', $confirm->body);
        $this->assertStringContainsString('₹500', $confirm->body);
        $this->assertStringContainsString('Priya Sharma', $confirm->body);
        $this->assertSame(['Confirm booking', 'Change time', 'Cancel'], array_column($confirm->interactive['buttons'], 'title'));

        $this->tap($this->tenant, 'Confirm booking', $confirm->interactive['buttons'][0]['id']);
        $this->assertStringContainsString('We have your booking request for *Haircut* with Sana on Tomorrow, 11:00 am', $this->lastReply()->body);

        $appointment = $this->inTenant($this->tenant, fn () => Appointment::query()->with('customer')->sole());
        $this->assertSame('whatsapp', $appointment->source);
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame($this->haircut->id, $appointment->service_id);
        $this->assertTrue($appointment->starts_at->equalTo($this->local($this->tenant, '2026-10-06 11:00')));
        $this->assertSame('Priya Sharma', $appointment->customer->name);
        $this->assertSame($appointment->customer_id, $this->conversation($this->tenant)->customer_id);

        Notification::assertSentTo($this->ownerOf($this->tenant), WebsiteActivityAlert::class, fn (WebsiteActivityAlert $alert) => $alert->kind === 'booking' && str_contains($alert->intro, 'WhatsApp'));

        $this->tap($this->tenant, 'Confirm booking', $confirm->interactive['buttons'][0]['id']);
        $this->assertStringContainsString('already booked', $this->lastReply()->body);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => Appointment::query()->count()));
    }

    public function test_auto_confirmed_bookings_say_so(): void
    {
        $this->enable($this->tenant);
        $this->inTenant($this->tenant, fn () => app(BookingSettings::class)->update(['online' => [...app(BookingSettings::class)->online(), 'auto_confirm' => true]]));
        $at = $this->local($this->tenant, '2026-10-06 15:00')->getTimestamp();

        $this->tap($this->tenant, '3:00 pm', "aw.bk.at.{$this->haircut->id}.{$this->sana->id}.{$at}", 'Priya Sharma');
        $this->assertStringNotContainsString('confirm it here shortly', $this->lastReply()->body);
        $this->tap($this->tenant, 'Confirm booking', "aw.bk.ok.{$this->haircut->id}.{$this->sana->id}.{$at}");

        $this->assertStringContainsString('You are booked!', $this->lastReply()->body);
        $this->assertSame(AppointmentStatus::Confirmed, $this->inTenant($this->tenant, fn () => Appointment::query()->sole()->status));
    }

    public function test_a_time_taken_meanwhile_shows_the_free_times_again(): void
    {
        $this->enable($this->tenant);
        $at = $this->local($this->tenant, '2026-10-06 11:00')->getTimestamp();

        $this->tap($this->tenant, '11:00 am', "aw.bk.at.{$this->haircut->id}.{$this->sana->id}.{$at}", 'Priya Sharma');
        $this->assertStringContainsString('Please check your booking', $this->lastReply()->body);

        $this->book($this->tenant, $this->sana, '2026-10-06 11:00');
        $this->tap($this->tenant, 'Confirm booking', "aw.bk.ok.{$this->haircut->id}.{$this->sana->id}.{$at}");

        $reply = $this->lastReply();
        $this->assertStringContainsString('What time on Tomorrow?', $reply->body);
        $this->assertSame('list', $reply->interactive['kind']);
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Appointment::query()->where('source', 'whatsapp')->count()));
    }

    public function test_the_name_is_asked_when_the_chat_has_none(): void
    {
        $this->enable($this->tenant);
        $at = $this->local($this->tenant, '2026-10-06 11:00')->getTimestamp();

        $this->tap($this->tenant, '11:00 am', "aw.bk.at.{$this->haircut->id}.{$this->sana->id}.{$at}");
        $this->assertStringContainsString('What name should we put this under?', $this->lastReply()->body);
        $this->assertSame(ChatbotSession::FLOW, $this->chatSession($this->tenant)->state);

        $this->say($this->tenant, '42');
        $this->assertStringContainsString('Please type your name', $this->lastReply()->body);

        $this->say($this->tenant, 'meera   joshi');
        $this->assertStringContainsString('Please check your booking', $this->lastReply()->body);
        $this->assertStringContainsString('Meera Joshi', $this->lastReply()->body);

        $this->tap($this->tenant, 'Confirm booking', "aw.bk.ok.{$this->haircut->id}.{$this->sana->id}.{$at}");
        $this->assertSame('Meera Joshi', $this->inTenant($this->tenant, fn () => Appointment::query()->sole()->customer->name));
    }

    public function test_back_and_cancel_leave_a_flow(): void
    {
        $this->enable($this->tenant);
        $at = $this->local($this->tenant, '2026-10-06 11:00')->getTimestamp();

        $this->tap($this->tenant, '11:00 am', "aw.bk.at.{$this->haircut->id}.{$this->sana->id}.{$at}");
        $this->say($this->tenant, 'back');

        $this->assertNotSame(ChatbotSession::FLOW, $this->chatSession($this->tenant)->state);
        $this->assertContains('aw.book', array_column($this->lastReply()->interactive['rows'] ?? $this->lastReply()->interactive['buttons'], 'id'));

        $this->tap($this->tenant, 'Cancel', 'aw.bk.no');
        $this->assertStringContainsString('nothing was booked', $this->lastReply()->body);
        $this->assertFalse($this->conversation($this->tenant)->isOptedOut());
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Appointment::query()->count()));
    }

    public function test_options_naming_another_businesss_service_book_nothing(): void
    {
        $other = $this->createTenant('Other Salon');
        $resource = $this->makeResource($other);
        $foreign = $this->makeService($other, ['name' => 'Foreign cut'], [$resource]);
        $this->enable($this->tenant);
        $at = $this->local($this->tenant, '2026-10-06 11:00')->getTimestamp();

        $this->tap($this->tenant, 'Foreign cut', 'aw.bk.svc.'.$foreign->id, 'Priya Sharma');
        $this->assertNotContains('Foreign cut', array_column($this->lastReply()->interactive['rows'], 'title'));

        $this->tap($this->tenant, 'Confirm booking', "aw.bk.ok.{$foreign->id}.{$resource->id}.{$at}");

        $this->assertSame(0, Appointment::withoutTenantScope()->count());
    }

    public function test_a_guest_reserves_a_table_in_the_chat(): void
    {
        $cafe = $this->createTenant('ABC Cafe', 'cafe');
        $this->enable($cafe);
        $this->inTenant($cafe, fn () => app(SaveDiningTable::class)->handle(['name' => 'Banquet', 'seats' => 12]));

        $this->tap($cafe, 'Reserve a table', 'aw.reserve', 'Rohan Mehta');
        $days = $this->lastReply();
        $this->assertStringContainsString('Which day would you like to come?', $days->body);
        $this->assertSame('aw.rv.day.20261006', $days->interactive['rows'][1]['id']);

        $this->tap($cafe, 'Tomorrow', 'aw.rv.day.20261006');
        $parts = $this->lastReply()->interactive['rows'];
        $this->tap($cafe, 'Evening', collect($parts)->firstWhere('title', 'Evening')['id']);
        $this->assertContains('7:00 pm', array_column($this->lastReply()->interactive['rows'], 'title'));

        $at = $this->local($cafe, '2026-10-06 19:00')->getTimestamp();
        $this->tap($cafe, '7:00 pm', "aw.rv.at.{$at}");
        $guests = $this->lastReply()->interactive['rows'];
        $this->assertCount(10, $guests);
        $this->assertSame('2 guests', $guests[1]['title']);
        $this->assertSame("aw.rv.big.{$at}", $guests[9]['id']);

        $this->tap($cafe, '10 or more', "aw.rv.big.{$at}");
        $this->say($cafe, 'We are 15');
        $this->assertStringContainsString('groups larger than 12', $this->lastReply()->body);

        $this->tap($cafe, '10 or more', "aw.rv.big.{$at}");
        $this->say($cafe, '12 people');
        $confirm = $this->lastReply();
        $this->assertStringContainsString('Tomorrow, 7:00 pm', $confirm->body);
        $this->assertStringContainsString('12 guests', $confirm->body);
        $this->assertStringContainsString('Rohan Mehta', $confirm->body);

        $this->tap($cafe, 'Confirm', "aw.rv.ok.{$at}.12");
        $this->assertStringContainsString('We have your table request for 12 guests on Tomorrow, 7:00 pm', $this->lastReply()->body);

        $reservation = $this->inTenant($cafe, fn () => Reservation::query()->sole());
        $this->assertSame('whatsapp', $reservation->source);
        $this->assertSame(12, $reservation->party_size);
        $this->assertTrue($reservation->reserved_at->equalTo($this->local($cafe, '2026-10-06 19:00')));
        $this->assertSame($reservation->customer_id, $this->conversation($cafe)->customer_id);
        Notification::assertSentTo($this->ownerOf($cafe), WebsiteActivityAlert::class, fn (WebsiteActivityAlert $alert) => $alert->kind === 'reservation');

        $this->tap($cafe, 'Confirm', "aw.rv.ok.{$at}.12");
        $this->assertStringContainsString('already requested', $this->lastReply()->body);
        $this->assertSame(1, $this->inTenant($cafe, fn () => Reservation::query()->count()));
    }

    public function test_a_customer_orders_for_delivery_in_the_chat(): void
    {
        $serum = $this->makeProduct($this->tenant, ['name' => 'Hair serum', 'price' => '450.00'], stock: 5);
        $this->makeProduct($this->tenant, ['name' => 'Wooden comb', 'price' => '120.00']);
        $this->setOnlineOrdering($this->tenant, ['delivery' => true, 'delivery_fee' => 40]);
        $this->enable($this->tenant);

        $this->tap($this->tenant, 'Order online', 'aw.order', 'Priya Sharma');
        $this->assertSame(['Hair serum', 'Wooden comb'], array_column($this->lastReply()->interactive['rows'], 'title'));

        $this->tap($this->tenant, 'Hair serum', 'aw.or.p.'.$serum->id);
        $this->assertSame(['Add to cart', 'Back to list', 'Main menu'], array_column($this->lastReply()->interactive['buttons'], 'title'));

        $this->tap($this->tenant, 'Add to cart', 'aw.or.add.'.$serum->id);
        $this->assertCount(5, $this->lastReply()->interactive['rows']);

        $this->tap($this->tenant, '2', "aw.or.qty.{$serum->id}.2");
        $this->assertStringContainsString('Added 2 × Hair serum', $this->lastReply()->body);
        $this->assertStringContainsString('₹900', $this->lastReply()->body);

        $this->tap($this->tenant, 'Checkout', 'aw.or.checkout');
        $this->assertSame(['Pickup', 'Delivery', 'View cart'], array_column($this->lastReply()->interactive['buttons'], 'title'));

        $this->tap($this->tenant, 'Delivery', 'aw.or.ful.delivery');
        $this->assertStringContainsString('delivery address', $this->lastReply()->body);
        $this->say($this->tenant, 'Pune');
        $this->assertStringContainsString('full delivery address', $this->lastReply()->body);

        $this->say($this->tenant, 'Flat 5, Lane 2, Baner, Pune');
        $confirm = $this->lastReply();
        $this->assertStringContainsString('2 × Hair serum', $confirm->body);
        $this->assertStringContainsString('Delivery: ₹40', $confirm->body);
        $this->assertStringContainsString('Total: ₹940', $confirm->body);
        $this->assertStringContainsString('Delivery to: Flat 5, Lane 2, Baner, Pune', $confirm->body);

        $this->tap($this->tenant, 'Place order', 'aw.or.ok');
        $this->assertStringContainsString('We have your order', $this->lastReply()->body);

        $order = $this->inTenant($this->tenant, fn () => Order::query()->with('items')->sole());
        $this->assertSame('whatsapp', $order->source);
        $this->assertSame('delivery', $order->fulfilment);
        $this->assertSame('Flat 5, Lane 2, Baner, Pune', $order->delivery_address);
        $this->assertSame('940.00', (string) $order->total);
        $this->assertSame([$serum->id], $order->items->pluck('product_id')->all());
        $this->assertSame($order->customer_id, $this->conversation($this->tenant)->customer_id);
        Notification::assertSentTo($this->ownerOf($this->tenant), WebsiteActivityAlert::class, fn (WebsiteActivityAlert $alert) => $alert->kind === 'order');

        $this->tap($this->tenant, 'Place order', 'aw.or.ok');
        $this->assertStringContainsString('already placed', $this->lastReply()->body);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => Order::query()->count()));
    }

    public function test_pickup_orders_skip_the_address(): void
    {
        $comb = $this->makeProduct($this->tenant, ['name' => 'Wooden comb', 'price' => '120.00']);
        $this->enable($this->tenant);

        $this->tap($this->tenant, '1', "aw.or.qty.{$comb->id}.1", 'Priya Sharma');
        $this->tap($this->tenant, 'Checkout', 'aw.or.checkout');
        $this->assertStringContainsString('Pickup from ABC Salon', $this->lastReply()->body);

        $this->tap($this->tenant, 'Place order', 'aw.or.ok');

        $order = $this->inTenant($this->tenant, fn () => Order::query()->sole());
        $this->assertSame('pickup', $order->fulfilment);
        $this->assertSame('whatsapp', $order->source);
    }

    public function test_a_student_books_a_free_demo_class_in_the_chat(): void
    {
        $coaching = $this->createCoaching();
        $course = $this->makeCourse($coaching, ['name' => 'JEE Foundation']);
        $batch = $this->makeBatch($coaching, $course, ['name' => 'Evening']);
        $this->enable($coaching);

        $this->tap($coaching, 'JEE Foundation', 'aw.course.'.$course->id, 'Anil Kumar');
        $this->assertSame('aw.dm.c.'.$course->id, $this->lastReply()->interactive['buttons'][0]['id']);

        $this->tap($coaching, 'Free demo class', 'aw.dm.c.'.$course->id);
        $days = $this->lastReply();
        $this->assertStringContainsString('Which day would you like to come for the demo?', $days->body);
        $this->assertSame(['Today', 'Wed 7 Oct'], array_slice(array_column($days->interactive['rows'], 'title'), 0, 2));
        $this->assertSame("aw.dm.at.{$batch->id}.20261005", $days->interactive['rows'][0]['id']);

        $this->tap($coaching, 'Today', "aw.dm.at.{$batch->id}.20261005");
        $this->assertStringContainsString('JEE Foundation · Evening', $this->lastReply()->body);
        $this->assertStringContainsString('Today, 5:00 pm', $this->lastReply()->body);

        $this->tap($coaching, 'Confirm', "aw.dm.ok.{$batch->id}.20261005");
        $this->assertStringContainsString('Your free demo class is booked for Today, 5:00 pm', $this->lastReply()->body);

        $demo = $this->inTenant($coaching, fn () => DemoClass::query()->sole());
        $this->assertSame($batch->id, $demo->batch_id);
        $this->assertSame($this->conversation($coaching)->lead_id, $demo->lead_id);
        $this->assertTrue($demo->scheduled_at->equalTo($this->local($coaching, '2026-10-05 17:00')));
        $this->assertSame('Booked on WhatsApp', $demo->notes);
        Notification::assertSentTo($this->ownerOf($coaching), WebsiteActivityAlert::class, fn (WebsiteActivityAlert $alert) => $alert->kind === 'whatsapp_demo');

        $this->tap($coaching, 'Confirm', "aw.dm.ok.{$batch->id}.20261005");
        $this->assertStringContainsString('already booked', $this->lastReply()->body);
        $this->assertSame(1, $this->inTenant($coaching, fn () => DemoClass::query()->count()));
    }

    private function enable(Tenant $tenant): void
    {
        $this->inTenant($tenant, fn () => app(ChatbotSettings::class)->update([
            ...app(ChatbotSettings::class)->all(),
            'enabled' => true,
        ]));
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

    private function conversation(Tenant $tenant): Conversation
    {
        return Conversation::withoutTenantScope()->where('tenant_id', $tenant->id)->sole();
    }

    private function chatSession(Tenant $tenant): ChatbotSession
    {
        return ChatbotSession::withoutTenantScope()->where('tenant_id', $tenant->id)->sole();
    }
}
