<?php

namespace App\Domain\AI\Listeners;

use App\Domain\AI\Jobs\ExtractLeadFromConversation;
use App\Domain\AI\Support\AISettings;
use App\Domain\Messaging\Events\ConversationMessageReceived;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Tenant\Support\TenantContext;
use Throwable;

/**
 * After a lead writes on WhatsApp or Instagram, schedules one extraction of its details (debounced).
 * Opt-out keywords and non-text messages are ignored. Never breaks message processing.
 */
class QueueLeadExtraction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AISettings $settings,
    ) {}

    public function handle(ConversationMessageReceived $event): void
    {
        try {
            if (! $event->conversation->lead_id
                || $event->message->type !== 'text'
                || MessagingCompliance::keyword($event->message->body) !== null
                || ! $this->context->check()
                || ! $this->context->hasModule('ai')
                || ! $this->context->hasModule('leads')) {
                return;
            }

            $settings = $this->settings->all();

            if (! $settings['enabled'] || ! $settings['auto_extract']) {
                return;
            }

            ExtractLeadFromConversation::dispatch($this->context->id(), $event->conversation->id)
                ->delay(now()->addSeconds((int) config('ai.extraction.delay_seconds')));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
