<?php

namespace App\Domain\Food\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A table booking for [reserved_at, ends_at) (UTC). The table is optional until the team assigns one;
 * with a table, the database refuses overlapping live reservations (reservations_no_overlap).
 */
#[Fillable([
    'tenant_id', 'customer_id', 'dining_table_id', 'party_size', 'reserved_at', 'ends_at', 'status', 'source',
    'notes', 'cancellation_reason', 'confirmed_at', 'seated_at', 'completed_at', 'cancelled_at', 'created_by_user_id',
])]
class Reservation extends Model
{
    use BelongsToTenant;

    public const OVERLAP_CONSTRAINT = 'reservations_no_overlap';

    protected $attributes = [
        'status' => 'pending',
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'reserved_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => ReservationStatus::class,
            'confirmed_at' => 'datetime',
            'seated_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeHolding(Builder $query): void
    {
        $query->whereIn('status', ReservationStatus::HOLDING);
    }
}
