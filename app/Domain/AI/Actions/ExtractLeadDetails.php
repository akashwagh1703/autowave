<?php

namespace App\Domain\AI\Actions;

use App\Domain\Activity\Models\Activity;
use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Services\AIService;
use App\Domain\Lead\Actions\UpdateLead;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Support\MessagingCompliance;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reads what a lead wrote (inbound WhatsApp/Instagram messages, website enquiries) and fills the lead's
 * empty details (ADR-019). Differing values for fields that already have one become suggestions staff
 * apply or dismiss. Rules first: e-mail addresses are found without AI, and too little text is never
 * sent to AI. The same input is read only once (the result is keyed by the latest message ids).
 */
class ExtractLeadDetails
{
    /** Extracted key → lead attribute. */
    public const FIELDS = ['name' => 'name', 'email' => 'email', 'interest' => 'interest', 'budget' => 'estimated_value'];

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const DISMISSED = 'dismissed';

    public function __construct(
        private readonly AIService $ai,
        private readonly UpdateLead $updateLead,
    ) {}

    /**
     * @param  string  $via  manual | auto | automation
     * @return ?AIResult null when the lead has written nothing yet
     */
    public function handle(Lead $lead, ?User $actor = null, string $via = 'manual', ?string $key = null): ?AIResult
    {
        [$text, $marker, $length] = $this->source($lead);

        if ($text === '') {
            return null;
        }

        $key ??= "lead:{$lead->id}:{$marker}";

        if ($existing = AIResult::query()->where('feature', AIService::EXTRACTION)->where('key', $key)->first()) {
            return $existing;
        }

        preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $email);
        $usedAi = $length >= (int) config('ai.extraction.min_chars');

        $fields = $usedAi
            ? $this->ai->extractLeadDetails($text, $actor)
            : ['name' => null, 'email' => null, 'interest' => null, 'budget' => null, 'preferred_time' => null, 'summary' => null];
        $fields['email'] ??= isset($email[0]) && filter_var($email[0], FILTER_VALIDATE_EMAIL) ? strtolower($email[0]) : null;

        [$fill, $suggestions] = $this->compare($lead, $fields);
        $filled = [];

        if ($fill !== [] && ! $lead->trashed()) {
            try {
                $this->updateLead->handle($lead, $fill, $actor, ['via' => 'ai']);
                $filled = $fill;
            } catch (ValidationException) {
                $suggestions = [...$suggestions, ...array_map(fn ($value) => ['value' => $value, 'current' => null, 'status' => self::PENDING], $fill)];
            }
        }

        $result = new AIResult([
            'feature' => AIService::EXTRACTION,
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'key' => $key,
            'status' => $suggestions === [] ? AIResult::USED : AIResult::READY,
            'created_by_user_id' => $actor?->id,
            'output' => [
                'filled' => $filled,
                'suggestions' => $suggestions,
                'preferred_time' => $fields['preferred_time'],
                'summary' => $fields['summary'],
                'via' => $via,
                'used_ai' => $usedAi,
            ],
        ]);

        try {
            // Savepoint: a lost race on the unique key must not abort an enclosing transaction.
            DB::transaction(fn () => $result->save());
        } catch (UniqueConstraintViolationException) {
            return AIResult::query()->where('feature', AIService::EXTRACTION)->where('key', $key)->firstOrFail();
        }

        return $result;
    }

    /** The latest extraction with suggestions still worth showing (values that still differ). */
    public static function pending(Lead $lead): ?AIResult
    {
        $result = AIResult::query()->for(AIService::EXTRACTION, 'lead', $lead->id)->where('status', AIResult::READY)->latest('id')->first();

        if (! $result) {
            return null;
        }

        $open = array_filter($result->output['suggestions'] ?? [], fn (array $suggestion, string $attribute) => ($suggestion['status'] ?? null) === self::PENDING
            && ! self::same($attribute, $suggestion['value'], $lead->getAttribute($attribute)), ARRAY_FILTER_USE_BOTH);

        return $open === [] ? null : $result->setAttribute('output', [...$result->output, 'suggestions' => $open]);
    }

    /** A lead name that is only a phone number or the "WhatsApp user …1234" placeholder counts as empty. */
    public static function isPlaceholderName(Lead $lead): bool
    {
        $name = trim((string) $lead->name);

        return $name === ''
            || preg_match('/^\+?[\d\s()-]{6,}$/', $name) === 1
            || preg_match('/^\S+ user …\S+$/u', $name) === 1
            || ($lead->phone !== null && $name === trim($lead->phone));
    }

    public static function same(string $attribute, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $attribute === 'estimated_value'
            ? abs((float) $a - (float) $b) < 0.005
            : mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{0: array<string, mixed>, 1: array<string, array{value: mixed, current: mixed, status: string}>}
     */
    private function compare(Lead $lead, array $fields): array
    {
        $fill = [];
        $suggestions = [];

        foreach (self::FIELDS as $key => $attribute) {
            $value = $fields[$key] ?? null;

            if ($value === null) {
                continue;
            }

            $current = $lead->getAttribute($attribute);
            $empty = $attribute === 'name' ? self::isPlaceholderName($lead) : ($current === null || trim((string) $current) === '');

            if ($empty) {
                $fill[$attribute] = $value;
            } elseif (! self::same($attribute, $value, $current)) {
                $suggestions[$attribute] = ['value' => $value, 'current' => $attribute === 'estimated_value' ? (float) $current : $current, 'status' => self::PENDING];
            }
        }

        return [$fill, $suggestions];
    }

    /**
     * Inbound text of the lead's conversations and its website enquiries, oldest first, and a marker of
     * the newest message and enquiry read, and the number of characters the contact wrote.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function source(Lead $lead): array
    {
        $messages = ConversationMessage::query()
            ->whereIn('conversation_id', fn ($query) => $query->select('id')->from('conversations')->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id))
            ->where('direction', ConversationMessage::INBOUND)
            ->where('type', 'text')
            ->whereNotNull('body')
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->limit((int) config('ai.context.messages'))
            ->get(['id', 'body', 'sent_at']);

        $enquiries = Activity::query()
            ->where('lead_id', $lead->id)
            ->where('type', 'website_enquiry')
            ->whereNotNull('body')
            ->latest('id')
            ->limit(5)
            ->get(['id', 'body', 'occurred_at']);

        $entries = collect()
            ->merge($messages->map(fn (ConversationMessage $message) => ['at' => $message->sent_at, 'label' => '', 'text' => trim((string) $message->body)]))
            ->merge($enquiries->map(fn (Activity $activity) => ['at' => $activity->occurred_at, 'label' => 'Website enquiry: ', 'text' => trim((string) $activity->body)]))
            ->filter(fn (array $entry) => $entry['text'] !== '' && MessagingCompliance::keyword($entry['text']) === null)
            ->sortBy('at');

        $lines = $entries
            ->map(fn (array $entry) => '- '.$entry['label'].Str::limit(preg_replace('/\s+/', ' ', $entry['text']) ?? '', (int) config('ai.context.message_chars')))
            ->implode("\n");
        $length = (int) $entries->sum(fn (array $entry) => mb_strlen(preg_replace('/\s+/', '', $entry['text']) ?? ''));

        return [trim($lines), 'm'.((int) $messages->max('id')).'e'.((int) $enquiries->max('id')), $length];
    }
}
