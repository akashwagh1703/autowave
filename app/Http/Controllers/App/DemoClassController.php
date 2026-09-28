<?php

namespace App\Http\Controllers\App;

use App\Domain\Education\Actions\ChangeDemoStatus;
use App\Domain\Education\Actions\ScheduleDemo;
use App\Domain\Education\Enums\DemoStatus;
use App\Domain\Education\Models\DemoClass;
use App\Domain\Lead\Models\Lead;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CrmPresenter;
use App\Http\Presenters\EducationPresenter;
use App\Support\TenantTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Demo (trial) classes for enquiries: the upcoming/past list, scheduling from a lead, and outcomes. */
class DemoClassController extends Controller
{
    public const VIEWS = ['upcoming', 'past', 'all'];

    public function index(Request $request): Response
    {
        $view = in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'upcoming';
        $startOfToday = TenantTime::now()->startOfDay()->utc();

        $query = DemoClass::query()->with(['lead', 'course', 'batch']);

        match ($view) {
            'upcoming' => $query->where('scheduled_at', '>=', $startOfToday)->orderBy('scheduled_at'),
            'past' => $query->where('scheduled_at', '<', $startOfToday)->orderByDesc('scheduled_at'),
            default => $query->orderByDesc('scheduled_at'),
        };

        $demos = $query->orderBy('id')->paginate(config('education.per_page'))->withQueryString();

        return Inertia::render('business/demos/Index', [
            'demos' => CrmPresenter::paginated($demos, fn (DemoClass $demo) => EducationPresenter::demo($demo)),
            'filters' => ['view' => $view],
            'counts' => [
                'today' => DemoClass::query()->where('status', DemoStatus::Scheduled)
                    ->whereBetween('scheduled_at', [$startOfToday, $startOfToday->copy()->addDay()])->count(),
                'awaiting' => DemoClass::query()->where('status', DemoStatus::Scheduled)->where('scheduled_at', '<', now())->count(),
            ],
        ]);
    }

    public function store(Request $request, Lead $lead, ScheduleDemo $scheduleDemo): RedirectResponse
    {
        $validated = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'course_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['scheduled_at.required' => __('Choose the date and time of the demo class.')]);

        $scheduleDemo->handle($lead, [...$validated, 'scheduled_at' => TenantTime::parse($validated['scheduled_at'])], $request->user());

        return back()->with('success', __('Demo class scheduled for :name.', ['name' => $lead->name]));
    }

    public function status(Request $request, DemoClass $demo, ChangeDemoStatus $changeStatus): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([DemoStatus::Attended->value, DemoStatus::NoShow->value, DemoStatus::Cancelled->value])],
        ]);

        $status = DemoStatus::from($validated['status']);
        $changeStatus->handle($demo, $status, $request->user());

        return back()->with('success', match ($status) {
            DemoStatus::Attended => __('Marked as attended.'),
            DemoStatus::NoShow => __('Marked as a no-show.'),
            default => __('Demo class cancelled.'),
        });
    }
}
