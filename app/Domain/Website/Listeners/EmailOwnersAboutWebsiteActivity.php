<?php

namespace App\Domain\Website\Listeners;

use App\Domain\Activity\Models\Activity;
use App\Domain\Billing\Support\BillingRecipients;
use App\Domain\Booking\Events\AppointmentCreated;
use App\Domain\Commerce\Events\OrderCreated;
use App\Domain\Food\Events\ReservationCreated;
use App\Domain\Messaging\Support\MessagingSettings;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Events\WebsiteEnquiryReceived;
use App\Domain\Website\Notifications\WebsiteActivityAlert;
use App\Support\OnlineSource;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Emails the business's owners when a customer sends an enquiry or books on the public website, or books,
 * reserves or orders in a WhatsApp chat with the assistant (staff-entered records send nothing). Website
 * orders and reservations are already emailed by the default "Tell the team" automations. Off with
 * Settings → Messaging → Email alerts. Runs after the triggering transaction commits; a failure is
 * reported but never breaks the customer's request.
 */
class EmailOwnersAboutWebsiteActivity
{
    /** @var list<class-string> */
    public const EVENTS = [WebsiteEnquiryReceived::class, AppointmentCreated::class, OrderCreated::class, ReservationCreated::class];

    public function __construct(
        private readonly TenantContext $context,
        private readonly MessagingSettings $settings,
    ) {}

    public function handle(object $event): void
    {
        try {
            $tenant = $this->context->get();

            // The internal tenant's enquiries are demo requests, already emailed as DemoRequested.
            if ($tenant === null || $tenant->is_internal || ! $this->settings->ownerAlerts()) {
                return;
            }

            $alert = $this->alert($event, $tenant);
            $owners = $alert ? BillingRecipients::owners($tenant) : collect();

            if ($owners->isNotEmpty()) {
                Notification::send($owners, $alert);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function alert(object $event, Tenant $tenant): ?WebsiteActivityAlert
    {
        $app = rtrim(config('app.url'), '/');
        $at = fn (?CarbonInterface $time) => $time?->timezone($tenant->timezone ?: config('app.timezone'))->format('D j M Y, g:i A');
        $make = fn (string $kind, string $subject, string $intro, array $details, string $action, string $path) => new WebsiteActivityAlert(
            $kind, $subject, $intro, array_filter($details, fn ($value) => filled($value)), $action, $app.$path, $tenant->name,
        );

        if ($event instanceof WebsiteEnquiryReceived) {
            $lead = $event->lead;
            $message = Activity::query()->where('lead_id', $lead->id)->where('type', 'website_enquiry')->latest('id')->value('body');

            return $make('enquiry', __('New enquiry from :name', ['name' => $lead->name]), __(':name sent an enquiry on your website.', ['name' => $lead->name]), [
                __('Phone') => $lead->phone,
                __('Email') => $lead->email,
                __('Interested in') => $lead->interest,
                __('Message') => $message,
            ], __('Open the lead'), "/leads/{$lead->id}");
        }

        if ($event instanceof AppointmentCreated && OnlineSource::is($event->appointment->source)) {
            $appointment = $event->appointment->loadMissing(['customer', 'service', 'resource']);
            $customer = $appointment->customer;
            $intro = $appointment->source === 'whatsapp' ? __(':name booked on WhatsApp.', ['name' => $customer?->name]) : __(':name booked on your website.', ['name' => $customer?->name]);

            return $make('booking', __('New booking: :service, :time', ['service' => $appointment->service?->name ?? $appointment->resource?->name, 'time' => $at($appointment->starts_at)]), $intro, [
                __('Service') => $appointment->service?->name,
                __('When') => $at($appointment->starts_at),
                __('With') => $appointment->resource?->name,
                __('Phone') => $customer?->phone,
                __('Email') => $customer?->email,
                __('Notes') => $appointment->notes,
            ], __('Open the booking'), "/appointments/{$appointment->id}");
        }

        if ($event instanceof OrderCreated && $event->order->source === 'whatsapp') {
            $order = $event->order->loadMissing(['customer', 'items']);

            return $make('order', __('New WhatsApp order :number', ['number' => $order->number]), __(':name ordered on WhatsApp.', ['name' => $order->customer?->name]), [
                __('Items') => $order->items->map(fn ($item) => $item->quantity.' × '.$item->product_name)->implode(', '),
                __('Total') => $order->total,
                __('Pickup or delivery') => $order->fulfilment === 'delivery' ? __('Delivery') : __('Pickup'),
                __('Address') => $order->delivery_address,
                __('Phone') => $order->customer?->phone,
            ], __('Open the order'), "/orders/{$order->id}");
        }

        if ($event instanceof ReservationCreated && $event->reservation->source === 'whatsapp') {
            $reservation = $event->reservation->loadMissing('customer');

            return $make('reservation', __('New table reservation: :time', ['time' => $at($reservation->reserved_at)]), __(':name reserved a table on WhatsApp.', ['name' => $reservation->customer?->name]), [
                __('When') => $at($reservation->reserved_at),
                __('Guests') => (string) $reservation->party_size,
                __('Phone') => $reservation->customer?->phone,
            ], __('Open the reservation'), "/reservations/{$reservation->id}");
        }

        return null;
    }
}
