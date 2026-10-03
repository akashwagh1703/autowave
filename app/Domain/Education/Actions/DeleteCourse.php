<?php

namespace App\Domain\Education\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Files\Actions\ManageAttachments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Soft-deletes a course and its batches, and deletes its video and brochures. Refused while any batch has active students. */
class DeleteCourse
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ManageAttachments $attachments,
    ) {}

    public function handle(Course $course): void
    {
        if (Enrolment::query()->active()->whereIn('batch_id', $course->batches()->select('id'))->exists()) {
            throw ValidationException::withMessages(['course' => __('This course has active students. Complete or drop them first.')]);
        }

        DB::transaction(function () use ($course) {
            $course->batches()->delete();
            $course->delete();
            $this->audit->log('course.deleted', $course, ['name' => $course->name]);
        });

        $this->attachments->deleteAllFor($course);
    }
}
