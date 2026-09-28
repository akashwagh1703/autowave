<?php

namespace App\Domain\Education\Support;

use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeeInstalment;
use App\Domain\Education\Models\FeePayment;

/**
 * Keeps an enrolment's paid amounts consistent with its payment rows: the total received is
 * allocated to instalments oldest first. Call with the enrolment row locked.
 */
final class FeeLedger
{
    public static function reallocate(Enrolment $enrolment): void
    {
        $remaining = number_format((float) FeePayment::query()->where('enrolment_id', $enrolment->id)->sum('amount'), 2, '.', '');
        $paid = $remaining;

        foreach (FeeInstalment::query()->where('enrolment_id', $enrolment->id)->orderBy('due_on')->orderBy('sequence')->get() as $instalment) {
            $allocated = bccomp($remaining, (string) $instalment->amount, 2) >= 0 ? (string) $instalment->amount : $remaining;
            $remaining = bcsub($remaining, $allocated, 2);

            if (bccomp((string) $instalment->amount_paid, $allocated, 2) !== 0) {
                $instalment->forceFill(['amount_paid' => $allocated])->save();
            }
        }

        $enrolment->forceFill(['amount_paid' => $paid])->save();
    }
}
