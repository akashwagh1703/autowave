<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;
use App\Domain\Automation\Support\TemplateRenderer;
use App\Domain\Messaging\Services\MessagingService;

/** Sends a WhatsApp message to the lead (or its customer) or the appointment's customer. */
class SendWhatsAppStep implements StepAction
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function rules(): array
    {
        return ['message' => ['required', 'string', 'max:'.config('automation.limits.message')]];
    }

    public function normalize(array $config): array
    {
        return ['message' => trim($config['message'])];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $subject = $context->subject;
        $phone = $subject->phone();

        if (! $phone) {
            return ActionResult::skipped('There is no phone number to send to.');
        }

        $message = $this->messaging->queue([
            'channel' => 'whatsapp',
            'recipient' => $phone,
            'recipient_name' => $subject->contactName(),
            'body' => $this->renderer->render($config['message'], $subject),
            'idempotency_key' => $context->idempotencyKey,
            'lead_id' => $subject->lead?->id,
            'customer_id' => $subject->customer?->id,
            'automation_run_id' => $context->run->id,
        ]);

        return ActionResult::completed('WhatsApp message queued for '.$message->maskedRecipient().'.', ['message_id' => $message->id]);
    }
}
