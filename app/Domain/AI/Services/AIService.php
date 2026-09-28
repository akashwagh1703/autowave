<?php

namespace App\Domain\AI\Services;

use App\Domain\Activity\Models\Activity;
use App\Domain\AI\Assistant\AssistantTools;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Support\AISettings;
use App\Domain\AI\Support\BusinessFacts;
use App\Domain\AI\Support\PromptLibrary;
use App\Domain\AI\Support\Transcript;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * AI use cases (ADR-008, ADR-019). The only class business code calls for AI. Builds grounded prompts,
 * goes through AIGateway (availability, cap, metering) and cleans the output. AI output is a draft or a
 * suggestion; callers decide what to do with it.
 */
class AIService
{
    public const SUMMARY = 'summary';

    public const REPLY_DRAFT = 'reply_draft';

    public const EXTRACTION = 'extraction';

    public function __construct(
        private readonly AIGateway $gateway,
        private readonly PromptLibrary $prompts,
        private readonly BusinessFacts $facts,
        private readonly AISettings $settings,
        private readonly Transcript $transcript,
        private readonly AssistantTools $tools,
        private readonly TenantContext $context,
    ) {}

    /**
     * A reply draft for the conversation. With a draft, the draft is improved instead.
     */
    public function suggestReply(Conversation $conversation, ?string $draft = null, ?User $user = null, ?string $instructions = null): string
    {
        $this->gateway->ensureAvailable();

        $system = $this->prompts->render('reply', [
            'business_name' => $this->facts->name(),
            'channel' => config("messaging.channels.{$conversation->channel}.label", $conversation->channel),
            'tone' => config('ai.tones.'.$this->settings->all()['tone']),
            'facts' => $this->facts->text(),
        ]);

        $task = filled($draft)
            ? "Improve this draft reply, keeping its meaning and language:\n".Str::limit(trim($draft), 2000)
            : 'Write the next reply from the business to the customer.';

        if (filled($instructions)) {
            $task .= "\nExtra instructions from the staff member: ".Str::limit(trim($instructions), (int) config('ai.instructions_max'));
        }

        $response = $this->gateway->chat(ChatRequest::for('reply', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Customer name: {$conversation->displayName()}\n\nConversation:\n".($this->transcript->conversation($conversation) ?: '(no messages yet)')."\n\n{$task}"],
        ]), $user);

