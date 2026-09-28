<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;

/**
 * An automation action (config/automation.php `actions`). Resolved from the container inside the
 * run's tenant context.
 *
 * handle() must be idempotent for the context's idempotency key: a step can run again after a
 * crash or a retry, and must not repeat its effect.
 */
interface StepAction
{
    /**
     * Validation rules for the step's config, keyed relative to the config.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * The config to store, after validation passed.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function normalize(array $config): array;

    /**
     * Throw to retry (temporary problems); return ActionResult::skipped() when retrying cannot help.
     *
     * @param  array<string, mixed>  $config
     */
    public function handle(ActionContext $context, array $config): ActionResult;
}
