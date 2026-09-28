<?php

namespace App\Domain\Booking\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weekly availability window: ISO weekday (1 = Monday) and local wall-clock times in the
 * tenant's timezone. A day may have several windows (split shifts).
 */
#[Fillable(['tenant_id', 'booking_resource_id', 'weekday', 'starts_at', 'ends_at'])]
class WorkingHour extends Model
{
    use BelongsToTenant;

    protected $table = 'resource_working_hours';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'booking_resource_id');
    }

    /** "HH:MM" without seconds. */
    public function startTime(): string
    {
        return substr((string) $this->starts_at, 0, 5);
    }

    public function endTime(): string
    {
        return substr((string) $this->ends_at, 0, 5);
    }
}
