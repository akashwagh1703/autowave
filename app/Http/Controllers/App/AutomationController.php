<?php

namespace App\Http\Controllers\App;

use App\Domain\Automation\Actions\DeleteAutomation;
use App\Domain\Automation\Actions\SaveAutomation;
use App\Domain\Automation\Actions\ToggleAutomation;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Support\AutomationCatalog;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AutomationPresenter;
use App\Http\Presenters\CrmPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public const STATUSES = ['all', 'active', 'paused'];

    /** Builder fields accepted from the request; DefinitionValidator validates them. */
    private const DEFINITION_FIELDS = ['name', 'description', 'trigger', 'is_active', 'once_per_subject', 'steps'];

    public function __construct(
        private readonly AutomationPresenter $presenter,
        private readonly AutomationCatalog $catalog,
    ) {}

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'all';

        $automations = Automation::query()
            ->with('nodes')
            ->withCount([
                'runs',
                'runs as active_runs_count' => fn (Builder $query) => $query->whereIn('status', RunStatus::IN_PROGRESS),
                'runs as failed_runs_count' => fn (Builder $query) => $query->where('status', RunStatus::Failed),
            ])
            ->withMax('runs', 'created_at')
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'paused', fn (Builder $query) => $query->where('is_active', false))
            ->orderByDesc('is_active')->orderBy('name')->orderBy('id')
            ->get();

        $since = now()->subDays(7);

        return Inertia::render('business/automations/Index', [
            'automations' => $automations->map(fn (Automation $automation) => $this->presenter->automation($automation))->all(),
            'filters' => ['status' => $status],
            'counts' => [
                'all' => Automation::query()->count(),
                'active' => Automation::query()->where('is_active', true)->count(),
            ],
            'stats' => [
                'runs_7d' => AutomationRun::query()->where('created_at', '>=', $since)->count(),
                'completed_7d' => AutomationRun::query()->where('created_at', '>=', $since)->where('status', RunStatus::Completed)->count(),
                'in_progress' => AutomationRun::query()->whereIn('status', RunStatus::IN_PROGRESS)->count(),
                'failed_7d' => AutomationRun::query()->where('created_at', '>=', $since)->where('status', RunStatus::Failed)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('business/automations/Create', [
            'catalog' => $this->catalog->forBuilder(),
        ]);
    }

    public function store(Request $request, SaveAutomation $save): RedirectResponse
    {
        $automation = $save->handle($request->only(self::DEFINITION_FIELDS), actor: $request->user());

        return to_route('automations.show', $automation)->with('success', __(':name created.', ['name' => $automation->name]));
    }

    public function show(Request $request, Automation $automation): Response
    {
        $automation->load('nodes')->loadCount([
            'runs',
            'runs as active_runs_count' => fn (Builder $query) => $query->whereIn('status', RunStatus::IN_PROGRESS),
            'runs as failed_runs_count' => fn (Builder $query) => $query->where('status', RunStatus::Failed),
        ]);

        $runs = $automation->runs()->with('automation')->latest('id')->paginate(config('automation.per_page'))->withQueryString();

        return Inertia::render('business/automations/Show', [
            'automation' => $this->presenter->automation($automation),
            'runs' => [
                ...CrmPresenter::paginated($runs, fn ($run) => $run),
                'data' => $this->presenter->runs($runs->items()),
            ],
        ]);
    }

    public function edit(Automation $automation): Response
    {
        $automation->load('nodes');

        return Inertia::render('business/automations/Edit', [
            'automation' => $this->presenter->automation($automation),
            'definition' => $this->presenter->definition($automation),
            'catalog' => $this->catalog->forBuilder(),
        ]);
    }

    public function update(Request $request, Automation $automation, SaveAutomation $save): RedirectResponse
    {
        $save->handle($request->only(self::DEFINITION_FIELDS), $automation, $request->user());

        return to_route('automations.show', $automation)->with('success', __('Automation saved. Runs already in progress keep their original steps.'));
    }

    public function toggle(Request $request, Automation $automation, ToggleAutomation $toggle): RedirectResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        if ($validated['is_active'] && ! $this->catalog->trigger($automation->trigger)) {
            return back()->with('error', __('This automation’s trigger is not available for your business.'));
        }

        try {
            $toggle->handle($automation, (bool) $validated['is_active']);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->first());
        }

        return back()->with('success', $automation->is_active ? __(':name is on.', ['name' => $automation->name]) : __(':name is paused.', ['name' => $automation->name]));
    }

    public function destroy(Automation $automation, DeleteAutomation $delete): RedirectResponse
    {
        $delete->handle($automation);

        return to_route('automations.index')->with('success', __('Automation deleted. Its run history is kept.'));
    }
}
