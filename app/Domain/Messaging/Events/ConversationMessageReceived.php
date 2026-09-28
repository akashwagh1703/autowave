<?php

namespace App\Domain\Messaging\Events;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A contact messaged the business. Reserved for a future `message.received` automation trigger (AW-053). */
class ConversationMessageReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ConversationMessage $message,
    ) {}
}
