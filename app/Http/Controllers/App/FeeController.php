<?php

namespace App\Http\Controllers\App;

use App\Domain\Education\Models\FeeInstalment;
use App\Domain\Education\Support\EducationSettings;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CrmPresenter;
use App\Http\Presenters\EducationPresenter;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Outstanding fee instalments of active students: overdue, due soon, or all unpaid. */
class FeeController extends Controller
{
    public const VIEWS = ['overdue', 'upcoming', 'all'];

    public function index(Request $request, EducationSettings $settings): Response
    {
        $filters = [
            'view' => in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'overdue',
            'search' => Str::limit(trim($request->string('search')->toString()), 100, '') ?: null,
        ];

        $today = TenantTime::now()->toDateString();
        $soon = TenantTime::now()->addDays(max(7, $settings->reminderDaysBefore()))->toDateString();

        $query = FeeInstalment::query()->outstanding()->with(['enrolment.customer', 'enrolment.batch.course']);

        match ($filters['view']) {
            'overdue' => $query->whereDate('due_on', '<', $today),
            'upcoming' => $query->whereDate('due_on', '>=', $today)->whereDate('due_on', '<=', $soon),
            default => null,
        };

        if ($filters['search']) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->whereHas('enrolment.customer', fn (Builder $customer) => $customer->withTrashed()
                ->where(fn (Builder $c) => $c->where('name', 'ilike', $like)->orWhere('phone', 'ilike', $like)));
        }

        $instalments = $query->orderBy('due_on')->orderBy('id')->paginate(config('education.per_page'))->withQueryString();

        $totals = fn (Builder $scope) => (string) ($scope->selectRaw('coalesce(sum(amount - amount_paid), 0) as due')->value('due') ?? '0');

        return Inertia::render('business/fees/Index', [
            'instalments' => CrmPresenter::paginated($instalments, fn (FeeInstalment $instalment) => [
                ...EducationPresenter::instalment($instalment),
                'enrolment' => $instalment->enrolment ? [
                    'id' => $instalment->enrolment->id,
                    'customer' => $instalment->enrolment->customer
                        ? ['id' => $instalment->enrolment->customer->id, 'name' => $instalment->enrolment->customer->name, 'phone' => $instalment->enrolment->customer->phone]
                        : null,
                    'batch' => $instalment->enrolment->batch
                        ? ['id' => $instalment->enrolment->batch->id, 'name' => $instalment->enrolment->batch->name, 'course' => $instalment->enrolment->batch->course?->name]
                        : null,
                ] : null,
            ]),
            'filters' => $filters,
            'summary' => [
                'overdue' => $totals(FeeInstalment::query()->outstanding()->whereDate('due_on', '<', $today)),
                'upcoming' => $totals(FeeInstalment::query()->outstanding()->whereDate('due_on', '>=', $today)->whereDate('due_on', '<=', $soon)),
                'all' => $totals(FeeInstalment::query()->outstanding()),
                'overdue_count' => FeeInstalment::query()->outstanding()->whereDate('due_on', '<', $today)->count(),
            ],
        ]);
    }
}
