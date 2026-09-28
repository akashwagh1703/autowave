<?php

namespace App\Domain\Messaging\Enums;

/** Why a message is sent; decides which compliance rules apply (ADR-018). */
enum MessagePurpose: string
{
    /** Sent by an automation to a lead or customer: opt-out and quiet hours apply. */
    case Automation = 'automation';

    /** Typed by a team member in the inbox: sent at once. */
    case Reply = 'reply';

    /** To the business's own team (NotifyTeamStep): sent at once. */
    case Notification = 'notification';

    /** Anything else the system sends. */
    case System = 'system';
}
