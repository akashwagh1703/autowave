<?php

namespace App\Domain\Booking\Models;

use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Domain\Tenant\Models\TenantUser;
use Database\Factories\BookingResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anything that can be booked for a period of time: a stylist, a doctor, a turf, a room (ADR-014).
 * The business type names it (`booking_resource_label`). A resource may be linked to a team member.
 */
#[Fillable(['tenant_id', 'tenant_user_id', 'name', 'description', 'color', 'is_active', 'sort_order'])]
#[UseFactory(BookingResourceFactory::class)]
class BookingResource extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingResourceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'booking_resource_service')->withPivot('tenant_id');
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class)->orderBy('weekday')->orderBy('starts_at');
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function offers(Service $service): bool
    {
        return $this->services()->whereKey($service->getKey())->exists();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }
}
