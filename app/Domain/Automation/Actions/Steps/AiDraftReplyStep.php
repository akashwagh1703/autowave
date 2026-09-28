<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIUnavailable;
use App\Domain\AI\Services\AIService;
use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;

/**
 * Prepares a reply in the inbox for a person to check and send. Never sends anything (ADR-019).
 */
class AiDraftReplyStep implements StepAction
{
    public function __construct(private readonly AIService $ai) {}

    public function rules(): array
    {
        return ['instructions' => ['nullable', 'string', 'max:'.config('ai.instructions_max')]];
    }

    public function normalize(array $config): array
    {
        return ['instructions' => filled($config['instructions'] ?? null) ? trim($config['instructions']) : null];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $conversation = $context->subject->conversation;

        if (! $conversation) {
            return ActionResult::skipped('There is no conversation to reply to.');
        }

        if ($conversation->isOptedOut()) {
            return ActionResult::skipped('The contact has opted out of messages.');
        }

        if ($conversation->last_message_direction === 'outbound') {
            return ActionResult::skipped('Someone has already replied.');
        }

        try {
            $draft = $this->ai->draftReply($conversation, $context->idempotencyKey, $config['instructions'] ?? null);
        } catch (AIUnavailable $exception) {
            return ActionResult::skipped($exception->getMessage());
        } catch (AIProviderException $exception) {
            if ($exception->temporary) {
                throw $exception;
            }

            return ActionResult::skipped('The AI service could not write a reply.');
        }

        return ActionResult::completed('Reply drafted in the inbox, waiting for someone to send it.', ['ai_result_id' => $draft->id]);
    }
}
