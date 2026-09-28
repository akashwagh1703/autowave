<?php

namespace App\Domain\AI\Jobs;

use App\Domain\AI\Actions\ExtractLeadDetails;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIUnavailable;
use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Services\AIGateway;
use App\Domain\AI\Services\AIService;
use App\Domain\AI\Support\AISettings;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fills a lead's details from its conversation once the contact has finished typing (ADR-019).
 * Unique per conversation while waiting, so a burst of messages costs one AI call.
 */
class ExtractLeadFromConversation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public readonly int $tenantId, public readonly int $conversationId)
    {
        $this->onQueue(config('ai.queue'));
        $this->afterCommit();
        $this->tries = (int) config('ai.tries');
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->conversationId}";
    }

    public function uniqueFor(): int
    {
        return (int) config('ai.extraction.delay_seconds') + 300;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('ai.backoff');
    }

    public function handle(TenantContext $context): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant || ! $tenant->isActive()) {
            return;
        }

        $context->run($tenant, function () {
            $conversation = Conversation::query()->with('lead.stage')->find($this->conversationId);
            $lead = $conversation?->lead;

            if (! $lead || $lead->trashed() || ! $lead->isOpen() || ! app(AISettings::class)->autoExtract() || ! app(AIGateway::class)->available()) {
                return;
            }

            $runs = AIResult::query()->for(AIService::EXTRACTION, 'lead', $lead->id)->where('output->via', 'auto')->count();

            if ($runs >= (int) config('ai.extraction.max_auto_runs')) {
                return;
            }

            try {
                app(ExtractLeadDetails::class)->handle($lead, null, 'auto');
            } catch (AIUnavailable) {
                return;
            } catch (AIProviderException $exception) {
                if ($exception->temporary) {
                    throw $exception;
                }
            }
        });
    }
}
