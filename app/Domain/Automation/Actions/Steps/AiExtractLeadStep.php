<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\AI\Actions\ExtractLeadDetails;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIUnavailable;
use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;

/**
 * Fills the lead's empty details from what the contact wrote; differing values become suggestions for
 * staff (ADR-019). The same messages are only read once, so a retry costs nothing.
 */
class AiExtractLeadStep implements StepAction
{
    public function __construct(private readonly ExtractLeadDetails $extract) {}

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

        if (! $lead || $lead->trashed()) {
            return ActionResult::skipped('There is no lead to fill in.');
        }

        try {
            $result = $this->extract->handle($lead, null, 'automation');
        } catch (AIUnavailable $exception) {
            return ActionResult::skipped($exception->getMessage());
        } catch (AIProviderException $exception) {
            if ($exception->temporary) {
                throw $exception;
            }

            return ActionResult::skipped('The AI service could not read the messages.');
        }

        if (! $result) {
            return ActionResult::skipped('The lead has not written anything yet.');
        }

        $filled = array_keys($result->output['filled'] ?? []);
        $suggested = array_keys($result->output['suggestions'] ?? []);

        return ActionResult::completed(match (true) {
            $filled !== [] => 'Filled in: '.implode(', ', array_map(fn (string $attribute) => str_replace('_', ' ', $attribute), $filled)).'.',
            $suggested !== [] => 'Suggested changes for staff to review.',
            default => 'Nothing new to fill in.',
        }, ['ai_result_id' => $result->id, 'filled' => $filled, 'suggested' => $suggested]);
    }
}
