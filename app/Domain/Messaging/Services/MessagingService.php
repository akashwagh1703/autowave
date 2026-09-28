<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Automation\Services\RunLogger;
use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Enums\MessagePurpose;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Inbound\StatusUpdate;
use App\Domain\Messaging\Jobs\SendOutboundMessage;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Support\ChannelResolver;
use App\Domain\Messaging\Support\MessagingCompliance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The only way business code sends a message (master prompt §43). queue() stores the message and
 * hands it to the `messaging` queue; the provider for the channel delivers it.
 *
 * - The provider is resolved per tenant (ChannelResolver): the connected Meta channel, or the fallback.
 * - WhatsApp and Instagram messages are appended to the contact's conversation in the same transaction.
 * - Automation messages queued in quiet hours wait until they end (`scheduled_for`).
 * - Idempotent: a second queue() with the same idempotency key returns the first message, so a
 *   retried automation step never sends twice.
 *
 * Callers check MessagingCompliance (window, opt-out) before queueing; queue() does not refuse.
 */
class MessagingService
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly RunLogger $runLogger,
        private readonly ChannelResolver $channels,
        private readonly MessagingCompliance $compliance,
        private readonly ConversationRecorder $conversations,
    ) {}

    /**
     * @param  array{
     *     channel: string,
     *     recipient: string,
     *     body: string,
     *     idempotency_key: string,
     *     purpose?: MessagePurpose,
     *     recipient_name?: ?string,
     *     subject?: ?string,
     *     template?: ?array{name: string, language: string, params: list<string>},
     *     lead_id?: ?int,
     *     customer_id?: ?int,
     *     tenant_user_id?: ?int,
     *     automation_run_id?: ?int,
     *     conversation_id?: ?int,
     *     sent_by_user_id?: ?int,
     * }  $data
     */
    public function queue(array $data): OutboundMessage
    {
        if (! config('messaging.channels.'.$data['channel'])) {
            throw new InvalidArgumentException("Unknown messaging channel [{$data['channel']}].");
        }

        if ($existing = $this->find($data['idempotency_key'])) {
            return $existing;
        }

        $purpose = $data['purpose'] ?? MessagePurpose::System;
        $resolved = $this->channels->resolve($data['channel']);
        $scheduledFor = $this->compliance->scheduleFor($data['channel'], $purpose);

        try {
            // Savepoint: a lost race on the unique key must not abort an enclosing transaction.
            $message = DB::transaction(function () use ($data, $resolved, $scheduledFor, $purpose) {
                $message = OutboundMessage::query()->create([
                    'channel' => $data['channel'],
                    'provider' => $resolved['provider'],
                    'simulated' => $resolved['simulated'],
                    'recipient' => $data['recipient'],
                    'recipient_name' => isset($data['recipient_name']) ? Str::limit($data['recipient_name'], 150, '') : null,
                    'subject' => isset($data['subject']) ? Str::limit($data['subject'], 200, '') : null,
                    'body' => $data['body'],
                    'template' => $data['template'] ?? null,
                    'status' => MessageStatus::Queued,
                    'idempotency_key' => $data['idempotency_key'],
                    'lead_id' => $data['lead_id'] ?? null,
                    'customer_id' => $data['customer_id'] ?? null,
                    'tenant_user_id' => $data['tenant_user_id'] ?? null,
                    'automation_run_id' => $data['automation_run_id'] ?? null,
                    'conversation_id' => $data['conversation_id'] ?? null,
                    'sent_by_user_id' => $data['sent_by_user_id'] ?? null,
                    'queued_at' => now(),
                    'scheduled_for' => $scheduledFor,
                ]);

                if (! $message->tenant_user_id) {
                    $this->conversations->recordOutbound($message, markRead: $purpose === MessagePurpose::Reply);
                }

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->find($data['idempotency_key']) ?? throw new \LogicException('Duplicate message key without a message.');
        }

        if ($scheduledFor) {
            $this->logOnRun($message, 'info', 'message.scheduled', $this->label($message).' to '.$message->maskedRecipient().' waits for the end of quiet hours ('.$scheduledFor->toIso8601String().').');
        }

        $this->dispatch($message);

        return $message;
    }

    /** Hand a queued message to the queue. A queue outage leaves it queued for messaging:dispatch-pending. */
    public function dispatch(OutboundMessage $message): void
    {
        try {
            $job = new SendOutboundMessage($message->id);

            if ($message->scheduled_for?->isFuture()) {
                $job->delay($message->scheduled_for);
            }

            Bus::dispatch($job->afterCommit());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Called by SendOutboundMessage. Claims the message so two workers never send it twice. */
    public function deliver(OutboundMessage $message): void
    {
        $claimed = OutboundMessage::query()->whereKey($message->id)
            ->where('status', MessageStatus::Queued)
            ->where(fn ($query) => $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()))
            ->update(['status' => MessageStatus::Sending, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $message->refresh();

        try {
            $providerMessageId = $this->provider($message->provider)->send($message);
        } catch (Throwable $exception) {
            $message->forceFill(['status' => MessageStatus::Queued, 'error' => Str::limit($exception->getMessage(), 1000, '')])->save();

            throw $exception;
        }

        $message->forceFill([
            'status' => MessageStatus::Sent,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
            'error' => null,
        ])->save();

        $this->recordOnTimeline($message);
        $this->logOnRun($message, 'info', 'message.sent', $this->label($message).' sent to '.$message->maskedRecipient().($message->simulated ? ' (simulated — no provider connected)' : '').'.');
    }

    /** Called when the delivery job has used all its attempts, hit a permanent error, or Meta reports a failure. */
    public function markFailed(OutboundMessage $message, Throwable|string|null $error): void
    {
        $text = $error instanceof Throwable ? $error->getMessage() : ($error ?? 'Unknown error');

        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'error' => Str::limit($text, 1000, ''),
        ])->save();

        $this->logOnRun($message, 'error', 'message.failed', $this->label($message).' to '.$message->maskedRecipient().' failed: '.Str::limit($message->error, 300));
    }

    /** Queue a failed message again (manual retry). */
    public function retry(OutboundMessage $message): bool
    {
        $updated = OutboundMessage::query()->whereKey($message->id)
            ->where('status', MessageStatus::Failed)
            ->update(['status' => MessageStatus::Queued, 'attempts' => 0, 'error' => null, 'failed_at' => null, 'scheduled_for' => null, 'queued_at' => now(), 'updated_at' => now()]);

        if ($updated !== 1) {
            return false;
        }

        $this->logOnRun($message, 'info', 'message.retried', $this->label($message).' to '.$message->maskedRecipient().' queued again.');
        $this->dispatch($message->refresh());

        return true;
    }

    /**
     * A delivery receipt from the provider. Receipts only move forward (sent → delivered → read); a
     * failure can arrive at any time. Returns whether a message was found.
     */
    public function applyReceipt(StatusUpdate $update): bool
    {
        $message = OutboundMessage::query()->where('channel', $update->channel)->where('provider_message_id', $update->providerMessageId)->first()
            ?? ($update->outboundMessageId ? OutboundMessage::query()->where('channel', $update->channel)->whereNull('provider_message_id')->find($update->outboundMessageId) : null);

        if (! $message) {
            return false;
        }

        $status = MessageStatus::from($update->status);

        if ($status === MessageStatus::Failed) {
            if ($message->status !== MessageStatus::Failed) {
                $this->markFailed($message, $update->error ?? 'The provider reported a delivery failure.');
            }

            return true;
        }

        if ($message->status === MessageStatus::Failed || $status->rank() <= $message->status->rank()) {
            return true;
        }

        $message->forceFill(array_filter([
            'status' => $status,
            'provider_message_id' => $message->provider_message_id ?? $update->providerMessageId,
            'sent_at' => $message->sent_at ?? $update->occurredAt,
            'delivered_at' => in_array($status, [MessageStatus::Delivered, MessageStatus::Read], true) ? ($message->delivered_at ?? $update->occurredAt) : null,
            'read_at' => $status === MessageStatus::Read ? $update->occurredAt : null,
        ], fn ($value) => $value !== null))->save();

        return true;
    }

    private function provider(string $name): MessagingProvider
    {
        $class = config("messaging.providers.{$name}.class") ?? throw new InvalidArgumentException("Unknown messaging provider [{$name}].");

        return app($class);
    }

    private function find(string $key): ?OutboundMessage
    {
        return OutboundMessage::query()->where('idempotency_key', $key)->first();
    }

    private function label(OutboundMessage $message): string
    {
        return config("messaging.channels.{$message->channel}.label", Str::headline($message->channel)).' message';
    }

    /** Messages to a lead or customer appear on their timeline; team notifications do not. */
    private function recordOnTimeline(OutboundMessage $message): void
    {
        if ($message->tenant_user_id || (! $message->lead_id && ! $message->customer_id)) {
            return;
        }

        $message->loadMissing(['lead', 'customer', 'run.automation', 'sender']);
        $lead = $message->lead?->trashed() ? null : $message->lead;

        if (! $lead && ! $message->customer) {
            return;
        }

        $this->recordActivity->handle(
            $message->channel,
            lead: $lead,
            customer: $lead ? null : $message->customer,
            actor: $message->sender,
            body: $message->body,
            metadata: array_filter([
                'via' => $message->automation_run_id ? 'automation' : ($message->sent_by_user_id ? 'inbox' : 'system'),
                'automation_id' => $message->run?->automation_id,
                'automation_name' => $message->run?->automation?->name,
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'template' => $message->template['name'] ?? null,
                'simulated' => $message->simulated,
                'direction' => 'outbound',
            ], fn ($value) => $value !== null),
        );
    }

    private function logOnRun(OutboundMessage $message, string $level, string $event, string $text): void
    {
        if ($message->automation_run_id && $run = $message->run()->first()) {
            $this->runLogger->log($run, $event, $text, $level, context: ['message_id' => $message->id]);
        }
    }
}
