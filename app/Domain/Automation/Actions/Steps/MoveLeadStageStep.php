<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;
use App\Domain\Automation\Support\AutomationCatalog;
use App\Domain\Lead\Actions\ChangeLeadStage;
use App\Domain\Lead\Models\LeadStage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Moves the lead to a stage (by code). Moving to a won stage converts the lead, as it does by hand. */
class MoveLeadStageStep implements StepAction
{
    public function __construct(
        private readonly ChangeLeadStage $changeStage,
        private readonly AutomationCatalog $catalog,
    ) {}

    public function rules(): array
    {
        return ['stage' => ['required', 'string', Rule::in($this->catalog->optionValues('lead_stages'))]];
    }

    public function normalize(array $config): array
    {
        return ['stage' => $config['stage']];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $lead = $context->subject->lead;

        if (! $lead) {
            return ActionResult::skipped('There is no lead to move.');
        }

        $stage = LeadStage::query()->active()->where('code', $config['stage'])->first();

        if (! $stage) {
            return ActionResult::skipped('The stage no longer exists or is inactive.');
        }

        if ($lead->lead_stage_id === $stage->id) {
            return ActionResult::skipped("The lead is already in {$stage->name}.");
        }

        try {
            $this->changeStage->handle($lead, $stage);
        } catch (ValidationException $exception) {
            return ActionResult::skipped(collect($exception->errors())->flatten()->first() ?? 'The lead could not be moved.');
        }

        return ActionResult::completed("Moved to {$stage->name}.");
    }
}
