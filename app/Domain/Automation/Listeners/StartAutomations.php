<?php

namespace App\Domain\Automation\Listeners;

use App\Domain\Automation\Services\AutomationResolver;
use App\Domain\Booking\Events\AppointmentCancelled;
use App\Domain\Booking\Events\AppointmentCompleted;
use App\Domain\Booking\Events\AppointmentConfirmed;
use App\Domain\Booking\Events\AppointmentCreated;
use App\Domain\Booking\Events\AppointmentNoShow;
use App\Domain\Booking\Events\AppointmentRescheduled;
use App\Domain\Commerce\Events\OrderCancelled;
use App\Domain\Commerce\Events\OrderCompleted;
use App\Domain\Commerce\Events\OrderConfirmed;
use App\Domain\Commerce\Events\OrderCreated;
use App\Domain\Commerce\Events\OrderPaid;
use App\Domain\Commerce\Events\OrderReady;
use App\Domain\Customer\Events\CustomerCreated;
use App\Domain\Education\Events\DemoScheduled;
use App\Domain\Education\Events\EnrolmentCreated;
use App\Domain\Education\Events\FeeDueSoon;
use App\Domain\Education\Events\FeeOverdue;
use App\Domain\Food\Events\ReservationCancelled;
use App\Domain\Food\Events\ReservationConfirmed;
use App\Domain\Food\Events\ReservationCreated;
use App\Domain\Lead\Events\LeadAssigned;
use App\Domain\Lead\Events\LeadConverted;
use App\Domain\Lead\Events\LeadCreated;
use App\Domain\Lead\Events\LeadStatusChanged;
use App\Domain\Lead\Events\LeadUpdated;
use App\Domain\Messaging\Events\ConversationMessageReceived;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Website\Events\WebsiteEnquiryReceived;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Maps domain events to automation triggers (config/automation.php `triggers`). Runs synchronously
 * after the triggering transaction commits (the events are ShouldDispatchAfterCommit); the work
 * itself happens on the queue. A failure here is reported but never breaks the user's request.
 *
 * Event keys: events that can only happen once per record use the record id, so a duplicate
 * delivery starts no second run; repeatable events get a unique key per occurrence.
 */
class StartAutomations
{
    /** @var list<class-string> */
    public const EVENTS = [
        LeadCreated::class,
        LeadUpdated::class,
        LeadStatusChanged::class,
        LeadAssigned::class,
        LeadConverted::class,
        CustomerCreated::class,
        AppointmentCreated::class,
        AppointmentConfirmed::class,
        AppointmentRescheduled::class,
        AppointmentCompleted::class,
        AppointmentCancelled::class,
        AppointmentNoShow::class,
        WebsiteEnquiryReceived::class,
        OrderCreated::class,
        OrderConfirmed::class,
        OrderReady::class,
        OrderCompleted::class,
        OrderCancelled::class,
        OrderPaid::class,
        ConversationMessageReceived::class,
        EnrolmentCreated::class,
        FeeDueSoon::class,
        FeeOverdue::class,
        DemoScheduled::class,
        ReservationCreated::class,
        ReservationConfirmed::class,
        ReservationCancelled::class,
    ];

    public function __construct(private readonly AutomationResolver $resolver) {}

    public function handle(object $event): void
    {
        $trigger = $this->map($event);

        if ($trigger === null) {
            return;
        }

        try {
            $this->resolver->start(...$trigger);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return ?array{0: string, 1: Model, 2: ?string, 3: array<string, mixed>} */
    private function map(object $event): ?array
    {
        return match (true) {
            $event instanceof LeadCreated => ['lead.created', $event->lead, "lead:{$event->lead->id}", []],
            $event instanceof LeadUpdated => ['lead.updated', $event->lead, null, ['changed' => $event->changed]],
            $event instanceof LeadStatusChanged => ['lead.status_changed', $event->lead, null, ['from' => $event->from->code, 'to' => $event->to->code]],
            $event instanceof LeadAssigned => $event->assignee
                ? ['lead.assigned', $event->lead, null, ['assignee_id' => $event->assignee->id, 'automatic' => $event->automatic]]
                : null,
            $event instanceof LeadConverted => ['lead.converted', $event->lead, "lead:{$event->lead->id}:converted:".$event->lead->converted_at?->getTimestamp(), ['customer_id' => $event->customer->id, 'customer_created' => $event->customerCreated]],
            $event instanceof CustomerCreated => ['customer.created', $event->customer, "customer:{$event->customer->id}", []],
            $event instanceof AppointmentCreated => ['appointment.created', $event->appointment, "appointment:{$event->appointment->id}", []],
            $event instanceof AppointmentConfirmed => ['appointment.confirmed', $event->appointment, "appointment:{$event->appointment->id}", []],
            $event instanceof AppointmentRescheduled => [
                'appointment.rescheduled',
                $event->appointment,
                "appointment:{$event->appointment->id}:".$event->appointment->starts_at->getTimestamp(),
                ['previous_starts_at' => $event->previousStartsAt->toIso8601String()],
            ],
            $event instanceof AppointmentCompleted => ['appointment.completed', $event->appointment, "appointment:{$event->appointment->id}", []],
            $event instanceof AppointmentCancelled => ['appointment.cancelled', $event->appointment, "appointment:{$event->appointment->id}", []],
            $event instanceof AppointmentNoShow => ['appointment.no_show', $event->appointment, "appointment:{$event->appointment->id}", []],
            $event instanceof WebsiteEnquiryReceived => ['website.enquiry', $event->lead, null, ['new_lead' => $event->newLead]],
            $event instanceof OrderCreated => ['order.created', $event->order, "order:{$event->order->id}", []],
            $event instanceof OrderConfirmed => ['order.confirmed', $event->order, "order:{$event->order->id}", []],
            $event instanceof OrderReady => ['order.ready', $event->order, "order:{$event->order->id}", []],
            $event instanceof OrderCompleted => ['order.completed', $event->order, "order:{$event->order->id}", []],
            $event instanceof OrderCancelled => ['order.cancelled', $event->order, "order:{$event->order->id}", []],
            // A removed payment can make an order unpaid again, so "paid" can happen more than once.
            $event instanceof OrderPaid => ['order.paid', $event->order, "order:{$event->order->id}:paid:".$event->order->payments()->max('id'), []],
            // Opt-out and opt-in keywords are handled by messaging compliance, not by automations.
            $event instanceof ConversationMessageReceived => MessagingCompliance::keyword($event->message->body) === null
                ? ['message.received', $event->conversation, "message:{$event->message->id}", ['message_id' => $event->message->id, 'type' => $event->message->type]]
                : null,
            $event instanceof EnrolmentCreated => ['enrolment.created', $event->enrolment, "enrolment:{$event->enrolment->id}", []],
            $event instanceof FeeDueSoon => ['fee.due_soon', $event->instalment, "fee:{$event->instalment->id}:due_soon", []],
            $event instanceof FeeOverdue => ['fee.overdue', $event->instalment, "fee:{$event->instalment->id}:overdue", []],
            $event instanceof DemoScheduled => ['demo.scheduled', $event->demo, "demo:{$event->demo->id}", []],
            $event instanceof ReservationCreated => ['reservation.created', $event->reservation, "reservation:{$event->reservation->id}", []],
            $event instanceof ReservationConfirmed => ['reservation.confirmed', $event->reservation, "reservation:{$event->reservation->id}", []],
            $event instanceof ReservationCancelled => ['reservation.cancelled', $event->reservation, "reservation:{$event->reservation->id}", []],
            default => null,
        };
    }
}
