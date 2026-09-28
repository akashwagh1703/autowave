<?php

namespace App\Domain\Booking\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A period (UTC) when a resource cannot be booked: leave, maintenance, a private event. */
#[Fillable(['tenant_id', 'booking_resource_id', 'starts_at', 'ends_at', 'reason', 'created_by_user_id'])]
class TimeOff extends Model
{
    use BelongsToTenant;

    protected $table = 'resource_time_off';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'booking_resource_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
