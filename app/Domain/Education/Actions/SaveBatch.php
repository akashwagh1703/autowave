<?php

namespace App\Domain\Education\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\TenantUser;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a batch. The course and teacher are checked against the current tenant.
 * Lowering the capacity below the number of active students is refused.
 */
class SaveBatch
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{course_id: int, name: string, starts_on?: ?string, ends_on?: ?string, weekdays?: ?list<int>, start_time?: ?string, end_time?: ?string, capacity?: ?int, teacher_tenant_user_id?: ?int, room?: ?string, fee?: numeric-string|float|null, is_active?: bool}  $data
     */
    public function handle(array $data, ?Batch $batch = null): Batch
    {
        $course = Course::query()->find($data['course_id'] ?? null)
            ?? throw ValidationException::withMessages(['course_id' => __('Choose a valid course.')]);

        $teacherId = ! empty($data['teacher_tenant_user_id']) ? (int) $data['teacher_tenant_user_id'] : null;

        if ($teacherId && ! TenantUser::query()->where('tenant_id', $course->tenant_id)->where('status', MembershipStatus::Active)->whereKey($teacherId)->exists()) {
            throw ValidationException::withMessages(['teacher_tenant_user_id' => __('Choose an active team member.')]);
        }

        $weekdays = array_values(array_unique(array_map('intval', $data['weekdays'] ?? [])));
        sort($weekdays);

        if ($weekdays !== [] && (min($weekdays) < 1 || max($weekdays) > 7)) {
            throw ValidationException::withMessages(['weekdays' => __('Choose valid days.')]);
        }

        $start = filled($data['start_time'] ?? null) ? $data['start_time'] : null;
        $end = filled($data['end_time'] ?? null) ? $data['end_time'] : null;

        if ($start && $end && $end <= $start) {
            throw ValidationException::withMessages(['end_time' => __('The class must end after it starts.')]);
        }

        $startsOn = filled($data['starts_on'] ?? null) ? $data['starts_on'] : null;
        $endsOn = filled($data['ends_on'] ?? null) ? $data['ends_on'] : null;

        if ($startsOn && $endsOn && $endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => __('The end date must be on or after the start date.')]);
        }

        $capacity = ! empty($data['capacity']) ? (int) $data['capacity'] : null;

        if ($batch && $capacity !== null && $batch->activeEnrolments()->count() > $capacity) {
            throw ValidationException::withMessages(['capacity' => __('The batch already has more active students than this.')]);
        }

        if (! $batch && Batch::query()->count() >= (int) config('education.max_batches')) {
            throw ValidationException::withMessages(['name' => __('You can have at most :max batches.', ['max' => config('education.max_batches')])]);
        }

        $attributes = [
            'course_id' => $course->id,
            'name' => trim($data['name']),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'weekdays' => $weekdays ?: null,
            'start_time' => $start,
            'end_time' => $end,
            'capacity' => $capacity,
            'teacher_tenant_user_id' => $teacherId,
            'room' => filled($data['room'] ?? null) ? trim($data['room']) : null,
            'fee' => isset($data['fee']) && $data['fee'] !== '' ? number_format((float) $data['fee'], 2, '.', '') : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if ($batch) {
            $batch->update($attributes);
        } else {
            $batch = Batch::query()->create($attributes);
        }

        $this->audit->log($batch->wasRecentlyCreated ? 'batch.created' : 'batch.updated', $batch, ['name' => $batch->name, 'course' => $course->name]);

        return $batch->setRelation('course', $course);
    }
}
