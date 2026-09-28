<?php

namespace App\Domain\Education\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Education\Enums\DemoStatus;
use App\Domain\Education\Models\DemoClass;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Marks a scheduled demo class as attended, no-show or cancelled (recorded on the lead's timeline). */
class ChangeDemoStatus
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    public function handle(DemoClass $demo, DemoStatus $status, ?User $actor = null): DemoClass
    {
        if ($demo->status !== DemoStatus::Scheduled || $status === DemoStatus::Scheduled) {
            throw ValidationException::withMessages(['status' => __('Only scheduled demo classes can be updated.')]);
        }

        if ($status !== DemoStatus::Cancelled && $demo->scheduled_at->isFuture()) {
            throw ValidationException::withMessages(['status' => __('Mark attendance once the demo class has started.')]);
        }

        $demo->update(['status' => $status]);
        $demo->loadMissing(['lead', 'course', 'batch']);

        if ($demo->lead) {
            $this->recordActivity->handle("demo_{$status->value}", lead: $demo->lead, actor: $actor, metadata: ScheduleDemo::summary($demo));
        }

        return $demo;
    }
}
