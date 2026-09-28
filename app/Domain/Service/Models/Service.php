<?php

namespace App\Domain\Service\Models;

use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Database\Factories\ServiceFactory;
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
 * Something the business sells by time: a name, duration and price (master prompt §27).
 * Nothing here is vertical-specific; the business type only supplies default categories.
 */
#[Fillable(['tenant_id', 'service_category_id', 'name', 'description', 'duration_minutes', 'price', 'is_active', 'sort_order', 'created_by_user_id'])]
#[UseFactory(ServiceFactory::class)]
class Service extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(BookingResource::class, 'booking_resource_service')->withPivot('tenant_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
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
