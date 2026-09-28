<?php

namespace App\Http\Controllers\App;

use App\Domain\Activity\Models\Activity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\CustomerLookup;
use App\Domain\Education\Actions\AdmitStudent;
use App\Domain\Education\Actions\ChangeEnrolmentStatus;
use App\Domain\Education\Actions\RecordFeePayment;
use App\Domain\Education\Actions\RemoveFeePayment;
use App\Domain\Education\Actions\UpdateFeePlan;
use App\Domain\Education\Enums\EnrolmentStatus;
use App\Domain\Education\Models\AttendanceRecord;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeePayment;
use App\Domain\Education\Support\EducationSettings;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CommercePresenter;
use App\Http\Presenters\CrmPresenter;
use App\Http\Presenters\EducationPresenter;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Students are enrolments: a customer admitted to a batch, with a fee plan, payments and attendance. */
class StudentController extends Controller
{
    public const STATUSES = ['active', 'completed', 'dropped', 'all'];

    public const RECENT_ATTENDANCE = 30;

    public function index(Request $request): Response
    {
        $filters = [
            'search' => Str::limit(trim($request->string('search')->toString()), 100, '') ?: null,
            'status' => in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'active',
            'course' => ctype_digit((string) $request->query('course')) ? (int) $request->query('course') : null,
            'batch' => ctype_digit((string) $request->query('batch')) ? (int) $request->query('batch') : null,
            'dues' => $request->boolean('dues'),
        ];

        $query = Enrolment::query()->with(['customer', 'batch.course', 'instalments']);

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $query->when($filters['batch'], fn (Builder $q, int $batch) => $q->where('batch_id', $batch))
            ->when($filters['course'], fn (Builder $q, int $course) => $q->whereHas('batch', fn (Builder $b) => $b->withTrashed()->where('course_id', $course)))
            ->when($filters['dues'], fn (Builder $q) => $q->whereRaw('amount_paid < fee_total - discount'));

        if ($filters['search']) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $digits = preg_replace('/\D/', '', $filters['search']);

            $query->whereHas('customer', fn (Builder $customer) => $customer->withTrashed()->where(function (Builder $c) use ($like, $digits) {
                $c->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('phone', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $c->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            }));
        }

        $enrolments = $query->orderByDesc('enrolled_on')->orderByDesc('id')->paginate(config('education.per_page'))->withQueryString();

        return Inertia::render('business/students/Index', [
            'students' => CrmPresenter::paginated($enrolments, fn (Enrolment $enrolment) => EducationPresenter::enrolment($enrolment)),
            'filters' => $filters,
            'counts' => [
                'active' => Enrolment::query()->active()->count(),
                'with_dues' => Enrolment::query()->active()->whereRaw('amount_paid < fee_total - discount')->count(),
            ],
            'courses' => Course::query()->ordered()->get(['id', 'name']),
            'batches' => Batch::query()->with('course')->orderBy('name')->get()
                ->map(fn (Batch $batch) => ['id' => $batch->id, 'course_id' => $batch->course_id, 'label' => ($batch->course?->name ? $batch->course->name.' · ' : '').$batch->name]),
        ]);
    }

