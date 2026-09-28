<?php

namespace App\Http\Controllers\App;

use App\Domain\Education\Actions\DeleteCourse;
use App\Domain\Education\Actions\SaveCourse;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Http\Controllers\Controller;
use App\Http\Presenters\EducationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Courses and their batches on one page; courses are edited in dialogs. */
class CourseController extends Controller
{
    public function index(Request $request): Response
    {
        $showInactive = $request->boolean('inactive');

        $courses = Course::query()
            ->ordered()
            ->when(! $showInactive, fn ($query) => $query->active())
            ->with(['batches' => fn ($query) => $query
                ->when(! $showInactive, fn ($batches) => $batches->active())
                ->with('teacher.user')
                ->withCount('activeEnrolments')
                ->orderBy('name')])
            ->get();

        return Inertia::render('business/courses/Index', [
            'courses' => $courses->map(fn (Course $course) => [
                ...EducationPresenter::course($course),
                'batches' => $course->batches->map(fn (Batch $batch) => EducationPresenter::batch($batch))->values()->all(),
            ]),
            'showInactive' => $showInactive,
            'counts' => [
                'courses' => Course::query()->active()->count(),
                'batches' => Batch::query()->active()->count(),
            ],
        ]);
    }

    public function store(Request $request, SaveCourse $saveCourse): RedirectResponse
    {
        $course = $saveCourse->handle($this->validated($request));

        return back()->with('success', __('Course :name added.', ['name' => $course->name]));
    }

    public function update(Request $request, Course $course, SaveCourse $saveCourse): RedirectResponse
    {
        $saveCourse->handle($this->validated($request), $course);

        return back()->with('success', __('Course updated.'));
    }

    public function destroy(Course $course, DeleteCourse $deleteCourse): RedirectResponse
    {
        $deleteCourse->handle($course);

        return back()->with('success', __('Course deleted.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->merge(['name' => Str::squish((string) $request->input('name'))]);

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'duration_label' => ['nullable', 'string', 'max:60'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
