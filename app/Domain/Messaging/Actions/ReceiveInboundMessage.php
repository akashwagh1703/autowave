<?php

namespace App\Domain\Messaging\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Actions\CreateLead;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Events\ConversationMessageReceived;
use App\Domain\Messaging\Inbound\InboundMessage;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Services\ConversationRecorder;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stores a message from a contact in the current tenant (ADR-018):
 * conversation → idempotent message → counters and opt-out → lead/customer link → timeline.
 */
class ReceiveInboundMessage
{
    public function __construct(
        private readonly ConversationRecorder $recorder,
        private readonly CreateLead $createLead,
        private readonly RecordActivity $recordActivity,
        private readonly TenantContext $context,
    ) {}

    /** @return ?ConversationMessage null when the message was already stored (webhook retry) */
    public function handle(InboundMessage $inbound): ?ConversationMessage
    {
        return DB::transaction(function () use ($inbound) {
            $found = $this->recorder->findOrCreate($inbound->channel, $inbound->handle, ['contact_name' => $inbound->name]);
            // Serialises concurrent messages from the same contact, so counters stay right.
            $conversation = Conversation::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();

            $message = $this->store($conversation, $inbound);

            if (! $message) {
                return null;
            }

            if (! $conversation->lead_id && ! $conversation->customer_id) {
                $this->link($conversation, $inbound);
            }

            $this->recorder->touch($conversation, $message, ConversationMessage::INBOUND);

            $keyword = $inbound->type === 'text' ? MessagingCompliance::keyword($inbound->text) : null;
            $conversation->forceFill([
                'contact_name' => $inbound->name ?? $conversation->contact_name,
                'status' => ConversationStatus::Open,
                'unread_count' => $conversation->unread_count + 1,
                'last_inbound_at' => $conversation->last_inbound_at?->gt($inbound->occurredAt) ? $conversation->last_inbound_at : $inbound->occurredAt,
                'opted_out_at' => match ($keyword) {
                    'out' => $conversation->opted_out_at ?? now(),
                    'in' => null,
                    default => $conversation->opted_out_at,
                },
            ])->save();

            $this->recordOnTimeline($conversation, $message, $keyword);

            ConversationMessageReceived::dispatch($conversation, $message);

            return $message;
        });
    }

    private function store(Conversation $conversation, InboundMessage $inbound): ?ConversationMessage
    {
        $exists = ConversationMessage::query()
            ->where('channel', $inbound->channel)
            ->where('provider_message_id', $inbound->providerMessageId)
            ->exists();

        if ($exists) {
            return null;
        }

        try {
            return DB::transaction(fn () => ConversationMessage::query()->create([
                'conversation_id' => $conversation->id,
                'channel' => $inbound->channel,
                'direction' => ConversationMessage::INBOUND,
                'type' => $inbound->type,
                'body' => $inbound->text,
                'provider_message_id' => $inbound->providerMessageId,
                'meta' => $inbound->meta ?: null,
                'sent_at' => $inbound->occurredAt,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * A new contact is matched to a customer with the same phone, else an open lead with the same
     * phone, else becomes a new lead (when the Leads module is on).
     */
    private function link(Conversation $conversation, InboundMessage $inbound): void
    {
        $phone = $inbound->channel === 'whatsapp' ? $inbound->handle : null;
        $customer = $phone ? Customer::query()->where('phone_normalized', $phone)->first() : null;
        $lead = $phone ? CreateLead::openDuplicateOf($phone) : null;

        if (! $lead && ! $customer && $this->context->hasModule('leads')) {
            $lead = $this->createLead($inbound, $phone);
        }

        $conversation->forceFill([
            'lead_id' => $lead?->id,
            'customer_id' => $customer?->id ?? $lead?->customer_id,
        ]);
    }

    private function createLead(InboundMessage $inbound, ?string $phone): ?Lead
    {
        $label = config("messaging.channels.{$inbound->channel}.label", $inbound->channel);
        $name = $inbound->name ?? ($phone ?? "{$label} user …".substr($inbound->handle, -4));

        try {
            return $this->createLead->handle([
                'name' => $name,
                'phone' => $phone,
                'source' => $inbound->channel,
                'interest' => null,
            ]);
        } catch (ValidationException) {
            // Another message created the lead a moment ago.
            return CreateLead::openDuplicateOf($phone);
        }
    }

    private function recordOnTimeline(Conversation $conversation, ConversationMessage $message, ?string $keyword): void
    {
        $lead = $conversation->lead_id ? $conversation->lead()->first() : null;
        $lead = $lead?->trashed() ? null : $lead;
        $customer = ! $lead && $conversation->customer_id ? $conversation->customer()->first() : null;

        if (! $lead && ! $customer) {
            return;
        }

        $this->recordActivity->handle(
            $conversation->channel,
            lead: $lead,
            customer: $customer,
            body: $message->body,
            metadata: array_filter([
                'direction' => 'inbound',
                'conversation_id' => $conversation->id,
                'from_name' => $conversation->contact_name,
                'opt_out' => $keyword,
            ], fn ($value) => $value !== null),
            occurredAt: $message->sent_at,
        );
    }
}
