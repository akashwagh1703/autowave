<?php

namespace App\Domain\Commerce\Listeners;

use App\Domain\Automation\Support\SubjectContext;
use App\Domain\Automation\Support\TemplateRenderer;
use App\Domain\Commerce\Events\OrderReady;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\Phone;
use Throwable;

/**
 * When an order is marked ready and Settings → Orders WhatsApp is on, tell the customer with a
 * pickup- or delivery-specific celebration message (no QR).
 */
class SendOrderReadyWhatsApp
{
    public function __construct(
        private readonly CommerceSettings $settings,
        private readonly MessagingService $messaging,
        private readonly TemplateRenderer $renderer,
        private readonly MessagingCompliance $compliance,
        private readonly TenantContext $context,
    ) {}

    public function handle(OrderReady $event): void
    {
        try {
            $this->send($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function send(OrderReady $event): void
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
        $body = $this->renderer->render($this->settings->readyMessageFor($order->fulfilment), $subject);

        $this->messaging->queue([
            'channel' => 'whatsapp',
            'recipient' => $phone,
            'recipient_name' => $customer->name,
            'body' => $body,
            'idempotency_key' => 'order-ready-wa:'.$order->id,
            'purpose' => MessagePurpose::Automation,
            'customer_id' => $customer->id,
        ]);
    }
}
