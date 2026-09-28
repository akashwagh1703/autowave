<?php

namespace App\Domain\Education\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Education\Events\DemoScheduled;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Models\DemoClass;
use App\Domain\Lead\Actions\ChangeLeadStage;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadStage;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Schedules a demo (trial) class for an open enquiry. When the tenant has an open lead stage coded
 * `demo_scheduled` (the coaching preset does), the lead moves to it.
 */
class ScheduleDemo
{
    public const STAGE_CODE = 'demo_scheduled';

    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly ChangeLeadStage $changeStage,
    ) {}

    /** @param  array{scheduled_at: DateTimeInterface, course_id?: ?int, batch_id?: ?int, notes?: ?string}  $data  scheduled_at is an absolute instant */
    public function handle(Lead $lead, array $data, ?User $actor = null): DemoClass
    {
        $lead->loadMissing('stage');

        if ($lead->stage->outcome !== StageOutcome::Open) {
            throw ValidationException::withMessages(['scheduled_at' => __('Demo classes are for open enquiries.')]);
        }

        $when = CarbonImmutable::instance($data['scheduled_at'])->utc()->startOfMinute();

        if ($when < CarbonImmutable::now()->subDay() || $when > CarbonImmutable::now()->addDays((int) config('education.max_demo_days_ahead'))) {
            throw ValidationException::withMessages(['scheduled_at' => __('Choose a date within the next :days days.', ['days' => config('education.max_demo_days_ahead')])]);
        }

        $batch = ! empty($data['batch_id']) ? (Batch::query()->find($data['batch_id']) ?? throw ValidationException::withMessages(['batch_id' => __('Choose a valid batch.')])) : null;
        $course = ! empty($data['course_id']) ? (Course::query()->find($data['course_id']) ?? throw ValidationException::withMessages(['course_id' => __('Choose a valid course.')])) : null;
        $course ??= $batch?->course;

        return DB::transaction(function () use ($lead, $data, $actor, $when, $batch, $course) {
            $demo = DemoClass::query()->create([
                'lead_id' => $lead->id,
                'course_id' => $course?->id,
                'batch_id' => $batch?->id,
                'scheduled_at' => $when,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'created_by_user_id' => $actor?->id,
            ]);

            $demo->setRelations(['lead' => $lead, 'course' => $course, 'batch' => $batch]);

            $this->recordActivity->handle('demo_scheduled', lead: $lead, actor: $actor, metadata: self::summary($demo));

            $stage = LeadStage::query()->where('code', self::STAGE_CODE)->where('is_active', true)->where('outcome', StageOutcome::Open)->first();

            if ($stage && $lead->lead_stage_id !== $stage->id) {
                $this->changeStage->handle($lead, $stage, $actor);
            }

            DemoScheduled::dispatch($demo);

            return $demo;
        });
    }

    /** @return array<string, mixed> */
    public static function summary(DemoClass $demo): array
    {
        return [
            'demo_id' => $demo->id,
            'scheduled_at' => $demo->scheduled_at->toIso8601String(),
            'course' => $demo->course?->name,
            'batch' => $demo->batch?->name,
        ];
    }
}
