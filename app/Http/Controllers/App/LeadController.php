<?php

namespace App\Http\Controllers\App;

use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Models\DemoClass;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Lead\Actions\CreateLead;
use App\Domain\Lead\Actions\DeleteLead;
use App\Domain\Lead\Actions\UpdateLead;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AiPresenter;
use App\Http\Presenters\CrmOptions;
use App\Http\Presenters\CrmPresenter;
use App\Http\Presenters\EducationPresenter;
use App\Http\Requests\Crm\LeadRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public const VIEWS = ['open', 'followup', 'closed', 'all'];

    public const SORTS = ['newest', 'oldest', 'name', 'followup', 'value'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CrmOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $myMembershipId = $this->membershipId($request);

        $leads = $this->filteredQuery($filters, $myMembershipId)
            ->with(['stage', 'source', 'assignee.user:id,name'])
            ->paginate(config('crm.per_page'))
            ->withQueryString();

        return Inertia::render('business/leads/Index', [
            'leads' => CrmPresenter::paginated($leads, fn (Lead $lead) => CrmPresenter::lead($lead)),
            'filters' => $filters,
            'counts' => [
                'by_stage' => Lead::query()->groupBy('lead_stage_id')->selectRaw('lead_stage_id, count(*) as aggregate')->pluck('aggregate', 'lead_stage_id'),
                'followup_due' => Lead::query()->followUpDue()->count(),
                'mine' => $myMembershipId ? Lead::query()->open()->where('assigned_tenant_user_id', $myMembershipId)->count() : 0,
            ],
            'stages' => $this->options->stages(),
            'sources' => $this->options->sources(),
            'members' => $this->options->members(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('business/leads/Create', [
            'stages' => collect($this->options->stages())->where('outcome', StageOutcome::Open->value)->values(),
            'sources' => $this->options->sources(),
            'members' => $this->options->members(),
            'defaultSourceId' => LeadSource::query()->active()->where('code', config('crm.default_source'))->value('id'),
        ]);
    }

    public function store(LeadRequest $request, CreateLead $createLead): RedirectResponse
    {
        $lead = $createLead->handle($request->leadData(), $request->user());

        return to_route('leads.show', $lead)->with('success', __('Lead added.'));
    }

    public function show(Request $request, Lead $lead): Response
    {
        $lead->load(['stage', 'source', 'assignee.user:id,name', 'customer']);

        return Inertia::render('business/leads/Show', [
            'lead' => CrmPresenter::lead($lead),
            'ai' => AiPresenter::record($lead, $request->user()),
            'activities' => $lead->activities()
                ->with('user:id,name')
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn ($activity) => CrmPresenter::activity($activity)),
            'stages' => $this->options->stages($lead->lead_stage_id),
            'members' => $this->options->members(),
            'activityTypes' => $this->options->activityTypes(),
            'education' => $this->education($request, $lead),
        ]);
    }

    /**
     * Demo classes and admissions for the lead page (coaching tenants).
     *
     * @return array<string, mixed>|null
     */
    private function education(Request $request, Lead $lead): ?array
    {
        if (! $this->context->hasEngine('education') || ! $request->user()->can('students.view')) {
            return null;
        }

        return [
            'demos' => DemoClass::query()->where('lead_id', $lead->id)->with(['course', 'batch'])
                ->orderByDesc('scheduled_at')->limit(20)->get()
                ->map(fn (DemoClass $demo) => EducationPresenter::demo($demo)),
            'enrolments' => Enrolment::query()->where('lead_id', $lead->id)->with('batch.course')->latest('id')->get()
                ->map(fn (Enrolment $enrolment) => [
                    'id' => $enrolment->id,
                    'status' => $enrolment->status->value,
                    'status_label' => $enrolment->status->label(),
                    'batch' => $enrolment->batch ? ($enrolment->batch->course?->name ? $enrolment->batch->course->name.' · ' : '').$enrolment->batch->name : null,
                ]),
            'courses' => Course::query()->active()->ordered()
                ->with(['batches' => fn ($query) => $query->active()->orderBy('name')])
                ->get()
                ->map(fn (Course $course) => [
                    'id' => $course->id,
                    'name' => $course->name,
                    'batches' => $course->batches->map(fn (Batch $batch) => ['id' => $batch->id, 'name' => $batch->name])->values(),
                ]),
        ];
    }

    public function edit(Lead $lead): Response
    {
        $lead->load(['stage', 'source']);

        return Inertia::render('business/leads/Edit', [
            'lead' => CrmPresenter::lead($lead),
            'sources' => $this->options->sources($lead->lead_source_id),
        ]);
    }

    public function update(LeadRequest $request, Lead $lead, UpdateLead $updateLead): RedirectResponse
    {
        $updateLead->handle($lead, $request->leadData(), $request->user());

        return to_route('leads.show', $lead)->with('success', __('Lead updated.'));
    }

    public function destroy(Lead $lead, DeleteLead $deleteLead): RedirectResponse
    {
        $deleteLead->handle($lead);

        return to_route('leads.index')->with('success', __('Lead deleted.'));
    }

    /**
     * Unknown filter values are dropped rather than rejected, so a stale bookmark still loads.
     *
     * @return array{search: ?string, stage: ?int, source: ?int, assignee: ?string, view: string, sort: string}
     */
    private function filters(Request $request): array
    {
        $assignee = $request->string('assignee')->toString();

        return [
            'search' => Str::limit(trim($request->string('search')->toString()), 100, '') ?: null,
            'stage' => $request->integer('stage') ?: null,
            'source' => $request->integer('source') ?: null,
            'assignee' => in_array($assignee, ['me', 'unassigned'], true) || ctype_digit($assignee) ? $assignee : null,
            'view' => in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'open',
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'newest',
        ];
    }

    private function filteredQuery(array $filters, ?int $myMembershipId): Builder
    {
        $query = Lead::query();

        if ($filters['search']) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $digits = preg_replace('/\D/', '', $filters['search']);

            $query->where(function (Builder $query) use ($like, $digits) {
                $query->where('name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhere('interest', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $query->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            });
        }

        // An explicit stage wins over the open/closed view.
        if ($filters['stage']) {
            $query->where('lead_stage_id', $filters['stage']);
        } else {
            match ($filters['view']) {
                'open' => $query->open(),
                'followup' => $query->followUpDue(),
                'closed' => $query->whereHas('stage', fn (Builder $stage) => $stage->whereIn('outcome', [StageOutcome::Won, StageOutcome::Lost])),
                default => null,
            };
        }

        $query->when($filters['source'], fn (Builder $q, int $source) => $q->where('lead_source_id', $source));

        match (true) {
            $filters['assignee'] === 'me' => $query->where('assigned_tenant_user_id', $myMembershipId ?? 0),
            $filters['assignee'] === 'unassigned' => $query->whereNull('assigned_tenant_user_id'),
            $filters['assignee'] !== null => $query->where('assigned_tenant_user_id', (int) $filters['assignee']),
            default => null,
        };

        match ($filters['sort']) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            'followup' => $query->orderByRaw('next_followup_at asc nulls last')->orderBy('id'),
            'value' => $query->orderByRaw('estimated_value desc nulls last')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return $query;
    }

    private function membershipId(Request $request): ?int
    {
        return TenantUser::query()
            ->where('tenant_id', $this->context->tenant()->id)
            ->where('user_id', $request->user()->id)
            ->value('id');
    }
}
