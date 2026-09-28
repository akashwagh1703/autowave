<?php

namespace App\Http\Controllers\App;

use App\Domain\Education\Actions\DeleteBatch;
use App\Domain\Education\Actions\SaveBatch;
use App\Domain\Education\Actions\TakeAttendance;
use App\Domain\Education\Enums\AttendanceStatus;
use App\Domain\Education\Models\AttendanceRecord;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\ClassSession;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\EducationPresenter;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Batches: create/edit forms, the batch page (students and attendance) and saving attendance. */
class BatchController extends Controller
{
    public const RECENT_SESSIONS = 12;

    public function __construct(private readonly TenantContext $context) {}

    public function create(Request $request): Response
    {
        return Inertia::render('business/batches/Create', [
            ...$this->formOptions(),
            'defaultCourseId' => $request->integer('course') ?: null,
        ]);
    }

    public function store(Request $request, SaveBatch $saveBatch): RedirectResponse
    {
        $batch = $saveBatch->handle($this->validated($request));

        return to_route('batches.show', $batch)->with('success', __('Batch :name added.', ['name' => $batch->name]));
    }

    public function show(Request $request, Batch $batch): Response
    {
        $batch->load(['course', 'teacher.user'])->loadCount('activeEnrolments');

        $today = TenantTime::now()->toDateString();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) && $request->query('date') <= $today
            ? (string) $request->query('date')
            : $today;

        $students = $batch->enrolments()
            ->active()
            ->with('customer')
            ->withCount([
                'attendance as sessions_marked',
                'attendance as sessions_attended' => fn ($query) => $query->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late]),
            ])
            ->get()
            ->sortBy(fn (Enrolment $enrolment) => mb_strtolower((string) $enrolment->customer?->name))
            ->values();

        $session = ClassSession::query()->where('batch_id', $batch->id)->whereDate('held_on', $date)->first();
        $marks = $session
            ? AttendanceRecord::query()->where('class_session_id', $session->id)->pluck('status', 'enrolment_id')->map(fn ($status) => $status instanceof AttendanceStatus ? $status->value : $status)
            : collect();

        return Inertia::render('business/batches/Show', [
            'batch' => EducationPresenter::batch($batch),
            'students' => $students->map(fn (Enrolment $enrolment) => [
                'id' => $enrolment->id,
                'customer' => $enrolment->customer ? ['id' => $enrolment->customer->id, 'name' => $enrolment->customer->name, 'phone' => $enrolment->customer->phone] : null,
                'enrolled_on' => $enrolment->enrolled_on?->toDateString(),
                'balance' => $enrolment->balance(),
                'attendance_rate' => $enrolment->sessions_marked > 0 ? (int) round($enrolment->sessions_attended * 100 / $enrolment->sessions_marked) : null,
                'mark' => $marks[$enrolment->id] ?? null,
            ]),
            'attendance' => [
                'date' => $date,
                'today' => $today,
                'meets' => $batch->meetsOn(CarbonImmutable::parse($date)),
                'topic' => $session?->topic,
                'saved' => (bool) $session,
            ],
            'sessions' => ClassSession::query()
                ->where('batch_id', $batch->id)
                ->withCount(['records', 'records as present_count' => fn ($query) => $query->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])])
                ->orderByDesc('held_on')
                ->limit(self::RECENT_SESSIONS)
                ->get()
                ->map(fn (ClassSession $session) => EducationPresenter::session($session)),
            'attendanceStatuses' => EducationPresenter::attendanceStatuses(),
        ]);
    }

    public function edit(Batch $batch): Response
    {
        $batch->load('course')->loadCount('activeEnrolments');

        return Inertia::render('business/batches/Edit', [
            ...$this->formOptions(),
            'batch' => EducationPresenter::batch($batch),
        ]);
    }

    public function update(Request $request, Batch $batch, SaveBatch $saveBatch): RedirectResponse
    {
        $saveBatch->handle($this->validated($request), $batch);

        return to_route('batches.show', $batch)->with('success', __('Batch updated.'));
    }

    public function destroy(Batch $batch, DeleteBatch $deleteBatch): RedirectResponse
    {
        $deleteBatch->handle($batch);

        return to_route('courses.index')->with('success', __('Batch deleted.'));
    }

    public function attendance(Request $request, Batch $batch, TakeAttendance $takeAttendance): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'topic' => ['nullable', 'string', 'max:200'],
            'marks' => ['required', 'array', 'min:1', 'max:500'],
            'marks.*' => ['required', Rule::enum(AttendanceStatus::class)],
        ], ['marks.required' => __('Mark at least one student.')]);

        $takeAttendance->handle($batch, $validated['date'], $validated['marks'], $validated['topic'] ?? null, $request->user());

        return to_route('batches.show', [$batch, 'date' => $validated['date']])->with('success', __('Attendance saved.'));
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'courses' => Course::query()->active()->ordered()->get()
                ->map(fn (Course $course) => ['id' => $course->id, 'name' => $course->name, 'fee' => $course->fee]),
            'teachers' => TenantUser::query()
                ->where('tenant_id', $this->context->id())
                ->where('status', MembershipStatus::Active)
                ->with('user:id,name')
                ->get()
                ->map(fn (TenantUser $member) => ['id' => $member->id, 'name' => $member->user?->name])
                ->sortBy('name')->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->merge(['name' => Str::squish((string) $request->input('name'))]);

        return $request->validate([
            'course_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'teacher_tenant_user_id' => ['nullable', 'integer'],
            'room' => ['nullable', 'string', 'max:80'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
