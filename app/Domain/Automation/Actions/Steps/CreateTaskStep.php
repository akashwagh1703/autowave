<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Activity\Models\Activity;
use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;
use App\Domain\Automation\Support\TemplateRenderer;

/**
 * Adds a follow-up task to the timeline (lead, or the customer / appointment). For an open lead it
 * also brings the lead's next follow-up forward to the task's due time, so it shows up in
 * "Pending follow-ups".
 */
class CreateTaskStep implements StepAction
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'due_in_hours' => ['required', 'integer', 'min:0', 'max:'.config('automation.limits.task_due_hours')],
        ];
    }

    public function normalize(array $config): array
    {
        return ['title' => trim($config['title']), 'due_in_hours' => (int) $config['due_in_hours']];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $subject = $context->subject;

        if (! $subject->lead && ! $subject->customer) {
            return ActionResult::skipped('There is no lead or customer to add the task to.');
        }

        if (Activity::query()->where('type', 'task')->where('metadata->idempotency_key', $context->idempotencyKey)->exists()) {
            return ActionResult::skipped('The task was already added.');
        }

        $title = $this->renderer->render($config['title'], $subject);
        $dueAt = now()->addHours($config['due_in_hours'])->startOfMinute();

        $this->recordActivity->handle(
            'task',
            lead: $subject->lead,
            customer: $subject->lead ? null : $subject->customer,
            body: $title,
            metadata: [
                'via' => 'automation',
                'automation_id' => $context->run->automation_id,
                'automation_name' => $context->automationName,
                'due_at' => $dueAt->toIso8601String(),
                'idempotency_key' => $context->idempotencyKey,
            ],
            appointment: $subject->appointment,
        );

        $lead = $subject->lead;

        if ($lead && $lead->isOpen() && ($lead->next_followup_at === null || $lead->next_followup_at->gt($dueAt))) {
            $lead->forceFill(['next_followup_at' => $dueAt])->save();
        }

        return ActionResult::completed("Task added: {$title}", ['due_at' => $dueAt->toIso8601String()]);
    }
}
