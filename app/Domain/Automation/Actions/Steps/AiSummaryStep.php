<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Activity\Models\Activity;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIUnavailable;
use App\Domain\AI\Services\AIService;
use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;

/** Adds a note with an AI summary of the lead (or customer) and its recent timeline. */
class AiSummaryStep implements StepAction
{
    public function __construct(
        private readonly AIService $ai,
        private readonly RecordActivity $recordActivity,
    ) {}

    public function rules(): array
    {
        return [];
    }

    public function normalize(array $config): array
    {
        return [];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $lead = $context->subject->lead;
        $customer = $lead ? null : $context->subject->customer;
        $record = $lead ?? $customer;

        if (! $record || $record->trashed()) {
            return ActionResult::skipped('There is no lead or customer to summarise.');
        }

        if (Activity::query()->where('type', 'note')->where('metadata->idempotency_key', $context->idempotencyKey)->exists()) {
            return ActionResult::skipped('The summary was already added.');
        }

        try {
            $summary = $this->ai->summarizeRecord($record);
        } catch (AIUnavailable $exception) {
            return ActionResult::skipped($exception->getMessage());
        } catch (AIProviderException $exception) {
            if ($exception->temporary) {
                throw $exception;
            }

            return ActionResult::skipped('The AI service could not write a summary.');
        }

        $this->recordActivity->handle(
            'note',
            lead: $lead,
            customer: $customer,
            body: __('AI summary').': '.($summary->output['text'] ?? ''),
            metadata: [
                'via' => 'ai',
                'automation_id' => $context->run->automation_id,
                'automation_name' => $context->automationName,
                'idempotency_key' => $context->idempotencyKey,
            ],
        );

        return ActionResult::completed('AI summary added to the timeline.', ['ai_result_id' => $summary->id]);
    }
}