    public function create(Request $request, EducationSettings $settings): Response
    {
        $lead = $request->integer('lead') ? Lead::query()->with(['stage', 'customer'])->find($request->integer('lead')) : null;
        $customer = $request->integer('customer') ? Customer::query()->find($request->integer('customer')) : null;

        return Inertia::render('business/students/Create', [
            'batches' => Batch::query()->active()
                ->whereHas('course', fn (Builder $course) => $course->active())
                ->with('course')->withCount('activeEnrolments')
                ->orderBy('name')->get()
                ->sortBy(fn (Batch $batch) => $batch->course?->name.' '.$batch->name)->values()
                ->map(fn (Batch $batch) => EducationPresenter::batch($batch)),
            'lead' => $lead && $lead->stage->outcome !== StageOutcome::Lost
                ? ['id' => $lead->id, 'name' => $lead->name, 'phone' => $lead->phone, 'email' => $lead->email, 'stage' => $lead->stage->name]
                : null,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email] : null,
            'defaultBatchId' => $request->integer('batch') ?: null,
            'defaultInstalments' => $settings->defaultInstalments(),
            'maxInstalments' => (int) config('education.max_instalments'),
            'today' => TenantTime::now()->toDateString(),
            'paymentMethods' => CommercePresenter::paymentMethods(),
        ]);
    }

    public function store(Request $request, AdmitStudent $admit): RedirectResponse
    {
        $validated = $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['nullable', 'string', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:190'],
            'batch_id' => ['required', 'integer'],
            'enrolled_on' => ['nullable', 'date_format:Y-m-d'],
            'fee_total' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'instalment_count' => ['nullable', 'integer', 'min:1', 'max:'.config('education.max_instalments')],
            'first_due_on' => ['nullable', 'date_format:Y-m-d'],
            'instalments' => ['nullable', 'array', 'max:'.config('education.max_instalments')],
            'instalments.*.due_on' => ['required', 'date_format:Y-m-d'],
            'instalments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payment.method' => ['nullable', 'required_with:payment.amount', Rule::in(array_keys(config('commerce.payment_methods')))],
            'payment.reference' => ['nullable', 'string', 'max:100'],
        ], ['batch_id.required' => __('Choose a batch.')]);

        if (empty($validated['payment']['amount']) || (float) $validated['payment']['amount'] <= 0) {
            unset($validated['payment']);
        }

        $enrolment = $admit->handle($validated, $request->user());

        return to_route('students.show', $enrolment)->with('success', __(':name admitted to :batch.', [
            'name' => $enrolment->customer->name,
            'batch' => $enrolment->batch->name,
        ]));
    }

    public function show(Enrolment $enrolment): Response
    {
        $enrolment->load(['customer', 'batch.course', 'batch.teacher.user', 'instalments', 'payments.recorder:id,name', 'creator:id,name']);

        $attendance = AttendanceRecord::query()
            ->where('enrolment_id', $enrolment->id)
            ->join('class_sessions', 'class_sessions.id', '=', 'attendance_records.class_session_id')
            ->orderByDesc('class_sessions.held_on')
            ->limit(self::RECENT_ATTENDANCE)
            ->get(['attendance_records.status', 'class_sessions.held_on', 'class_sessions.topic']);

        $totals = AttendanceRecord::query()->where('enrolment_id', $enrolment->id)
            ->selectRaw("count(*) as marked, count(*) filter (where status in ('present', 'late')) as attended")
            ->first();

        return Inertia::render('business/students/Show', [
            'enrolment' => EducationPresenter::enrolment($enrolment),
            'attendance' => [
                'marked' => (int) $totals->marked,
                'attended' => (int) $totals->attended,
                'rate' => $totals->marked > 0 ? (int) round($totals->attended * 100 / $totals->marked) : null,
                'recent' => $attendance->map(fn (AttendanceRecord $record) => [
                    'held_on' => substr((string) $record->held_on, 0, 10),
                    'topic' => $record->topic,
                    'status' => $record->status->value,
                    'status_label' => $record->status->label(),
                ]),
            ],
            'activities' => Activity::query()
                ->where('customer_id', $enrolment->customer_id)
                ->whereRaw("metadata->>'enrolment_id' = ?", [(string) $enrolment->id])
                ->with('user:id,name')
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (Activity $activity) => CrmPresenter::activity($activity)),
            'paymentMethods' => CommercePresenter::paymentMethods(),
            'statuses' => EducationPresenter::enrolmentStatuses(),
            'maxInstalments' => (int) config('education.max_instalments'),
        ]);
    }

    public function update(Request $request, Enrolment $enrolment): RedirectResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $enrolment->update(['notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null]);

        return back()->with('success', __('Notes saved.'));
    }

    public function status(Request $request, Enrolment $enrolment, ChangeEnrolmentStatus $changeStatus): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(EnrolmentStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = EnrolmentStatus::from($validated['status']);
        $changeStatus->handle($enrolment, $status, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', match ($status) {
            EnrolmentStatus::Active => __('Student re-activated.'),
            EnrolmentStatus::Completed => __('Course marked as completed.'),
            EnrolmentStatus::Dropped => __('Student marked as dropped.'),
        });
    }

    public function fees(Request $request, Enrolment $enrolment, UpdateFeePlan $updatePlan): RedirectResponse
    {
        $validated = $request->validate([
            'fee_total' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'instalments' => ['present', 'array', 'max:'.config('education.max_instalments')],
            'instalments.*.due_on' => ['required', 'date_format:Y-m-d'],
            'instalments.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $updatePlan->handle($enrolment, $validated);

        return back()->with('success', __('Fee plan updated.'));
    }

    public function storePayment(Request $request, Enrolment $enrolment, RecordFeePayment $recordPayment): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'method' => ['required', Rule::in(array_keys(config('commerce.payment_methods')))],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
        ], ['method.required' => __('Choose how the fee was paid.')]);

        $paidAt = filled($validated['paid_at'] ?? null) ? TenantTime::parse($validated['paid_at']) : null;

        if ($paidAt?->isFuture()) {
            throw ValidationException::withMessages(['paid_at' => __('The payment date cannot be in the future.')]);
        }

        $recordPayment->handle($enrolment, [...$validated, 'paid_at' => $paidAt], $request->user());

        return back()->with('success', __('Fee payment recorded.'));
    }

    public function destroyPayment(Request $request, Enrolment $enrolment, FeePayment $payment, RemoveFeePayment $removePayment): RedirectResponse
    {
        $removePayment->handle($enrolment, $payment, $request->user());

        return back()->with('success', __('Payment removed.'));
    }

    /** Student lookup for the admission form (JSON): existing customers by name, phone or email. */
    public function lookup(Request $request): JsonResponse
    {
        return response()->json(['data' => CustomerLookup::search($request->string('search')->toString())]);
    }
}
