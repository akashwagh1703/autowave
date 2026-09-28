<?php

namespace App\Domain\Education\Models;

use App\Domain\Education\Enums\EnrolmentStatus;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Domain\Tenant\Models\TenantUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group of students taking a course together on a weekly schedule (ISO weekdays, local times).
 * Capacity limits active enrolments; the fee overrides the course fee.
 */
#[Fillable([
    'tenant_id', 'course_id', 'name', 'starts_on', 'ends_on', 'weekdays', 'start_time', 'end_time',
    'capacity', 'teacher_tenant_user_id', 'room', 'fee', 'is_active',
])]
class Batch extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'weekdays' => 'array',
            'fee' => 'decimal:2',
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'teacher_tenant_user_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    public function activeEnrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class)->where('status', EnrolmentStatus::Active);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    /** The fee for a new admission: the batch's own fee, else the course fee, else nothing. */
    public function effectiveFee(): string
    {
        return (string) ($this->fee ?? $this->course?->fee ?? '0.00');
    }

    public function startTime(): ?string
    {
        return $this->start_time ? substr((string) $this->start_time, 0, 5) : null;
    }

    public function endTime(): ?string
    {
        return $this->end_time ? substr((string) $this->end_time, 0, 5) : null;
    }

    /** Whether the batch meets on a local date (weekday in the schedule, within its start and end dates). */
    public function meetsOn(\DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return in_array((int) $date->format('N'), $this->weekdays ?? [], true)
            && (! $this->starts_on || $day >= $this->starts_on->toDateString())
            && (! $this->ends_on || $day <= $this->ends_on->toDateString());
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
