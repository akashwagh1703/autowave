<?php

namespace App\Console\Commands;

use App\Domain\Education\Services\FeeReminders;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs hourly (routes/console.php) so each tenant's "today" follows its own timezone. Fires the
 * `fee.due_soon` and `fee.overdue` automation triggers; what happens next (a WhatsApp reminder, a
 * task) is up to the tenant's automations.
 */
class SendFeeReminders extends Command
{
    protected $signature = 'education:fee-reminders';

    protected $description = 'Fire fee due-soon and overdue automation triggers for coaching tenants';

    public function handle(TenantContext $context, FeeReminders $reminders): int
    {
        $totals = ['due_soon' => 0, 'overdue' => 0];

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($context, $reminders, &$totals) {
            if (! $tenant->isActive()) {
                return;
            }

            try {
                $result = $context->run($tenant, fn () => $reminders->run());
                $totals['due_soon'] += $result['due_soon'];
                $totals['overdue'] += $result['overdue'];
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $this->components->info("Fee reminders: {$totals['due_soon']} due soon, {$totals['overdue']} overdue.");

        return self::SUCCESS;
    }
}
