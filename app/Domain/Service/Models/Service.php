<?php

namespace App\Domain\Service\Models;

use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Media\Models\Media;
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
 * Packages are the same row with is_package=true and optional package_items (included services/products).
 * Nothing here is vertical-specific; the business type only supplies default categories.
 */
#[Fillable(['tenant_id', 'service_category_id', 'name', 'description', 'duration_minutes', 'price', 'is_active', 'is_package', 'sort_order', 'created_by_user_id'])]
#[UseFactory(ServiceFactory::class)]
class Service extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $attributes = [
        'is_active' => true,
        'is_package' => false,
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_package' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /** Optional photo (SetRecordImage, collection `service`), shown as a card in the WhatsApp assistant. */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(BookingResource::class, 'booking_resource_service')->withPivot('tenant_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** Lines that make up this package (empty for ordinary services). */
    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class, 'package_service_id')->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopePackages(Builder $query): void
    {
        $query->where('is_package', true);
    }

    public function scopeStandalone(Builder $query): void
    {
        $query->where('is_package', false);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }
}
