<?php

namespace App\Domain\Education\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeePayment;
use App\Domain\Education\Support\FeeLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Removes a fee payment recorded by mistake (not a refund); kept on the timeline and in the audit log. */
class RemoveFeePayment
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Enrolment $enrolment, FeePayment $payment, ?User $actor = null): void
    {
        abort_unless($payment->enrolment_id === $enrolment->id, 404);

        DB::transaction(function () use ($enrolment, $payment, $actor) {
            Enrolment::query()->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();

            $payment->delete();
            FeeLedger::reallocate($enrolment);
            $enrolment->loadMissing(['batch.course', 'customer']);

            $metadata = ['amount' => (string) $payment->amount, 'method' => $payment->method];

            $this->recordActivity->handle('fee_payment_removed', customer: $enrolment->customer, actor: $actor, metadata: [...AdmitStudent::summary($enrolment), ...$metadata]);
            $this->audit->log('enrolment.fee_payment_removed', $enrolment, $metadata);
        });
    }
}
