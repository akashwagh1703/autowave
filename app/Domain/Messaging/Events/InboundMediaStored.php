<?php

namespace App\Domain\Messaging\Events;

use App\Domain\Messaging\Models\ConversationMessage;
use Illuminate\Foundation\Events\Dispatchable;

/** An inbound WhatsApp/Instagram file finished downloading onto the conversation message. */
class InboundMediaStored
{
    use Dispatchable;

    public function __construct(public readonly ConversationMessage $message) {}
}
