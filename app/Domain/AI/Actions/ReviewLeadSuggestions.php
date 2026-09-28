<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Services\AIService;
use App\Domain\Lead\Actions\UpdateLead;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Staff apply or dismiss lead-detail suggestions from an extraction (ADR-019). */
class ReviewLeadSuggestions
{
    public function __construct(private readonly UpdateLead $updateLead) {}

    /**
     * @param  list<string>  $attributes  lead attributes to apply (others stay pending)
     * @return list<string> the attributes applied
     */
    public function apply(Lead $lead, AIResult $result, array $attributes, User $actor): array
    {
        return DB::transaction(function () use ($lead, $result, $attributes, $actor) {
            $result = $this->lock($lead, $result);
            $suggestions = $result->output['suggestions'] ?? [];
            $data = [];

            foreach ($attributes as $attribute) {
                if (($suggestions[$attribute]['status'] ?? null) === ExtractLeadDetails::PENDING) {
                    $data[$attribute] = $suggestions[$attribute]['value'];
                    $suggestions[$attribute]['status'] = ExtractLeadDetails::APPLIED;
                }
            }

            if ($data !== []) {
                $this->updateLead->handle($lead, $data, $actor, ['via' => 'ai_suggestion']);
            }

            $this->save($result, $suggestions);

            return array_keys($data);
        });
    }

    /** @param  list<string>  $attributes */
    public function dismiss(Lead $lead, AIResult $result, array $attributes): void
    {
        DB::transaction(function () use ($lead, $result, $attributes) {
            $result = $this->lock($lead, $result);
            $suggestions = $result->output['suggestions'] ?? [];

            foreach ($attributes as $attribute) {
                if (($suggestions[$attribute]['status'] ?? null) === ExtractLeadDetails::PENDING) {
                    $suggestions[$attribute]['status'] = ExtractLeadDetails::DISMISSED;
                }
            }

            $this->save($result, $suggestions);
        });
    }

    private function lock(Lead $lead, AIResult $result): AIResult
    {
        return AIResult::query()
            ->for(AIService::EXTRACTION, 'lead', $lead->id)
            ->whereKey($result->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param  array<string, array<string, mixed>>  $suggestions */
    private function save(AIResult $result, array $suggestions): void
    {
        $pending = array_filter($suggestions, fn (array $suggestion) => ($suggestion['status'] ?? null) === ExtractLeadDetails::PENDING);

        $result->forceFill([
            'output' => [...$result->output, 'suggestions' => $suggestions],
            'status' => $pending === [] ? AIResult::USED : AIResult::READY,
        ])->save();
    }
}
