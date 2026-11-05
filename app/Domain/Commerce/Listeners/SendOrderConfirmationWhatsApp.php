<?php

namespace App\Domain\Commerce\Listeners;

use App\Domain\Automation\Support\SubjectContext;
use App\Domain\Automation\Support\TemplateRenderer;
use App\Domain\Commerce\Events\OrderConfirmed;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\Phone;
use Throwable;

/**
 * After the business confirms an order (typically once payment screenshot is received), tell the
 * customer. No payment/counter QR — that was sent on the pending payment request.
 */
class SendOrderConfirmationWhatsApp
{
    public function __construct(
        private readonly CommerceSettings $settings,
        private readonly MessagingService $messaging,
        private readonly TemplateRenderer $renderer,
        private readonly MessagingCompliance $compliance,
        private readonly TenantContext $context,
    ) {}

    public function handle(OrderConfirmed $event): void
    {
        try {
            $this->send($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function send(OrderConfirmed $event): void
    {
        $confirmation = $this->settings->confirmation();

        if (! $confirmation['whatsapp']) {
            return;
        }

        $order = $event->order->loadMissing(['customer', 'items']);
        $customer = $order->customer;
        $phone = Phone::normalize($customer?->phone);

        if (! $phone) {
            return;
        }

        $conversation = $this->compliance->conversationFor('whatsapp', $phone);

        if ($conversation?->isOptedOut()) {
            return;
        }

        if ($this->compliance->textBlockedReason('whatsapp', $conversation)) {
            return;
        }

        $subject = SubjectContext::for($this->context->tenant(), $order);
        $body = $this->renderer->render($confirmation['message'], $subject);

        $this->messaging->queue([
            'channel' => 'whatsapp',
            'recipient' => $phone,
            'recipient_name' => $customer->name,
            'body' => $body,
            'idempotency_key' => 'order-confirm-wa:'.$order->id,
            'purpose' => MessagePurpose::Automation,
            'customer_id' => $customer->id,
        ]);
    }
}
