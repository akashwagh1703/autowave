<?php

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Models\AutomationLog;
use App\Domain\Automation\Models\AutomationRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes a run's execution log. Warnings and errors also go to the application log with tenant,
 * automation and run ids, so failures can be traced without opening the app.
 */
class RunLogger
{
    /**
     * @param  'info'|'warning'|'error'  $level
     * @param  array<string, mixed>  $context  never include secrets or full message text
     */
    public function log(AutomationRun $run, string $event, string $message, string $level = 'info', ?int $step = null, array $context = []): AutomationLog
    {
        $entry = AutomationLog::query()->create([
            'tenant_id' => $run->tenant_id,
            'automation_run_id' => $run->id,
            'step_index' => $step,
            'level' => $level,
            'event' => $event,
            'message' => Str::limit($message, 500, ''),
            'context' => $context ?: null,
        ]);

        if ($level !== 'info') {
            Log::log($level, "automation.{$event}", [
                'tenant_id' => $run->tenant_id,
                'automation_id' => $run->automation_id,
                'automation_run_id' => $run->id,
                'step' => $step,
                'message' => $entry->message,
            ]);
        }

        return $entry;
    }
}
