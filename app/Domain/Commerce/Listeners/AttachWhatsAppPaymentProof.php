<?php

namespace App\Domain\Commerce\Listeners;

use App\Domain\Commerce\Actions\AttachOrderPaymentProofFromWhatsApp;
use App\Domain\Messaging\Events\InboundMediaStored;
use Throwable;

/** Copies inbound WhatsApp payment screenshots onto the customer's pending unpaid order. */
class AttachWhatsAppPaymentProof
{
    public function __construct(private readonly AttachOrderPaymentProofFromWhatsApp $attach) {}

    public function handle(InboundMediaStored $event): void
    {
        try {
            $this->attach->handle($event->message);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
