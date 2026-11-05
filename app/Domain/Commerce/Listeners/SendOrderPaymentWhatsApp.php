<?php

namespace App\Domain\Commerce\Listeners;

use App\Domain\Automation\Support\SubjectContext;
use App\Domain\Automation\Support\TemplateRenderer;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Events\OrderCreated;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Commerce\Support\OrderQr;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\Phone;
use Throwable;

/**
 * Pending unpaid orders: ask the customer to pay via WhatsApp (optional QR) and reply with a
 * payment screenshot. The business confirms the order after that — not for counter/delivery staff.
 */
class SendOrderPaymentWhatsApp
{
    public function __construct(
        private readonly CommerceSettings $settings,
        private readonly OrderQr $qr,
        private readonly MessagingService $messaging,
        private readonly TemplateRenderer $renderer,
        private readonly MessagingCompliance $compliance,
        private readonly TenantContext $context,
    ) {}

    public function handle(OrderCreated $event): void
    {
        try {
            $this->send($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function send(OrderCreated $event): void
    {
        $confirmation = $this->settings->confirmation();

        if (! $confirmation['whatsapp']) {
            return;
        }

        $order = $event->order->loadMissing(['customer', 'items']);

        if ($order->status !== OrderStatus::Pending || bccomp($order->balance(), '0', 2) <= 0) {
            return;
        }

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
        $body = $this->renderer->render($confirmation['payment_message'], $subject);
        $interactive = null;

        if ($confirmation['include_qr']) {
            $interactive = ['kind' => 'image', 'image' => $this->qr->store($order)];
        }

        $this->messaging->queue([
            'channel' => 'whatsapp',
            'recipient' => $phone,
            'recipient_name' => $customer->name,
            'body' => $body,
            'interactive' => $interactive,
            'idempotency_key' => 'order-payment-wa:'.$order->id,
            'purpose' => MessagePurpose::Automation,
            'customer_id' => $customer->id,
        ]);
    }
}
