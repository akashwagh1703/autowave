<?php

namespace App\Domain\Automation\Support;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The records a run's steps can read and change: the subject plus what it links to (an
 * appointment's customer, a lead's customer). Loaded fresh for every step.
 */
final class SubjectContext
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $subjectType,
        public readonly Model $subject,
        public readonly ?Lead $lead = null,
        public readonly ?Customer $customer = null,
        public readonly ?Appointment $appointment = null,
    ) {}

    public static function for(Tenant $tenant, Model $subject): self
    {
        $type = AutomationRun::subjectTypeOf($subject);

        return match ($type) {
            'lead' => self::forLead($tenant, $subject),
            'customer' => new self($tenant, $type, $subject, customer: $subject),
            'appointment' => self::forAppointment($tenant, $subject),
        };
    }

    public function entity(string $name): ?Model
    {
        return match ($name) {
            'lead' => $this->lead,
            'customer' => $this->customer,
            'appointment' => $this->appointment,
            default => null,
        };
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
}
