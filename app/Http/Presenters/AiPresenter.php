<?php

namespace App\Http\Presenters;

use App\Domain\AI\Actions\ExtractLeadDetails;
use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Services\AIService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;

/** Shapes AI results (drafts, summaries, lead suggestions) for the business app. */
class AiPresenter
{
    /** Lead attribute → label for suggestions. */
    public const FIELD_LABELS = ['name' => 'Name', 'email' => 'Email', 'interest' => 'Interest', 'estimated_value' => 'Estimated value'];

    /**
     * AI props for a lead or customer page: the last summary and (for a lead) suggestions waiting for review.
     * Null when the business or the user cannot use AI.
     *
     * @return ?array{summary: ?array<string, mixed>, suggestions: ?array<string, mixed>, can_extract: bool}
     */
    public static function record(Lead|Customer $record, User $user): ?array
    {
        if (! app(TenantContext::class)->hasModule('ai') || ! $user->can('ai.use')) {
            return null;
        }

        $lead = $record instanceof Lead ? $record : null;
        $summary = AIResult::query()->for(AIService::SUMMARY, $lead ? 'lead' : 'customer', $record->id)->latest('updated_at')->latest('id')->first();

        return [
            'summary' => $summary ? self::summary($summary) : null,
            'suggestions' => $lead ? self::suggestions(ExtractLeadDetails::pending($lead)) : null,
            'can_extract' => $lead !== null && ! $lead->trashed() && $user->can('leads.update'),
        ];
    }

    /** @return ?array{id: int, text: string, created_at: ?string} */
    public static function draft(?AIResult $result): ?array
    {
        return $result ? [
            'id' => $result->id,
            'text' => (string) ($result->output['text'] ?? ''),
            'created_at' => $result->created_at?->toIso8601String(),
        ] : null;
    }

    /** @return array{id: int, text: string, updated_at: ?string} */
    public static function summary(AIResult $result): array
    {
        return [
            'id' => $result->id,
            'text' => (string) ($result->output['text'] ?? ''),
            'updated_at' => $result->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Pending suggestions of an extraction (see ExtractLeadDetails::pending()).
     *
     * @return ?array{id: int, created_at: ?string, preferred_time: ?string, summary: ?string, suggestions: list<array{attribute: string, label: string, value: mixed, current: mixed}>}
     */
    public static function suggestions(?AIResult $result): ?array
    {
        if (! $result) {
            return null;
        }

        $suggestions = [];

        foreach ($result->output['suggestions'] ?? [] as $attribute => $suggestion) {
            if (($suggestion['status'] ?? null) === ExtractLeadDetails::PENDING) {
                $suggestions[] = [
                    'attribute' => $attribute,
                    'label' => self::FIELD_LABELS[$attribute] ?? $attribute,
                    'value' => $suggestion['value'],
                    'current' => $suggestion['current'] ?? null,
                ];
            }
        }

        return [
            'id' => $result->id,
            'created_at' => $result->created_at?->toIso8601String(),
            'preferred_time' => $result->output['preferred_time'] ?? null,
            'summary' => $result->output['summary'] ?? null,
            'suggestions' => $suggestions,
        ];
    }
}
