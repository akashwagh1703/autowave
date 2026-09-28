<?php

namespace App\Domain\Automation\Support;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The records a run's steps can read and change: the subject plus what it links to (an
 * appointment's or order's customer, a lead's customer). Loaded fresh for every step.
 */
final class SubjectContext
{
    private string|null|false $latestInbound = false;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $subjectType,
        public readonly Model $subject,
        public readonly ?Lead $lead = null,
        public readonly ?Customer $customer = null,
        public readonly ?Appointment $appointment = null,
        public readonly ?Order $order = null,
        public readonly ?Conversation $conversation = null,
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
            default => null,
        };
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
}
