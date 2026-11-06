<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Files\Actions\ManageAttachments;
use App\Domain\Files\Models\Attachment;
use App\Domain\Messaging\Models\ConversationMessage;
use Throwable;

/**
 * When a customer sends a payment screenshot on WhatsApp, copy it onto their latest pending unpaid
 * order so staff can review it, record the payment, and confirm.
 */
class AttachOrderPaymentProofFromWhatsApp
{
    public function __construct(
        private readonly ManageAttachments $attachments,
        private readonly RecordActivity $activity,
    ) {}

    public function handle(ConversationMessage $message): ?Attachment
    {
        if ($message->direction !== ConversationMessage::INBOUND) {
            return null;
        }

        if (($message->meta['payment_proof_order_id'] ?? null) !== null) {
            return null;
        }

        $source = $message->attachment;

        if (! $source || ! str_starts_with((string) $source->mime_type, 'image/')) {
            return null;
        }

        $conversation = $message->conversation()->first();
        $customerId = $conversation?->customer_id;

        if (! $customerId) {
            return null;
        }

        $order = Order::query()
            ->where('customer_id', $customerId)
            ->where('status', OrderStatus::Pending)
            ->whereColumn('amount_paid', '<', 'total')
            ->orderByDesc('id')
            ->first();

        if (! $order) {
            return null;
        }

        try {
            $proof = $this->attachments->attachCopy(
                $source,
                $order,
                'order',
                title: __('WhatsApp payment screenshot'),
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $message->forceFill([
            'meta' => [...($message->meta ?? []), 'payment_proof_order_id' => $order->id],
        ])->save();

        $this->activity->handle(
            'payment_proof_received',
            customer: $order->customer,
            order: $order,
            body: __('Payment screenshot received on WhatsApp.'),
            metadata: [
                'number' => $order->number,
                'attachment_id' => $proof->id,
                'conversation_message_id' => $message->id,
            ],
        );

        return $proof;
    }
}
