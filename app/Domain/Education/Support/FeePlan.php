<?php

namespace App\Domain\Education\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Builds and checks fee instalment plans. Amounts are decimal strings; the plan always adds up to
 * the net fee exactly (any rounding remainder goes on the last instalment).
 */
final class FeePlan
{
    /**
     * `count` equal monthly instalments starting on `firstDue`.
     *
     * @return list<array{due_on: string, amount: string}>
     */
    public static function split(string $net, int $count, string $firstDue): array
    {
        if (bccomp($net, '0', 2) <= 0) {
            return [];
        }

        $count = max(1, min($count, (int) config('education.max_instalments')));
        $share = bcdiv($net, (string) $count, 2);
        $start = CarbonImmutable::parse($firstDue);
        $plan = [];

        for ($i = 0; $i < $count; $i++) {
            $amount = $i === $count - 1 ? bcsub($net, bcmul($share, (string) ($count - 1), 2), 2) : $share;
            $plan[] = ['due_on' => $start->addMonthsNoOverflow($i)->toDateString(), 'amount' => $amount];
        }

        return array_values(array_filter($plan, fn (array $row) => bccomp($row['amount'], '0', 2) > 0));
    }

    /**
     * Validates a plan typed by the team: positive amounts, valid dates, adding up to the net fee.
     * Errors are keyed instalments.{index}.{field}.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array{due_on: string, amount: string}>
     */
    public static function normalize(array $rows, string $net): array
    {
        if (count($rows) > (int) config('education.max_instalments')) {
            throw ValidationException::withMessages(['instalments' => __('Use at most :max instalments.', ['max' => config('education.max_instalments')])]);
        }

        $plan = [];
        $sum = '0.00';

        foreach (array_values($rows) as $index => $row) {
            $row = is_array($row) ? $row : [];
            $due = (string) ($row['due_on'] ?? '');
            $amount = $row['amount'] ?? null;

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || ! strtotime($due)) {
                throw ValidationException::withMessages(["instalments.{$index}.due_on" => __('Enter a due date.')]);
            }

            if (! is_numeric($amount) || (float) $amount <= 0) {
                throw ValidationException::withMessages(["instalments.{$index}.amount" => __('Enter an amount greater than zero.')]);
            }

            $amount = number_format((float) $amount, 2, '.', '');
            $sum = bcadd($sum, $amount, 2);
            $plan[] = ['due_on' => $due, 'amount' => $amount];
        }

        if (bccomp($sum, $net, 2) !== 0) {
            throw ValidationException::withMessages(['instalments' => __('The instalments add up to :sum but the fee after discount is :net.', ['sum' => $sum, 'net' => $net])]);
        }

        usort($plan, fn (array $a, array $b) => strcmp($a['due_on'], $b['due_on']));

        return $plan;
    }
}
