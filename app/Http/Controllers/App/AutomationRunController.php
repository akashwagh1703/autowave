<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Automation\Actions\ControlAutomationRun;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Services\MessagingService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AutomationPresenter;
use App\Http\Presenters\CrmPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutomationRunController extends Controller
{
    public function __construct(private readonly AutomationPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $statuses = array_map(fn (RunStatus $status) => $status->value, RunStatus::cases());

        $filters = [
            'status' => in_array($request->query('status'), $statuses, true) ? $request->query('status') : null,
            'automation' => ctype_digit((string) $request->query('automation')) ? (int) $request->query('automation') : null,
        ];

        $runs = AutomationRun::query()
            ->with('automation')
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['automation'], fn ($query, int $id) => $query->where('automation_id', $id))
            ->latest('id')
            ->paginate(config('automation.per_page'))
            ->withQueryString();

        return Inertia::render('business/automations/Runs', [
            'runs' => [
                ...CrmPresenter::paginated($runs, fn ($run) => $run),
                'data' => $this->presenter->runs($runs->items()),
            ],
            'filters' => $filters,
            'statuses' => array_map(fn (RunStatus $status) => ['value' => $status->value, 'label' => $status->label()], RunStatus::cases()),
            'automations' => Automation::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at'])
                ->map(fn (Automation $automation) => ['id' => $automation->id, 'name' => $automation->name, 'deleted' => $automation->trashed()])
                ->all(),
        ]);
    }

    public function show(AutomationRun $run): Response
    {
        return Inertia::render('business/automations/RunShow', [
            'run' => $this->presenter->runDetail($run),
        ]);
    }

    public function retry(Request $request, AutomationRun $run, ControlAutomationRun $control): RedirectResponse
    {
        $control->retry($run, $request->user());

        return back()->with('success', __('Run retried.'));
    }

    public function cancel(Request $request, AutomationRun $run, ControlAutomationRun $control): RedirectResponse
    {
        $control->cancel($run, $request->user());

        return back()->with('success', __('Run cancelled.'));
    }

    public function retryMessage(OutboundMessage $message, MessagingService $messaging, AuditLogger $audit): RedirectResponse
    {
        if (! $messaging->retry($message)) {
            return back()->with('error', __('Only failed messages can be sent again.'));
        }

        $audit->log('automation.message_retried', $message, ['channel' => $message->channel, 'automation_run_id' => $message->automation_run_id]);

        return back()->with('success', __('Message queued again.'));
    }
}
