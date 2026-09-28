<?php

namespace App\Domain\Education\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Education\Models\Batch;
use Illuminate\Validation\ValidationException;

/** Soft-deletes a batch. Refused while it has active students; past enrolments keep pointing at it. */
class DeleteBatch
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Batch $batch): void
    {
        if ($batch->activeEnrolments()->exists()) {
            throw ValidationException::withMessages(['batch' => __('This batch has active students. Complete or drop them first.')]);
        }

        $batch->delete();
        $this->audit->log('batch.deleted', $batch, ['name' => $batch->name]);
    }
}
