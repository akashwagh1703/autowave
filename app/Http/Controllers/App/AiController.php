<?php

namespace App\Http\Controllers\App;

use App\Domain\AI\Actions\ExtractLeadDetails;
use App\Domain\AI\Actions\ReviewLeadSuggestions;
use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Services\AIService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Models\Conversation;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * AI helpers in the business app (ADR-019). Everything here returns a draft or a suggestion; nothing is
 * sent to a customer from this controller.
 */
class AiController extends Controller
{
    public function __construct(private readonly AIService $ai) {}

    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'draft' => ['nullable', 'string', 'max:'.config('messaging.inbox.reply_max')],
            'instructions' => ['nullable', 'string', 'max:'.config('ai.instructions_max')],
        ]);

        return response()->json([
            'text' => $this->ai->suggestReply($conversation, $validated['draft'] ?? null, $request->user(), $validated['instructions'] ?? null),
        ]);
    }

    public function dismissDraft(Conversation $conversation, int $draft): JsonResponse
    {
        AIResult::query()->for(AIService::REPLY_DRAFT, 'conversation', $conversation->id)->whereKey($draft)
            ->where('status', AIResult::READY)
            ->update(['status' => AIResult::DISMISSED, 'updated_at' => now()]);

        return response()->json(['dismissed' => true]);
    }

    public function conversationSummary(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->summary($this->ai->summarizeConversation($conversation, $request->user(), $request->boolean('refresh')));
    }

    public function leadSummary(Request $request, Lead $lead): JsonResponse
    {
        return $this->summary($this->ai->summarizeRecord($lead, $request->user(), $request->boolean('refresh')));
    }

    public function customerSummary(Request $request, Customer $customer): JsonResponse
    {
        return $this->summary($this->ai->summarizeRecord($customer, $request->user(), $request->boolean('refresh')));
    }

    public function extract(Request $request, Lead $lead, ExtractLeadDetails $extract): RedirectResponse
    {
        $result = $extract->handle($lead, $request->user());

        if (! $result) {
            return back()->with('error', __('This lead has not written anything to read yet.'));
        }

        $filled = array_keys($result->output['filled'] ?? []);
        $suggested = ExtractLeadDetails::pending($lead->refresh());

        return back()->with('success', match (true) {
            $filled !== [] => __('Filled in: :fields.', ['fields' => implode(', ', array_map(fn (string $attribute) => mb_strtolower(AiPresenter::FIELD_LABELS[$attribute] ?? $attribute), $filled))]),
            $suggested !== null => __('AI found details that differ from the lead. Review them below.'),
            default => __('No new details found.'),
        });
    }

    public function applySuggestions(Request $request, Lead $lead, ReviewLeadSuggestions $review): RedirectResponse
    {
        [$result, $attributes] = $this->suggestionInput($request, $lead);
        $applied = $review->apply($lead, $result, $attributes, $request->user());

        return back()->with('success', $applied === [] ? __('Nothing to apply.') : __('Lead updated.'));
    }

    public function dismissSuggestions(Request $request, Lead $lead, ReviewLeadSuggestions $review): RedirectResponse
    {
        [$result, $attributes] = $this->suggestionInput($request, $lead);
        $review->dismiss($lead, $result, $attributes);

        return back()->with('success', __('Suggestions dismissed.'));
    }

    public function write(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(array_keys(config('ai.copy_kinds')))],
            'instructions' => ['nullable', 'string', 'max:'.config('ai.instructions_max')],
            'context' => ['nullable', 'array'],
            'context.section' => ['nullable', 'string', 'max:100'],
            'context.field' => ['nullable', 'string', 'max:100'],
            'context.current' => ['nullable', 'string', 'max:4000'],
            'context.trigger' => ['nullable', 'string', 'max:150'],
            'context.channel' => ['nullable', 'string', 'max:50'],
            'context.placeholders' => ['nullable', 'array', 'max:40'],
            'context.placeholders.*' => ['string', 'max:60', 'regex:/^[a-z_]+\.[a-z_]+$/'],
        ]);

        $permissions = Arr::wrap(config("ai.copy_kinds.{$validated['kind']}.permission"));

        abort_if($permissions !== [] && ! collect($permissions)->contains(fn (string $permission) => $request->user()->can($permission)), 403);

        $context = Arr::only($validated['context'] ?? [], ['section', 'field', 'current', 'trigger', 'channel', 'placeholders']);

        return response()->json([
            'text' => $this->ai->generateCopy($validated['kind'], $context, $validated['instructions'] ?? null, $request->user()),
        ]);
    }

    private function summary(AIResult $result): JsonResponse
    {
        return response()->json(['summary' => AiPresenter::summary($result)]);
    }

    /** @return array{0: AIResult, 1: list<string>} */
    private function suggestionInput(Request $request, Lead $lead): array
    {
        $validated = $request->validate([
            'result_id' => ['required', 'integer'],
            'attributes' => ['required', 'array', 'min:1'],
            'attributes.*' => ['string', Rule::in(array_values(ExtractLeadDetails::FIELDS))],
        ]);

        $result = AIResult::query()->for(AIService::EXTRACTION, 'lead', $lead->id)->findOrFail($validated['result_id']);

        return [$result, array_values(array_unique($validated['attributes']))];
    }
}
