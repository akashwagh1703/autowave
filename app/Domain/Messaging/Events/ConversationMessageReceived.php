<?php

namespace App\Domain\Messaging\Events;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A contact messaged the business. Starts `message.received` automations, the AI lead extraction (ADR-019) and the WhatsApp assistant (ADR-021). */
class ConversationMessageReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ConversationMessage $message,
    ) {}
}
