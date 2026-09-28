<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Automation\Services\RunLogger;
use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Jobs\SendOutboundMessage;
use App\Domain\Messaging\Models\OutboundMessage;
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
 * Idempotent: a second queue() with the same idempotency key returns the first message, so a
 * retried automation step never sends twice.
 */
class MessagingService
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly RunLogger $runLogger,
    ) {}

    /**
     * @param  array{
     *     channel: string,
     *     recipient: string,
     *     body: string,
     *     idempotency_key: string,
     *     recipient_name?: ?string,
     *     subject?: ?string,
     *     lead_id?: ?int,
     *     customer_id?: ?int,
     *     tenant_user_id?: ?int,
     *     automation_run_id?: ?int,
     * }  $data
     */
    public function queue(array $data): OutboundMessage
    {
        $channel = config('messaging.channels.'.$data['channel'])
            ?? throw new InvalidArgumentException("Unknown messaging channel [{$data['channel']}].");

        if ($existing = $this->find($data['idempotency_key'])) {
            return $existing;
        }

        try {
            // Savepoint: a lost race on the unique key must not abort an enclosing transaction.
            $message = DB::transaction(fn () => OutboundMessage::query()->create([
                'channel' => $data['channel'],
                'provider' => $channel['provider'],
                'simulated' => (bool) config('messaging.providers.'.$channel['provider'].'.simulated', false),
                'recipient' => $data['recipient'],
                'recipient_name' => isset($data['recipient_name']) ? Str::limit($data['recipient_name'], 150, '') : null,
                'subject' => isset($data['subject']) ? Str::limit($data['subject'], 200, '') : null,
                'body' => $data['body'],
                'status' => MessageStatus::Queued,
                'idempotency_key' => $data['idempotency_key'],
                'lead_id' => $data['lead_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'tenant_user_id' => $data['tenant_user_id'] ?? null,
                'automation_run_id' => $data['automation_run_id'] ?? null,
                'queued_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->find($data['idempotency_key']) ?? throw new \LogicException('Duplicate message key without a message.');
        }

        $this->dispatch($message);

        return $message;
    }

    /** Hand a queued message to the queue. A queue outage leaves it queued for messaging:dispatch-pending. */
    public function dispatch(OutboundMessage $message): void
    {
        try {
            Bus::dispatch((new SendOutboundMessage($message->id))->afterCommit());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Called by SendOutboundMessage. Claims the message so two workers never send it twice. */
    public function deliver(OutboundMessage $message): void
    {
        $claimed = OutboundMessage::query()->whereKey($message->id)
            ->where('status', MessageStatus::Queued)
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

    /** Called when the delivery job has used all its attempts. */
    public function markFailed(OutboundMessage $message, ?Throwable $exception): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'error' => Str::limit($exception?->getMessage() ?? 'Unknown error', 1000, ''),
        ])->save();

        $this->logOnRun($message, 'error', 'message.failed', $this->label($message).' to '.$message->maskedRecipient().' failed: '.Str::limit($message->error, 300));
    }

    /** Queue a failed message again (manual retry). */
    public function retry(OutboundMessage $message): bool
    {
        $updated = OutboundMessage::query()->whereKey($message->id)
            ->where('status', MessageStatus::Failed)
            ->update(['status' => MessageStatus::Queued, 'attempts' => 0, 'error' => null, 'failed_at' => null, 'queued_at' => now(), 'updated_at' => now()]);

        if ($updated !== 1) {
            return false;
        }

        $this->logOnRun($message, 'info', 'message.retried', $this->label($message).' to '.$message->maskedRecipient().' queued again.');
        $this->dispatch($message->refresh());

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

        $message->loadMissing(['lead', 'customer', 'run.automation']);

        $this->recordActivity->handle(
            $message->channel,
            lead: $message->lead,
            customer: $message->lead ? null : $message->customer,
            body: $message->body,
            metadata: array_filter([
                'via' => $message->automation_run_id ? 'automation' : 'system',
                'automation_id' => $message->run?->automation_id,
                'automation_name' => $message->run?->automation?->name,
                'message_id' => $message->id,
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
