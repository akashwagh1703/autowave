<?php

namespace App\Domain\Chatbot\Listeners;

use App\Domain\Chatbot\Jobs\ReplyWithChatbot;
use App\Domain\Chatbot\Support\ChatbotSettings;
use App\Domain\Messaging\Events\ConversationMessageReceived;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Tenant\Support\TenantContext;
use Throwable;

/**
 * When a contact writes on WhatsApp and the business has the assistant on, queues its answer (ADR-021).
 * STOP/START messages and opted-out contacts never get one. Never breaks message processing.
 */
class QueueChatbotReply
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ChatbotSettings $settings,
    ) {}

    public function handle(ConversationMessageReceived $event): void
    {
        try {
            if ($event->conversation->channel !== 'whatsapp'
                || $event->conversation->isOptedOut()
                || ($event->message->type === 'text' && MessagingCompliance::keyword($event->message->body) !== null)
                || ! $this->context->check()
                || ! $this->context->hasModule('messaging')
                || ! $this->settings->enabled()) {
                return;
            }

            ReplyWithChatbot::dispatch($this->context->id(), $event->conversation->id, $event->message->id);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
