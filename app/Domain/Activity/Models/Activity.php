<?php

namespace App\Domain\Activity\Models;

use App\Domain\Booking\Models\Appointment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry on a lead's and/or customer's timeline. When a lead is linked to a customer its
 * activities carry customer_id too, so the customer timeline includes the lead history.
 * Appointment entries carry both appointment_id and customer_id.
 */
#[Fillable(['tenant_id', 'lead_id', 'customer_id', 'appointment_id', 'user_id', 'type', 'body', 'metadata', 'occurred_at'])]
class Activity extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
