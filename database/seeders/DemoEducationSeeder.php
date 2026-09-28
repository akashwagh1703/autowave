<?php

namespace Database\Seeders;

use App\Domain\Education\Actions\AdmitStudent;
use App\Domain\Education\Actions\ChangeDemoStatus;
use App\Domain\Education\Actions\SaveBatch;
use App\Domain\Education\Actions\SaveCourse;
use App\Domain\Education\Actions\ScheduleDemo;
use App\Domain\Education\Actions\TakeAttendance;
use App\Domain\Education\Enums\DemoStatus;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Lead\Actions\CreateLead;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo courses, batches, students, fees, attendance and demo classes for ABC Coaching,
 * created through the domain actions. Runs once (skips if the tenant already has courses).
 */
class DemoEducationSeeder extends Seeder
{
    public function run(
        TenantContext $context,
        SaveCourse $saveCourse,
        SaveBatch $saveBatch,
        AdmitStudent $admit,
        TakeAttendance $attendance,
        CreateLead $createLead,
        ScheduleDemo $scheduleDemo,
        ChangeDemoStatus $changeDemo,
    ): void {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoEducationSeeder must not run in production.');
        }

        $tenant = Tenant::query()->where('slug', 'abc-coaching')->first();

        if (! $tenant) {
            return;
        }

        $context->run($tenant, function (Tenant $tenant) use ($saveCourse, $saveBatch, $admit, $attendance, $createLead, $scheduleDemo, $changeDemo) {
            if (Course::withTrashed()->exists()) {
                return;
            }

            $owner = User::query()->where('email', 'owner@abc-coaching.test')->first();
            $teacher = TenantUser::query()->where('tenant_id', $tenant->id)
                ->whereHas('user', fn ($query) => $query->where('email', 'teacher@abc-coaching.test'))
                ->value('id');
            $today = TenantTime::now()->startOfDay();

            $class10 = $saveCourse->handle(['name' => 'Class 10 Maths & Science', 'description' => 'Full board syllabus with weekly tests and doubt sessions.', 'fee' => 36000, 'duration_label' => '10 months']);
            $class12 = $saveCourse->handle(['name' => 'Class 12 Physics', 'description' => 'Board and entrance preparation, with practicals revision.', 'fee' => 42000, 'duration_label' => '11 months']);
            $saveCourse->handle(['name' => 'JEE Foundation (Class 9)', 'description' => 'Builds problem-solving for competitive exams.', 'fee' => 30000, 'duration_label' => '1 year']);

            $morning = $saveBatch->handle([
                'course_id' => $class10->id, 'name' => 'Morning batch', 'weekdays' => [1, 3, 5], 'start_time' => '07:00', 'end_time' => '09:00',
                'capacity' => 20, 'teacher_tenant_user_id' => $teacher, 'room' => 'Room 1', 'starts_on' => $today->copy()->subMonths(2)->toDateString(),
            ]);
            $evening = $saveBatch->handle([
                'course_id' => $class10->id, 'name' => 'Evening batch', 'weekdays' => [2, 4, 6], 'start_time' => '17:30', 'end_time' => '19:30',
                'capacity' => 4, 'room' => 'Room 2', 'starts_on' => $today->copy()->subMonth()->toDateString(),
            ]);
            $physics = $saveBatch->handle([
                'course_id' => $class12->id, 'name' => 'Weekend batch', 'weekdays' => [6, 7], 'start_time' => '10:00', 'end_time' => '13:00',
                'capacity' => 15, 'teacher_tenant_user_id' => $teacher, 'fee' => 40000, 'starts_on' => $today->copy()->addDays(10)->toDateString(),
            ]);

            $twoMonthsAgo = $today->copy()->subMonths(2)->toDateString();

            // Three instalments starting two months ago: the first paid, the second overdue.
            $aarya = $admit->handle([
                'customer' => ['name' => 'Aarya Kulkarni', 'phone' => '97000 10001'],
                'batch_id' => $morning->id, 'enrolled_on' => $twoMonthsAgo, 'instalment_count' => 3, 'first_due_on' => $twoMonthsAgo,
                'payment' => ['amount' => 12000, 'method' => 'upi', 'reference' => 'UPI-EDU-1'],
            ], $owner);
            $admit->handle([
                'customer' => ['name' => 'Vihaan Joshi', 'phone' => '97000 10002'],
                'batch_id' => $morning->id, 'enrolled_on' => $twoMonthsAgo, 'discount' => 6000, 'instalment_count' => 1, 'first_due_on' => $twoMonthsAgo,
                'payment' => ['amount' => 30000, 'method' => 'bank_transfer'],
            ], $owner);
            $admit->handle([
                'customer' => ['name' => 'Sara Deshpande', 'phone' => '97000 10003'],
                'batch_id' => $morning->id, 'enrolled_on' => $today->copy()->subMonth()->toDateString(), 'instalment_count' => 2, 'first_due_on' => $today->copy()->addDays(2)->toDateString(),
            ], $owner);
            $admit->handle([
                'customer' => ['name' => 'Kabir Patil', 'phone' => '97000 10004'],
                'batch_id' => $evening->id, 'instalment_count' => 4, 'first_due_on' => $today->toDateString(),
                'payment' => ['amount' => 9000, 'method' => 'cash'],
            ], $owner);

            $this->attendance($attendance, $morning, $aarya->id, $owner);

            // Enquiries: a new one, one with a demo tomorrow, and one who attended a demo and was admitted.
            $createLead->handle(['name' => 'Rhea Shah', 'phone' => '97000 20001', 'interest' => 'Class 10 Maths & Science'], $owner);

            $diya = $createLead->handle(['name' => 'Diya Menon', 'phone' => '97000 20002', 'interest' => 'Class 12 Physics'], $owner);
            $scheduleDemo->handle($diya, [
                'scheduled_at' => $today->copy()->addDay()->setTime(10, 0)->utc(),
                'course_id' => $class12->id,
                'batch_id' => $physics->id,
                'notes' => 'Parent will join.',
            ], $owner);

            $ishaan = $createLead->handle(['name' => 'Ishaan Rao', 'phone' => '97000 20003', 'interest' => 'Class 10, evening timing'], $owner);
            $demo = $scheduleDemo->handle($ishaan, ['scheduled_at' => CarbonImmutable::now()->subHours(3), 'batch_id' => $evening->id], $owner);
            $changeDemo->handle($demo, DemoStatus::Attended, $owner);
            $admit->handle(['lead_id' => $ishaan->id, 'batch_id' => $evening->id, 'instalment_count' => 2], $owner);
        });
    }

    /** Attendance for the batch's last three class days (the first student absent once). */
    private function attendance(TakeAttendance $attendance, Batch $batch, int $absentEnrolmentId, ?User $owner): void
    {
        $enrolments = $batch->activeEnrolments()->pluck('id')->all();
        $day = TenantTime::now()->startOfDay();
        $taken = 0;

        for ($i = 0; $i < 21 && $taken < 3; $i++, $day = $day->subDay()) {
            if (! in_array($day->isoWeekday(), $batch->weekdays ?? [], true)) {
                continue;
            }

            $marks = array_fill_keys($enrolments, 'present');

            if ($taken === 1) {
                $marks[$absentEnrolmentId] = 'absent';
            }

            $attendance->handle($batch, $day->toDateString(), $marks, ['Quadratic equations', 'Light: reflection', 'Weekly test'][$taken], $owner);
            $taken++;
        }
    }
}
