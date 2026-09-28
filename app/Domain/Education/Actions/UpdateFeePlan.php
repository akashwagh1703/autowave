<?php

namespace App\Domain\Education\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeeInstalment;
use App\Domain\Education\Support\FeeLedger;
use App\Domain\Education\Support\FeePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces an enrolment's fee, discount and instalments. The plan must add up to the fee after
 * discount, which cannot drop below what has been paid. Payments are re-allocated; an instalment
 * kept with the same due date and amount keeps its reminder history, so nobody is reminded twice.
 */
class UpdateFeePlan
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{fee_total: numeric-string|float, discount?: numeric-string|float|null, instalments: list<array{due_on: string, amount: numeric-string|float}>}  $data */
    public function handle(Enrolment $enrolment, array $data): Enrolment
    {
        $feeTotal = number_format(max(0, (float) ($data['fee_total'] ?? 0)), 2, '.', '');
        $discount = number_format(max(0, (float) ($data['discount'] ?? 0)), 2, '.', '');

        if (bccomp($discount, $feeTotal, 2) > 0) {
            throw ValidationException::withMessages(['discount' => __('The discount cannot be more than the fee.')]);
        }

        $net = bcsub($feeTotal, $discount, 2);
        $plan = FeePlan::normalize($data['instalments'] ?? [], $net);

        return DB::transaction(function () use ($enrolment, $feeTotal, $discount, $net, $plan) {
            $locked = Enrolment::query()->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();

            if (bccomp($net, (string) $locked->amount_paid, 2) < 0) {
                throw ValidationException::withMessages(['fee_total' => __('The fee after discount cannot be less than the amount already paid (:paid).', ['paid' => $locked->amount_paid])]);
            }

            $previous = FeeInstalment::query()->where('enrolment_id', $locked->id)->get()
                ->keyBy(fn (FeeInstalment $instalment) => $instalment->due_on->toDateString().'|'.$instalment->amount);

            FeeInstalment::query()->where('enrolment_id', $locked->id)->delete();
            $locked->forceFill(['fee_total' => $feeTotal, 'discount' => $discount, 'amount_paid' => 0])->save();

            foreach ($plan as $index => $row) {
                $kept = $previous->get($row['due_on'].'|'.$row['amount']);

                $locked->instalments()->create([
                    'sequence' => $index + 1,
                    'due_on' => $row['due_on'],
                    'amount' => $row['amount'],
                    'reminded_at' => $kept?->reminded_at,
                    'overdue_notified_at' => $kept?->overdue_notified_at,
                ]);
            }

            FeeLedger::reallocate($locked);
            $this->audit->log('enrolment.fee_plan_updated', $locked, ['fee_total' => $feeTotal, 'discount' => $discount, 'instalments' => count($plan)]);

            return $locked;
        });
    }
}
