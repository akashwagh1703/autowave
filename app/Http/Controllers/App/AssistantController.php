<?php

namespace App\Http\Controllers\App;

use App\Domain\AI\Services\AIService;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The business assistant (ADR-019): questions about the business answered from read-only data the
 * user may see, and marketing writing help. Chat history lives in the browser only.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $canAsk = $request->user()->can('ai.assistant');

        return Inertia::render('business/assistant/Index', [
            'canAsk' => $canAsk,
            'examples' => $canAsk ? $this->examples($request->user()) : [],
            'copyKinds' => collect(config('ai.copy_kinds'))
                ->filter(fn (array $kind) => $kind['marketing'] ?? false)
                ->map(fn (array $kind, string $key) => ['key' => $key, 'label' => $kind['label'], 'description' => $kind['description'] ?? null, 'max' => $kind['max']])
                ->values()->all(),
            'limits' => [
                'question' => (int) config('ai.context.assistant_chars'),
                'turns' => (int) config('ai.context.assistant_turns'),
                'instructions' => (int) config('ai.instructions_max'),
            ],
        ]);
    }

    public function ask(Request $request, AIService $ai): JsonResponse
    {
        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.((int) config('ai.context.assistant_turns') * 2)],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:4000'],
        ]);

        $turns = array_map(fn (array $turn) => ['role' => $turn['role'], 'content' => trim($turn['content'])], $validated['messages']);

        if (end($turns)['role'] !== 'user' || mb_strlen(end($turns)['content']) > (int) config('ai.context.assistant_chars')) {
            return response()->json(['message' => __('Ask a question of up to :max characters.', ['max' => config('ai.context.assistant_chars')])], 422);
        }

        return response()->json($ai->ask($turns, $request->user()));
    }

    /** @return list<string> */
    private function examples(User $user): array
    {
        $examples = [__('How is the business doing this week?')];

        if ($this->context->hasEngine('booking') && $user->can('appointments.view')) {
            $examples[] = __('How many appointments do we have tomorrow?');
        }

        if ($this->context->hasModule('leads') && $user->can('leads.view')) {
            $examples[] = __('Which new leads came in the last 7 days?');
        }

        if ($this->context->hasEngine('commerce') && $user->can('orders.view')) {
            $examples[] = __('What were our sales yesterday?');
        }

        return $examples;
    }
}
