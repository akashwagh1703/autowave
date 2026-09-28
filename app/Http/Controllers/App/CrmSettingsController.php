<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Lead\Actions\UpdatePipeline;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CrmPresenter;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CrmSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): Response
    {
        $leadCounts = Lead::query()->groupBy('lead_stage_id')->selectRaw('lead_stage_id, count(*) as aggregate')->pluck('aggregate', 'lead_stage_id');

        return Inertia::render('business/settings/Crm', [
            'stages' => LeadStage::query()->ordered()->get()->map(fn (LeadStage $stage) => [
                ...CrmPresenter::stage($stage),
                'leads_count' => (int) ($leadCounts[$stage->id] ?? 0),
            ]),
            'sources' => LeadSource::query()->ordered()->get()->map(fn (LeadSource $source) => CrmPresenter::source($source)),
            'outcomes' => collect(StageOutcome::cases())->map(fn (StageOutcome $outcome) => ['value' => $outcome->value, 'label' => $outcome->label()]),
            'autoAssign' => (bool) ($this->context->setting('crm', [])['auto_assign'] ?? false),
        ]);
    }

    public function updateStages(Request $request, UpdatePipeline $pipeline): RedirectResponse
    {
        $request->merge(['stages' => $this->squishNames($request->input('stages'))]);

        $validated = $request->validate([
            'stages' => ['required', 'array', 'min:3', 'max:20'],
            'stages.*.id' => ['nullable', 'integer'],
            'stages.*.name' => ['required', 'string', 'min:2', 'max:60'],
            'stages.*.color' => ['required', 'string', 'regex:'.StoreBusinessRequest::COLOR_PATTERN],
            'stages.*.outcome' => ['required', Rule::enum(StageOutcome::class)],
            'stages.*.is_active' => ['required', 'boolean'],
        ], [
            'stages.*.color.regex' => __('Use a hex colour such as #4f46e5.'),
        ]);

        $pipeline->stages(array_map(fn (array $row) => [...$row, 'is_active' => (bool) $row['is_active']], $validated['stages']));

        return back()->with('success', __('Pipeline stages saved.'));
    }

    public function updateSources(Request $request, UpdatePipeline $pipeline): RedirectResponse
    {
        $request->merge(['sources' => $this->squishNames($request->input('sources'))]);

        $validated = $request->validate([
            'sources' => ['required', 'array', 'min:1', 'max:30'],
            'sources.*.id' => ['nullable', 'integer'],
            'sources.*.name' => ['required', 'string', 'min:2', 'max:60'],
            'sources.*.is_active' => ['required', 'boolean'],
        ]);

        $pipeline->sources(array_map(fn (array $row) => [...$row, 'is_active' => (bool) $row['is_active']], $validated['sources']));

        return back()->with('success', __('Lead sources saved.'));
    }

    public function updateAssignment(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['auto_assign' => ['required', 'boolean']]);

        $current = $this->context->setting('crm', []);
        TenantSetting::query()->updateOrCreate(['key' => 'crm'], ['value' => [...$current, 'auto_assign' => (bool) $validated['auto_assign']]]);
        $audit->log('crm.assignment_updated', null, ['auto_assign' => (bool) $validated['auto_assign']]);

        return back()->with('success', __('Assignment settings saved.'));
    }

    private function squishNames(mixed $rows): mixed
    {
        if (! is_array($rows)) {
            return $rows;
        }

        return array_map(fn ($row) => is_array($row) && isset($row['name']) && is_string($row['name'])
            ? [...$row, 'name' => Str::squish($row['name'])]
            : $row, $rows);
    }
}
