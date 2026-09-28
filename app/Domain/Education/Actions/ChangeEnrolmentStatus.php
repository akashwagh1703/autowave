<?php

namespace App\Domain\Education\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Education\Enums\EnrolmentStatus;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Enrolment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Completes, drops or re-activates an enrolment. Re-activating needs a free seat and no other active
 * enrolment of the student in the batch. Dropped and completed students get no fee reminders.
 */
class ChangeEnrolmentStatus
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    public function handle(Enrolment $enrolment, EnrolmentStatus $status, ?User $actor = null, ?string $reason = null): Enrolment
    {
        if ($enrolment->status === $status) {
            return $enrolment;
        }

        return DB::transaction(function () use ($enrolment, $status, $actor, $reason) {
            if ($status === EnrolmentStatus::Active) {
                $batch = Batch::query()->whereKey($enrolment->batch_id)->lockForUpdate()->first();

                if (! $batch) {
                    throw ValidationException::withMessages(['status' => __('The batch was deleted.')]);
                }

                if ($batch->capacity !== null && $batch->activeEnrolments()->count() >= $batch->capacity) {
                    throw ValidationException::withMessages(['status' => __('This batch is full.')]);
                }

                if (Enrolment::query()->active()->where('batch_id', $batch->id)->where('customer_id', $enrolment->customer_id)->exists()) {
                    throw ValidationException::withMessages(['status' => __('The student is already active in this batch.')]);
                }
            }

            $enrolment->forceFill([
                'status' => $status,
                'completed_at' => $status === EnrolmentStatus::Completed ? now() : null,
                'dropped_at' => $status === EnrolmentStatus::Dropped ? now() : null,
            ])->save();

            $enrolment->loadMissing(['batch.course', 'customer']);

            $this->recordActivity->handle("enrolment_{$status->value}", customer: $enrolment->customer, actor: $actor, body: $reason, metadata: AdmitStudent::summary($enrolment));

            return $enrolment;
        });
    }
}