        return $this->clean($response->text(), (int) config('messaging.inbox.reply_max'));
    }

    /**
     * A reply draft kept for the inbox (made by an automation). Only a person can send it. The key makes
     * it idempotent: the same key never costs a second AI call.
     */
    public function draftReply(Conversation $conversation, string $key, ?string $instructions = null): AIResult
    {
        if ($existing = AIResult::query()->where('feature', self::REPLY_DRAFT)->where('key', $key)->first()) {
            return $existing;
        }

        $result = new AIResult([
            'feature' => self::REPLY_DRAFT,
            'subject_type' => 'conversation',
            'subject_id' => $conversation->id,
            'key' => $key,
            'status' => AIResult::READY,
            'output' => ['text' => $this->suggestReply($conversation, null, null, $instructions)],
        ]);

        try {
            DB::transaction(fn () => $result->save());
        } catch (UniqueConstraintViolationException) {
            return AIResult::query()->where('feature', self::REPLY_DRAFT)->where('key', $key)->firstOrFail();
        }

        return $result;
    }

    /** The newest draft still waiting in the conversation: none once the business has replied since it was made. */
    public static function pendingDraft(Conversation $conversation): ?AIResult
    {
        $draft = AIResult::query()->for(self::REPLY_DRAFT, 'conversation', $conversation->id)->where('status', AIResult::READY)->latest('id')->first();

        if (! $draft || $conversation->messages()->where('direction', ConversationMessage::OUTBOUND)->where('created_at', '>=', $draft->created_at)->exists()) {
            return null;
        }

        return $draft;
    }

    /**
     * The conversation's summary, reused until a new message arrives (or regenerated on request).
     */
    public function summarizeConversation(Conversation $conversation, ?User $user = null, bool $refresh = false): AIResult
    {
        $lastId = (int) $conversation->messages()->max('id');
        $key = "conversation:{$conversation->id}:m{$lastId}";

        return $this->summary('conversation', $conversation->id, $key, $user, $refresh, function () use ($conversation, $lastId) {
            if ($lastId === 0) {
                return null;
            }

            return "Contact: {$conversation->displayName()} on ".config("messaging.channels.{$conversation->channel}.label", $conversation->channel)
                ."\n\nConversation:\n".$this->transcript->conversation($conversation);
        });
    }

    /**
     * A lead's or customer's summary from its details and recent timeline, reused until the timeline changes.
     */
    public function summarizeRecord(Lead|Customer $record, ?User $user = null, bool $refresh = false): AIResult
    {
        $type = $record instanceof Lead ? 'lead' : 'customer';
        $activities = Activity::query()
            ->where($type === 'lead' ? 'lead_id' : 'customer_id', $record->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit((int) config('ai.context.activities'))
            ->get();
        $key = "{$type}:{$record->id}:a".((int) $activities->max('id'));

        return $this->summary($type, $record->id, $key, $user, $refresh, function () use ($record, $activities) {
            if ($activities->isEmpty()) {
                return null;
            }

            return $this->recordHeader($record)."\n\nTimeline:\n".$this->transcript->timeline($activities);
        });
    }

    /**
     * Lead details from what a contact wrote. Values are validated and normalised; unknown ones are null.
     *
     * @return array{name: ?string, email: ?string, interest: ?string, budget: ?float, preferred_time: ?string, summary: ?string}
     */
    public function extractLeadDetails(string $text, ?User $user = null): array
    {
        $response = $this->gateway->chat(ChatRequest::for('extraction', [
            ['role' => 'system', 'content' => $this->prompts->render('extraction', [
                'business_name' => $this->facts->name(),
                'business_type' => $this->facts->type(),
                'catalogue' => $this->facts->catalogue(),
            ])],
            ['role' => 'user', 'content' => "Messages:\n".Str::limit($text, 6000)],
        ], json: true), $user);

        $data = $response->json();
        $string = fn (string $key, int $max) => is_string($data[$key] ?? null) && trim($data[$key]) !== '' && ! in_array(strtolower(trim($data[$key])), ['null', 'none', 'n/a', 'unknown'], true)
            ? Str::limit(trim($data[$key]), $max, '')
            : null;
        $email = $string('email', 191);
        $budget = $data['budget'] ?? null;
        $budget = is_string($budget) ? preg_replace('/[^\d.]/', '', $budget) : $budget;

        return [
            'name' => $string('name', 150),
            'email' => $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null,
            'interest' => $string('interest', 191),
            'budget' => is_numeric($budget) && (float) $budget > 0 && (float) $budget < 100_000_000 ? round((float) $budget, 2) : null,
            'preferred_time' => $string('preferred_time', 100),
            'summary' => $string('summary', 300),
        ];
    }

    /**
     * Writing help (config/ai.php `copy_kinds`).
     *
     * @param  array{section?: ?string, field?: ?string, current?: ?string, trigger?: ?string, channel?: ?string, placeholders?: list<string>}  $context
     */
    public function generateCopy(string $kind, array $context, ?string $instructions, ?User $user = null): string
    {
        $definition = config("ai.copy_kinds.{$kind}") ?? throw new InvalidArgumentException("Unknown copy kind [{$kind}].");
        $max = (int) $definition['max'];
        $placeholders = $context['placeholders'] ?? [];

        $system = $this->prompts->render('copy', [
            'business_name' => $this->facts->name(),
            'business_type' => $this->facts->type(),
            'tone' => config('ai.tones.'.$this->settings->all()['tone']),
            'max' => $max,
            'placeholders' => $placeholders !== [] ? implode(', ', array_map(fn (string $key) => '{{'.$key.'}}', $placeholders)) : 'none',
            'facts' => $this->facts->text(),
        ]);

        $task = match ($kind) {
            'website_field' => 'Write the "'.($context['field'] ?? 'text').'" of the "'.($context['section'] ?? 'website').'" section of the business website.',
            'automation_message' => 'Write a '.($context['channel'] ?? 'WhatsApp').' message sent automatically when: '.($context['trigger'] ?? 'an event happens').'.',
            default => 'Write: '.$definition['description'],
        };

        $prompt = implode("\n", array_filter([
            $task,
            filled($context['current'] ?? null) ? 'Current text (improve or replace it): '.Str::limit((string) $context['current'], 2000) : null,
            'Instructions: '.(filled($instructions) ? Str::limit(trim($instructions), (int) config('ai.instructions_max')) : 'none'),
        ]));

        $response = $this->gateway->chat(ChatRequest::for('copy', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ]), $user);

        return $this->clean($response->text(), $max);
    }

    /**
     * The business assistant. `turns` are the recent chat turns from the browser, oldest first, ending
     * with the user's question. The model may call read-only tools a few times before answering.
     *
     * @param  list<array{role: string, content: string}>  $turns
     * @return array{reply: string, tools: list<string>}
     */
    public function ask(array $turns, User $user): array
    {
        $this->gateway->ensureAvailable();

        $tenant = $this->context->tenant();
        $labels = $this->context->hasEngine('booking') ? app(BookingSettings::class)->resourceLabels() : null;
        $system = $this->prompts->render('assistant', [
            'business_name' => $this->facts->name(),
            'business_type' => $this->facts->type(),
            'user_name' => $user->name,
            'today' => TenantTime::now()->format('l, j F Y, g:i A'),
            'timezone' => $tenant->timezone,
            'currency' => $tenant->currency,
            'resource_label' => $labels['plural'] ?? 'Staff',
            'facts' => $this->facts->text(),
        ]);

        $messages = [['role' => 'system', 'content' => $system]];

        foreach (array_slice($turns, -((int) config('ai.context.assistant_turns'))) as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => Str::limit($turn['content'], (int) config('ai.context.assistant_chars'))];
        }

        $request = ChatRequest::for('assistant', $messages, tools: $this->tools->definitions($user));
        $used = [];

        for ($round = 0; $round <= (int) config('ai.context.assistant_tool_rounds'); $round++) {
            $lastRound = $round === (int) config('ai.context.assistant_tool_rounds');
            $response = $this->gateway->chat($lastRound ? $request->withMessages($messages)->withoutTools() : $request->withMessages($messages), $user);

            if ($response->toolCalls === [] || $lastRound) {
                return ['reply' => $this->clean($response->text(), 4000) ?: __('Sorry, I could not find an answer to that.'), 'tools' => array_values(array_unique($used))];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => array_map(fn (array $call) => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => json_encode((object) $call['arguments'])],
                ], $response->toolCalls),
            ];

            foreach ($response->toolCalls as $call) {
                $used[] = $call['name'];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($this->tools->call($call['name'], $call['arguments'], $user), JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return ['reply' => __('Sorry, I could not find an answer to that.'), 'tools' => array_values(array_unique($used))];
    }

    /**
     * @param  callable(): ?string  $input  the prompt input, or null when there is nothing to summarise
     */
    private function summary(string $subjectType, int $subjectId, string $key, ?User $user, bool $refresh, callable $input): AIResult
    {
        $existing = AIResult::query()->where('feature', self::SUMMARY)->where('key', $key)->first();

        if ($existing && ! $refresh) {
            return $existing;
        }

        $text = $input();

        if ($text === null) {
            $summary = __('Nothing to summarise yet.');
        } else {
            $response = $this->gateway->chat(ChatRequest::for('summary', [
                ['role' => 'system', 'content' => $this->prompts->render('summary', ['business_name' => $this->facts->name()])],
                ['role' => 'user', 'content' => $text],
            ]), $user);
            $summary = $this->clean($response->text(), 1500);
        }

        $result = $existing ?? new AIResult(['feature' => self::SUMMARY, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'key' => $key]);
        $result->fill(['output' => ['text' => $summary], 'created_by_user_id' => $user?->id, 'status' => AIResult::READY]);
        $result->updated_at = now();

        try {
            // Savepoint: a lost race on the unique key must not abort an enclosing transaction.
            DB::transaction(fn () => $result->save());
        } catch (UniqueConstraintViolationException) {
            // Someone else summarised the same state a moment ago.
            return AIResult::query()->where('feature', self::SUMMARY)->where('key', $key)->firstOrFail();
        }

        return $result;
    }

    private function recordHeader(Lead|Customer $record): string
    {
        if ($record instanceof Lead) {
            $record->loadMissing(['stage:id,name', 'source:id,name']);

            return implode("\n", array_filter([
                "Lead: {$record->name}",
                'Stage: '.($record->stage?->name ?? '—'),
                $record->source ? "Source: {$record->source->name}" : null,
                $record->interest ? "Interest: {$record->interest}" : null,
                $record->estimated_value !== null ? 'Estimated value: '.$record->estimated_value : null,
                'Lead since: '.$record->created_at?->setTimezone(TenantTime::timezone())->format('j M Y'),
            ]));
        }

        return implode("\n", array_filter([
            "Customer: {$record->name}",
            $record->city ? "City: {$record->city}" : null,
            $record->tags ? 'Tags: '.implode(', ', (array) $record->tags) : null,
            'Customer since: '.$record->created_at?->setTimezone(TenantTime::timezone())->format('j M Y'),
        ]));
    }

    /** Trims quotes and labels models sometimes add, and enforces the length limit. */
    private function clean(string $text, int $max): string
    {
        $text = trim($text);

        for ($pass = 0; $pass < 2; $pass++) {
            $text = preg_replace('/^(reply|message|draft|text)\s*:\s*/i', '', $text) ?? $text;

            if (preg_match('/^(["“])(.*)(["”])$/su', $text, $match)) {
                $text = trim($match[2]);
            }
        }

        return Str::limit($text, $max, '');
    }
}
