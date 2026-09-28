<?php

namespace App\Domain\Education\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Education\Models\Course;
use Illuminate\Validation\ValidationException;

/** Creates or updates a course. Names are unique per tenant (case-insensitive). */
class SaveCourse
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{name: string, description?: ?string, fee?: numeric-string|float|null, duration_label?: ?string, is_active?: bool}  $data */
    public function handle(array $data, ?Course $course = null): Course
    {
        $name = trim($data['name']);

        $taken = Course::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($course, fn ($query) => $query->whereKeyNot($course->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('A course with this name already exists.')]);
        }

        if (! $course && Course::query()->count() >= (int) config('education.max_courses')) {
            throw ValidationException::withMessages(['name' => __('You can have at most :max courses.', ['max' => config('education.max_courses')])]);
        }

        $attributes = [
            'name' => $name,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'fee' => isset($data['fee']) && $data['fee'] !== '' ? number_format((float) $data['fee'], 2, '.', '') : null,
            'duration_label' => filled($data['duration_label'] ?? null) ? trim($data['duration_label']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if ($course) {
            $course->update($attributes);
        } else {
            $course = Course::query()->create([...$attributes, 'sort_order' => (int) Course::query()->max('sort_order') + 10]);
        }

        $this->audit->log($course->wasRecentlyCreated ? 'course.created' : 'course.updated', $course, ['name' => $course->name]);

        return $course;
    }
}
