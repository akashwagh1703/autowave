<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automation waits and queue recovery (ADR-015). Needs `php artisan schedule:work` (dev) or a cron
// entry for `schedule:run` (production) — see docs/08-devops/queue-workers.md.
Schedule::command('automation:dispatch-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('messaging:dispatch-pending')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('messaging:prune-webhooks')->dailyAt('03:15')->withoutOverlapping()->onOneServer();
// Fee due-soon / overdue automation triggers (ADR-020); hourly so each tenant's day follows its timezone.
Schedule::command('education:fee-reminders')->hourly()->withoutOverlapping()->onOneServer();
