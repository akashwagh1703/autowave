<?php

namespace App\Domain\Education\Actions;

use App\Domain\Education\Enums\AttendanceStatus;
use App\Domain\Education\Models\AttendanceRecord;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\ClassSession;
use App\Models\User;
use App\Support\TenantTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records (or corrects) attendance for a batch's class on a local date. The class session is created
 * on first save. Only the batch's active students can be marked; the date cannot be in the future.
 */
class TakeAttendance
{
    /**
     * @param  array<int|string, string>  $marks  enrolment id => present|absent|late|excused
     */
    public function handle(Batch $batch, string $date, array $marks, ?string $topic = null, ?User $actor = null): ClassSession
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > TenantTime::now()->toDateString()) {
            throw ValidationException::withMessages(['date' => __('Choose today or an earlier date.')]);
        }

        $active = $batch->activeEnrolments()->pluck('id')->all();
        $records = [];

        foreach ($marks as $enrolmentId => $value) {
            $status = AttendanceStatus::tryFrom((string) $value);

            if (! $status) {
                throw ValidationException::withMessages(["marks.{$enrolmentId}" => __('Choose present, absent, late or excused.')]);
            }

            if (! in_array((int) $enrolmentId, $active, true)) {
                throw ValidationException::withMessages(['marks' => __('Only the batch’s active students can be marked.')]);
            }

            $records[(int) $enrolmentId] = $status;
        }

        return DB::transaction(function () use ($batch, $date, $records, $topic, $actor) {
            $session = ClassSession::query()->firstOrCreate(
                ['batch_id' => $batch->id, 'held_on' => $date],
                ['created_by_user_id' => $actor?->id],
            );

            $session->update(['topic' => filled($topic) ? trim($topic) : null]);

            foreach ($records as $enrolmentId => $status) {
                AttendanceRecord::query()->updateOrCreate(
                    ['class_session_id' => $session->id, 'enrolment_id' => $enrolmentId],
                    ['status' => $status],
                );
            }

            return $session;
        });
    }
}
