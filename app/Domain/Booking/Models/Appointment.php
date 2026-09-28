<?php

namespace App\Domain\Booking\Models;

use App\Domain\Activity\Models\Activity;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's booking of a resource for [starts_at, ends_at) (UTC). Never deleted: cancellation
 * is a status, so the customer's history stays complete.
 */
#[Fillable([
    'tenant_id', 'customer_id', 'booking_resource_id', 'service_id', 'starts_at', 'ends_at', 'status',
    'price', 'notes', 'source', 'confirmed_at', 'completed_at', 'cancelled_at', 'cancellation_reason',
    'no_show_at', 'created_by_user_id',
])]
class Appointment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'price' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'booking_resource_id')->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** Appointments that occupy their resource's time. */
    public function scopeBlocking(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::BLOCKING);
    }

    /** Appointments overlapping [start, end) — both UTC. */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): void
    {
        $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }
}
