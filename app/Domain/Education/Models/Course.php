<?php

namespace App\Domain\Education\Models;

use App\Domain\Media\Models\Media;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Something a coaching centre teaches (ADR-020). Taught in batches; the fee is the default for new admissions. */
#[Fillable(['tenant_id', 'name', 'description', 'fee', 'duration_label', 'is_active', 'sort_order'])]
class Course extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /** Optional photo (SetRecordImage, collection `course`), shown as a card in the WhatsApp assistant. */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
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
