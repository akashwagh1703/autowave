<?php

namespace App\Domain\Automation\Support;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Customer\Models\Customer;
use App\Domain\Education\Models\DemoClass;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeeInstalment;
use App\Domain\Food\Models\Reservation;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The records a run's steps can read and change: the subject plus what it links to (an
 * appointment's or order's customer, a lead's customer). Loaded fresh for every step.
 * Vertical records (enrolment, fee, demo_class, reservation) are in `records`, keyed by entity.
 */
final class SubjectContext
{
    private string|null|false $latestInbound = false;

    /**
     * @param  array<string, Model>  $records
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $subjectType,
        public readonly Model $subject,
        public readonly ?Lead $lead = null,
        public readonly ?Customer $customer = null,
        public readonly ?Appointment $appointment = null,
        public readonly ?Order $order = null,
        public readonly ?Conversation $conversation = null,
        public readonly array $records = [],
    ) {}

    public static function for(Tenant $tenant, Model $subject): self
    {
        $type = AutomationRun::subjectTypeOf($subject);

        return match ($type) {
            'lead' => self::forLead($tenant, $subject),
            'customer' => new self($tenant, $type, $subject, customer: $subject),
            'appointment' => self::forAppointment($tenant, $subject),
            'order' => self::forOrder($tenant, $subject),
            'conversation' => self::forConversation($tenant, $subject),
            'enrolment' => self::forEnrolment($tenant, $subject),
            'fee' => self::forFee($tenant, $subject),
            'demo_class' => self::forDemo($tenant, $subject),
            'reservation' => self::forReservation($tenant, $subject),
        };
    }

    public function entity(string $name): ?Model
    {
        return match ($name) {
            'lead' => $this->lead,
            'customer' => $this->customer,
            'appointment' => $this->appointment,
            'order' => $this->order,
            'conversation' => $this->conversation,
            default => $this->records[$name] ?? null,
        };
    }

    public function enrolment(): ?Enrolment
    {
        return $this->records['enrolment'] ?? null;
    }

    public function fee(): ?FeeInstalment
    {
        return $this->records['fee'] ?? null;
    }

    public function demo(): ?DemoClass
    {
        return $this->records['demo_class'] ?? null;
    }

    public function reservation(): ?Reservation
    {
        return $this->records['reservation'] ?? null;
    }

    /** The text of the contact's latest message in the conversation (the one that triggered the run, normally). */
    public function latestInboundText(): ?string
    {
        if ($this->latestInbound === false) {
            $this->latestInbound = $this->conversation?->messages()
                ->where('direction', ConversationMessage::INBOUND)
                ->orderByDesc('sent_at')->orderByDesc('id')
                ->value('body');
        }

        return $this->latestInbound;
    }

    /** The lead's contact details win over its linked customer's. */
    public function phone(): ?string
    {
        return $this->lead?->phone_normalized ?: $this->lead?->phone ?: $this->customer?->phone_normalized ?: $this->customer?->phone ?: null;
    }

    public function email(): ?string
    {
        return $this->lead?->email ?: $this->customer?->email ?: null;
    }

    public function contactName(): ?string
    {
        return $this->lead?->name ?? $this->customer?->name;
    }

    private static function forLead(Tenant $tenant, Lead $lead): self
    {
        $lead->loadMissing(['stage', 'source', 'customer', 'assignee.user']);
        $customer = $lead->customer && ! $lead->customer->trashed() ? $lead->customer : null;

        return new self($tenant, 'lead', $lead, lead: $lead, customer: $customer);
    }

    private static function forAppointment(Tenant $tenant, Appointment $appointment): self
    {
        $appointment->loadMissing(['customer', 'service', 'resource']);
        $customer = $appointment->customer && ! $appointment->customer->trashed() ? $appointment->customer : null;

        return new self($tenant, 'appointment', $appointment, customer: $customer, appointment: $appointment);
    }

    private static function forOrder(Tenant $tenant, Order $order): self
    {
        $order->loadMissing(['customer', 'items']);
        $customer = $order->customer && ! $order->customer->trashed() ? $order->customer : null;

        return new self($tenant, 'order', $order, customer: $customer, order: $order);
    }

    /** A conversation's linked lead (open or not) and customer; deleted ones are left out. */
    private static function forConversation(Tenant $tenant, Conversation $conversation): self
    {
        $conversation->loadMissing(['lead.stage', 'lead.source', 'lead.customer', 'customer']);
        $lead = $conversation->lead && ! $conversation->lead->trashed() ? $conversation->lead : null;
        $customer = $conversation->customer && ! $conversation->customer->trashed() ? $conversation->customer : ($lead?->customer && ! $lead->customer->trashed() ? $lead->customer : null);

        return new self($tenant, 'conversation', $conversation, lead: $lead, customer: $customer, conversation: $conversation);
    }

    private static function forEnrolment(Tenant $tenant, Enrolment $enrolment): self
    {
        $enrolment->loadMissing(['customer', 'batch.course']);
        $customer = $enrolment->customer && ! $enrolment->customer->trashed() ? $enrolment->customer : null;

        return new self($tenant, 'enrolment', $enrolment, customer: $customer, records: ['enrolment' => $enrolment]);
    }

    private static function forFee(Tenant $tenant, FeeInstalment $fee): self
    {
        $fee->loadMissing(['enrolment.customer', 'enrolment.batch.course']);
        $enrolment = $fee->enrolment;
        $customer = $enrolment?->customer && ! $enrolment->customer->trashed() ? $enrolment->customer : null;

        return new self($tenant, 'fee', $fee, customer: $customer, records: array_filter(['fee' => $fee, 'enrolment' => $enrolment]));
    }

    private static function forDemo(Tenant $tenant, DemoClass $demo): self
    {
        $demo->loadMissing(['lead.stage', 'lead.source', 'lead.customer', 'course', 'batch']);
        $lead = $demo->lead && ! $demo->lead->trashed() ? $demo->lead : null;
        $customer = $lead?->customer && ! $lead->customer->trashed() ? $lead->customer : null;

        return new self($tenant, 'demo_class', $demo, lead: $lead, customer: $customer, records: ['demo_class' => $demo]);
    }

    private static function forReservation(Tenant $tenant, Reservation $reservation): self
    {
        $reservation->loadMissing(['customer', 'table']);
        $customer = $reservation->customer && ! $reservation->customer->trashed() ? $reservation->customer : null;

        return new self($tenant, 'reservation', $reservation, customer: $customer, records: ['reservation' => $reservation]);
    }
}
