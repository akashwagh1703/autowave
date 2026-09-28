<?php

namespace App\Domain\Messaging\Contracts;

use App\Domain\Messaging\Models\OutboundMessage;

/**
 * A channel provider (WhatsApp Cloud API, Instagram, SMTP…). Only MessagingService talks to
 * providers; business code never does (master prompt §43).
 */
interface MessagingProvider
{
    /**
     * Deliver the message. Throw on failure so the delivery job retries.
     *
     * @return string the provider's message id
     */
    public function send(OutboundMessage $message): string;
}
