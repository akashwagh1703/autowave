<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;
use App\Domain\Customer\Support\CustomerTags;

/** Adds a tag to the customer (the subject, the appointment's customer or the lead's customer). */
class TagCustomerStep implements StepAction
{
    public function rules(): array
    {
        return ['tag' => ['required', 'string', 'max:'.CustomerTags::MAX_LENGTH]];
    }

    public function normalize(array $config): array
    {
        return ['tag' => CustomerTags::normalize([$config['tag']])[0] ?? ''];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $customer = $context->subject->customer;

        if (! $customer) {
            return ActionResult::skipped('There is no customer to tag.');
        }

        $tags = $customer->tags ?? [];

        if (in_array(mb_strtolower($config['tag']), array_map('mb_strtolower', $tags), true)) {
            return ActionResult::skipped("The customer is already tagged “{$config['tag']}”.");
        }

        $updated = CustomerTags::normalize([...$tags, $config['tag']]);

        if (count($updated) === count($tags)) {
            return ActionResult::skipped('The customer already has the maximum number of tags.');
        }

        $customer->forceFill(['tags' => $updated])->save();

        return ActionResult::completed("Tagged “{$config['tag']}”.");
    }
}
