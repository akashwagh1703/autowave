<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;
use App\Domain\Automation\Support\TemplateRenderer;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Rules\ApprovedTemplate;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Support\MessagingCompliance;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Sends a WhatsApp message to the lead (or its customer) or the appointment's / order's customer.
 *
 * Config: `{message}` for free text, or `{mode: template, template: {name, language}, params: [...]}`
 * for an approved template. Opted-out contacts are skipped; free text is skipped outside the 24-hour
 * window when a real WhatsApp number is connected (ADR-018).
 */
class SendWhatsAppStep implements StepAction
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly TemplateRenderer $renderer,
        private readonly MessagingCompliance $compliance,
    ) {}

    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::in(['text', 'template'])],
            'message' => ['exclude_if:mode,template', 'required', 'string', 'max:'.config('automation.limits.message')],
            'template' => ['exclude_unless:mode,template', 'required', 'array', new ApprovedTemplate],
            'template.name' => ['exclude_unless:mode,template', 'required', 'string', 'max:512'],
            'template.language' => ['exclude_unless:mode,template', 'required', 'string', 'max:20'],
            'params' => ['exclude_unless:mode,template', 'nullable', 'array', 'list', 'max:10'],
            'params.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function normalize(array $config): array
    {
        if (($config['mode'] ?? 'text') !== 'template') {
            return ['message' => trim($config['message'])];
        }

        return [
            'mode' => 'template',
            'template' => ['name' => $config['template']['name'], 'language' => $config['template']['language']],
            'params' => array_map(fn ($param) => trim((string) $param), array_values($config['params'] ?? [])),
        ];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $subject = $context->subject;
        $phone = $subject->phone();

        if (! $phone) {
            return ActionResult::skipped('There is no phone number to send to.');
        }

        $conversation = $this->compliance->conversationFor('whatsapp', $phone);

        if ($conversation?->isOptedOut()) {
            return ActionResult::skipped('The contact opted out of WhatsApp messages.');
        }

        $base = [
            'channel' => 'whatsapp',
            'recipient' => $phone,
            'recipient_name' => $subject->contactName(),
            'idempotency_key' => $context->idempotencyKey,
            'purpose' => MessagePurpose::Automation,
            'lead_id' => $subject->lead?->id,
            'customer_id' => $subject->customer?->id,
            'automation_run_id' => $context->run->id,
        ];

        if (($config['mode'] ?? 'text') === 'template') {
            $template = MessageTemplate::query()->approved()
                ->where('channel', 'whatsapp')
                ->where('name', $config['template']['name'] ?? '')
                ->where('language', $config['template']['language'] ?? '')
                ->first();

            if (! $template) {
                return ActionResult::skipped('The WhatsApp template "'.($config['template']['name'] ?? '').'" is not approved any more. Sync templates in Settings → Messaging.');
            }

            // Meta rejects empty parameters; a variable with no value becomes a dash.
            $params = array_map(
                fn ($param) => Str::limit(trim($this->renderer->render((string) $param, $subject)), 1024, '') ?: '-',
                array_slice(array_pad($config['params'] ?? [], $template->variables, ''), 0, $template->variables),
            );

            $message = $this->messaging->queue([
                ...$base,
                'body' => $template->render($params),
                'template' => ['name' => $template->name, 'language' => $template->language, 'params' => $params],
            ]);

            return $this->completed($message, "Template \"{$template->name}\"");
        }

        if ($reason = $this->compliance->textBlockedReason('whatsapp', $conversation)) {
            return ActionResult::skipped($reason.' Use an approved template in this step.');
        }

        $message = $this->messaging->queue([...$base, 'body' => $this->renderer->render($config['message'], $subject)]);

        return $this->completed($message, 'WhatsApp message');
    }

    private function completed(OutboundMessage $message, string $label): ActionResult
    {
        $when = $message->scheduled_for?->isFuture() ? ' It waits for the end of quiet hours.' : '';

        return ActionResult::completed("{$label} queued for {$message->maskedRecipient()}.{$when}", ['message_id' => $message->id]);
    }
}
