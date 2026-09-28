<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;
use App\Domain\Automation\Support\TemplateRenderer;
use App\Domain\Messaging\Services\MessagingService;

/** Emails the lead (or its customer) or the appointment's customer. */
class SendEmailStep implements StepAction
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:'.config('automation.limits.subject')],
            'message' => ['required', 'string', 'max:'.config('automation.limits.message')],
        ];
    }

    public function normalize(array $config): array
    {
        return ['subject' => trim($config['subject']), 'message' => trim($config['message'])];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $subject = $context->subject;
        $email = $subject->email();

        if (! $email) {
            return ActionResult::skipped('There is no email address to send to.');
        }

        $message = $this->messaging->queue([
            'channel' => 'email',
            'recipient' => $email,
            'recipient_name' => $subject->contactName(),
            'subject' => $this->renderer->render($config['subject'], $subject),
            'body' => $this->renderer->render($config['message'], $subject),
            'idempotency_key' => $context->idempotencyKey,
            'lead_id' => $subject->lead?->id,
            'customer_id' => $subject->customer?->id,
            'automation_run_id' => $context->run->id,
        ]);

        return ActionResult::completed('Email queued for '.$message->maskedRecipient().'.', ['message_id' => $message->id]);
    }
}
