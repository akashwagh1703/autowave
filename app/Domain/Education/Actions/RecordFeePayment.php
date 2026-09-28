<?php

namespace App\Domain\Education\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Services\OrderPricing;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeePayment;
use App\Domain\Education\Support\FeeLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records fee money received for an enrolment (manual, no gateway). A payment cannot be more than
 * the balance; it is allocated to instalments oldest first under a lock on the enrolment.
 */
class RecordFeePayment
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{amount: numeric-string|float|int, method: string, reference?: ?string, paid_at?: ?\DateTimeInterface}  $data */
    public function handle(Enrolment $enrolment, array $data, ?User $actor = null): FeePayment
    {
        $amount = OrderPricing::money($data['amount'] ?? 0);

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount greater than zero.')]);
        }

        if (! array_key_exists($data['method'] ?? '', config('commerce.payment_methods'))) {
            throw ValidationException::withMessages(['method' => __('Choose how the fee was paid.')]);
        }

        return DB::transaction(function () use ($enrolment, $data, $actor, $amount) {
            $locked = Enrolment::query()->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();
            $balance = $locked->balance();

            if (bccomp($amount, $balance, 2) > 0) {
                throw ValidationException::withMessages(['amount' => bccomp($balance, '0', 2) <= 0
                    ? __('The fee is already fully paid.')
                    : __('The amount cannot be more than the balance due (:balance).', ['balance' => $balance])]);
            }

            $payment = $locked->payments()->create([
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                'paid_at' => $data['paid_at'] ?? now(),
                'recorded_by_user_id' => $actor?->id,
            ]);

            FeeLedger::reallocate($enrolment);
            $enrolment->loadMissing(['batch.course', 'customer']);

            $this->recordActivity->handle('fee_paid', customer: $enrolment->customer, actor: $actor, metadata: [
                ...AdmitStudent::summary($enrolment),
                'amount' => $amount,
                'method' => $payment->method,
                'method_label' => $payment->methodLabel(),
            ]);

            $this->audit->log('enrolment.fee_paid', $enrolment, ['amount' => $amount, 'method' => $payment->method]);

            return $payment;
        });
    }
}
