<?php

namespace App\Console\Commands;

use App\Domain\Customer\Services\BirthdayGreetings;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs hourly so each tenant's "today" follows its timezone. Fires `customer.birthday` once per
 * customer per year; loyalty can also grant a free reward stamp pack.
 */
class SendBirthdayGreetings extends Command
{
    protected $signature = 'customers:birthday-greetings';

    protected $description = 'Fire birthday automation triggers (and optional loyalty rewards)';

    public function handle(TenantContext $context, BirthdayGreetings $greetings): int
    {
        $totals = ['greeted' => 0, 'rewards' => 0];

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($context, $greetings, &$totals) {
            if (! $tenant->isActive()) {
                return;
            }

            try {
                $result = $context->run($tenant, fn () => $greetings->run());
                $totals['greeted'] += $result['greeted'];
                $totals['rewards'] += $result['rewards'];
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $this->components->info("Birthdays: {$totals['greeted']} greeted, {$totals['rewards']} loyalty rewards.");

        return self::SUCCESS;
    }
}
