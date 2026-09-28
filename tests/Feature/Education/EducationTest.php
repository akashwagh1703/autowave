<?php

namespace Tests\Feature\Education;

use App\Domain\Activity\Models\Activity;
use App\Domain\Education\Enums\AttendanceStatus;
use App\Domain\Education\Enums\DemoStatus;
use App\Domain\Education\Enums\EnrolmentStatus;
use App\Domain\Education\Models\AttendanceRecord;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\ClassSession;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Models\DemoClass;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeeInstalment;
use App\Domain\Education\Models\FeePayment;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesEducationRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Coaching (education engine, ADR-020): courses, batches, admissions, fees, attendance and demo classes. */
class EducationTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesEducationRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 5 October 2026, 10:00 in India.
        $this->travelToBookingDay();
    }

    public function test_the_owner_manages_courses_and_batches(): void
    {
        $tenant = $this->createCoaching();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/courses'), ['name' => 'Class 10 Maths', 'fee' => 12000, 'duration_label' => '10 months', 'is_active' => true])->assertSessionHasNoErrors();
        $this->post($this->appUrl('/courses'), ['name' => 'class 10 maths', 'is_active' => true])->assertSessionHasErrors('name');
        $course = $this->inTenant($tenant, fn () => Course::query()->sole());

        $this->post($this->appUrl('/batches'), [
            'course_id' => $course->id, 'name' => 'Evening', 'weekdays' => [5, 1, 3], 'start_time' => '17:00', 'end_time' => '18:30', 'capacity' => 1, 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->post($this->appUrl('/batches'), ['course_id' => $course->id, 'name' => 'Broken', 'start_time' => '18:00', 'end_time' => '17:00', 'is_active' => true])
            ->assertSessionHasErrors('end_time');

        $batch = $this->inTenant($tenant, fn () => Batch::query()->sole());
        $this->assertSame([[1, 3, 5], '12000.00'], [$batch->weekdays, $batch->effectiveFee()]);

        $this->admit($tenant, $batch);
        $this->put($this->appUrl("/batches/{$batch->id}"), ['course_id' => $course->id, 'name' => 'Evening', 'capacity' => 1, 'is_active' => true])->assertSessionHasNoErrors();
        $this->delete($this->appUrl("/batches/{$batch->id}"))->assertSessionHasErrors('batch');
        $this->delete($this->appUrl("/courses/{$course->id}"))->assertSessionHasErrors('course');

        $this->get($this->appUrl('/courses'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('business/courses/Index'));
        $this->get($this->appUrl("/batches/{$batch->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->component('business/batches/Show'));
    }

    public function test_admitting_a_new_student_splits_the_fee_and_records_the_first_payment(): void
    {
        $tenant = $this->createCoaching();
        $batch = $this->makeBatch($tenant, $this->makeCourse($tenant, ['fee' => '10000.00']));
        $this->actingAs($this->ownerOf($tenant));

        $response = $this->post($this->appUrl('/students'), [
            'customer' => ['name' => 'Aarav Shah', 'phone' => '98765 11111'],
            'batch_id' => $batch->id,
            'instalment_count' => 3,
            'first_due_on' => '2026-10-05',
            'payment' => ['amount' => 4000, 'method' => 'upi'],
        ])->assertSessionHasNoErrors();

        $enrolment = $this->inTenant($tenant, fn () => Enrolment::query()->with(['customer', 'instalments'])->sole());
        $response->assertRedirect($this->appUrl("/students/{$enrolment->id}"));

        $this->assertSame(['Aarav Shah', '10000.00', '4000.00', EnrolmentStatus::Active], [$enrolment->customer->name, (string) $enrolment->fee_total, (string) $enrolment->amount_paid, $enrolment->status]);
        $this->assertSame(['2026-10-05', '2026-11-05', '2026-12-05'], $enrolment->instalments->map(fn (FeeInstalment $row) => $row->due_on->toDateString())->all());
        $this->assertSame(['3333.33', '3333.33', '3333.34'], $enrolment->instalments->map(fn (FeeInstalment $row) => (string) $row->amount)->all());
        $this->assertSame(['3333.33', '666.67', '0.00'], $enrolment->instalments->map(fn (FeeInstalment $row) => (string) $row->amount_paid)->all());
        $this->assertSame(['created', 'admitted', 'fee_paid'], $this->inTenant($tenant, fn () => Activity::query()->where('customer_id', $enrolment->customer_id)->orderBy('id')->pluck('type')->all()));

        $this->get($this->appUrl("/students/{$enrolment->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->component('business/students/Show'));
        $this->get($this->appUrl('/students'))->assertInertia(fn (Assert $page) => $page->where('students.meta.total', 1)->where('counts.active', 1));
    }

    public function test_admitting_from_an_enquiry_converts_it_to_admitted(): void
    {
        $tenant = $this->createCoaching();
        $batch = $this->makeBatch($tenant);
        $lead = $this->makeLead($tenant, ['name' => 'Riya Kulkarni', 'phone' => '98765 22222']);
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl("/students/admit?lead={$lead->id}"))->assertInertia(fn (Assert $page) => $page->where('lead.id', $lead->id));
        $this->post($this->appUrl('/students'), ['lead_id' => $lead->id, 'batch_id' => $batch->id, 'discount' => 2000])->assertSessionHasNoErrors();

        $enrolment = $this->inTenant($tenant, fn () => Enrolment::query()->sole());
        $lead = $this->inTenant($tenant, fn () => Lead::query()->with('stage')->findOrFail($lead->id));

        $this->assertSame('converted', $lead->stage->code);
        $this->assertSame([$lead->customer_id, $lead->id, '10000.00'], [$enrolment->customer_id, $enrolment->lead_id, $enrolment->netFee()]);

        $lost = $this->makeLead($tenant);
        $this->inTenant($tenant, fn () => $lost->update(['lead_stage_id' => $this->stage($tenant, 'lost')->id]));
        $this->post($this->appUrl('/students'), ['lead_id' => $lost->id, 'batch_id' => $batch->id])->assertSessionHasErrors('lead_id');
    }

    public function test_admissions_respect_capacity_duplicates_and_inactive_batches(): void
    {
        $tenant = $this->createCoaching();
        $batch = $this->makeBatch($tenant, data: ['capacity' => 1]);
        $student = $this->makeCustomer($tenant);
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/students'), ['customer_id' => $student->id, 'batch_id' => $batch->id])->assertSessionHasNoErrors();
        $this->post($this->appUrl('/students'), ['customer_id' => $student->id, 'batch_id' => $batch->id])->assertSessionHasErrors('batch_id');
        $this->post($this->appUrl('/students'), ['customer' => ['name' => 'Second'], 'batch_id' => $batch->id])->assertSessionHasErrors(['batch_id' => 'This batch is full (1 students).']);

        $closed = $this->makeBatch($tenant, data: ['is_active' => false]);
        $this->post($this->appUrl('/students'), ['customer' => ['name' => 'Third'], 'batch_id' => $closed->id])->assertSessionHasErrors('batch_id');

        $open = $this->makeBatch($tenant);
        $this->post($this->appUrl('/students'), ['customer' => ['name' => 'Fourth'], 'batch_id' => $open->id, 'discount' => 20000])->assertSessionHasErrors('discount');
        $this->post($this->appUrl('/students'), [
            'customer' => ['name' => 'Fifth'], 'batch_id' => $open->id,
            'instalments' => [['due_on' => '2026-10-10', 'amount' => 5000], ['due_on' => '2026-11-10', 'amount' => 5000]],
        ])->assertSessionHasErrors('instalments');

        $this->assertSame(1, $this->inTenant($tenant, fn () => Enrolment::query()->count()));
    }

    public function test_fee_payments_are_allocated_oldest_first_and_the_plan_can_change(): void
    {
        $tenant = $this->createCoaching();
        $enrolment = $this->admit($tenant, $this->makeBatch($tenant), [
            'fee_total' => 9000,
            'instalments' => [['due_on' => '2026-11-05', 'amount' => 6000], ['due_on' => '2026-10-05', 'amount' => 3000]],
        ]);
        $this->actingAs($this->ownerOf($tenant));
        $paid = fn () => $this->inTenant($tenant, fn () => FeeInstalment::query()->where('enrolment_id', $enrolment->id)->orderBy('due_on')->pluck('amount_paid')->map(fn ($v) => (string) $v)->all());

        $this->post($this->appUrl("/students/{$enrolment->id}/payments"), ['amount' => 10000, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->post($this->appUrl("/students/{$enrolment->id}/payments"), ['amount' => 4000, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(['3000.00', '1000.00'], $paid());

        $payment = $this->inTenant($tenant, fn () => FeePayment::query()->sole());
        $this->delete($this->appUrl("/students/{$enrolment->id}/payments/{$payment->id}"))->assertSessionHasNoErrors();
        $this->assertSame(['0.00', '0.00'], $paid());

        $this->post($this->appUrl("/students/{$enrolment->id}/payments"), ['amount' => 5000, 'method' => 'upi'])->assertSessionHasNoErrors();
        $this->inTenant($tenant, fn () => FeeInstalment::query()->where('due_on', '2026-10-05')->update(['reminded_at' => now()]));

        $this->put($this->appUrl("/students/{$enrolment->id}/fees"), ['fee_total' => 4000, 'instalments' => [['due_on' => '2026-10-05', 'amount' => 4000]]])
            ->assertSessionHasErrors('fee_total');
        $this->put($this->appUrl("/students/{$enrolment->id}/fees"), ['fee_total' => 9000, 'discount' => 1000, 'instalments' => [['due_on' => '2026-10-05', 'amount' => 3000]]])
            ->assertSessionHasErrors('instalments');
        $this->put($this->appUrl("/students/{$enrolment->id}/fees"), [
            'fee_total' => 9000, 'discount' => 1000,
            'instalments' => [['due_on' => '2026-10-05', 'amount' => 3000], ['due_on' => '2026-12-05', 'amount' => 5000]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['3000.00', '2000.00'], $paid());
        $instalments = $this->inTenant($tenant, fn () => FeeInstalment::query()->where('enrolment_id', $enrolment->id)->orderBy('due_on')->get());
        $this->assertNotNull($instalments[0]->reminded_at, 'An unchanged instalment keeps its reminder history.');
        $this->assertSame('3000.00', $this->inTenant($tenant, fn () => $enrolment->fresh()->balance()));
    }

    public function test_students_can_be_dropped_and_reactivated_only_with_a_free_seat(): void
    {
        $tenant = $this->createCoaching();
        $batch = $this->makeBatch($tenant, data: ['capacity' => 1]);
        $enrolment = $this->admit($tenant, $batch);
        $this->actingAs($this->ownerOf($tenant));

        $this->patch($this->appUrl("/students/{$enrolment->id}/status"), ['status' => 'dropped', 'reason' => 'Moved to another city'])->assertSessionHasNoErrors();
        $this->assertSame(EnrolmentStatus::Dropped, $this->inTenant($tenant, fn () => $enrolment->fresh()->status));
        $this->assertSame('Moved to another city', $this->inTenant($tenant, fn () => Activity::query()->where('type', 'enrolment_dropped')->sole()->body));

        $this->admit($tenant, $batch);
        $this->patch($this->appUrl("/students/{$enrolment->id}/status"), ['status' => 'active'])->assertSessionHasErrors(['status' => 'This batch is full.']);
    }

    public function test_attendance_is_taken_per_class_and_can_be_corrected(): void
    {
        $tenant = $this->createCoaching();
        $batch = $this->makeBatch($tenant);
        $first = $this->admit($tenant, $batch);
        $second = $this->admit($tenant, $batch);
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl("/batches/{$batch->id}/attendance"), ['date' => '2026-10-05', 'topic' => 'Algebra', 'marks' => [$first->id => 'present', $second->id => 'absent']])
            ->assertSessionHasNoErrors();
        $this->post($this->appUrl("/batches/{$batch->id}/attendance"), ['date' => '2026-10-05', 'marks' => [$second->id => 'late']])->assertSessionHasNoErrors();

        $session = $this->inTenant($tenant, fn () => ClassSession::query()->sole());
        $this->assertSame('2026-10-05', $session->held_on->toDateString());
        $records = $this->inTenant($tenant, fn () => AttendanceRecord::query()->orderBy('enrolment_id')->pluck('status', 'enrolment_id')->all());
        $this->assertEquals([$first->id => AttendanceStatus::Present, $second->id => AttendanceStatus::Late], $records);

        $this->post($this->appUrl("/batches/{$batch->id}/attendance"), ['date' => '2026-10-06', 'marks' => [$first->id => 'present']])->assertSessionHasErrors('date');

        $this->patch($this->appUrl("/students/{$second->id}/status"), ['status' => 'dropped']);
        $this->post($this->appUrl("/batches/{$batch->id}/attendance"), ['date' => '2026-10-02', 'marks' => [$second->id => 'present']])->assertSessionHasErrors('marks');

        $this->get($this->appUrl("/students/{$first->id}"))->assertInertia(fn (Assert $page) => $page->where('attendance.marked', 1)->where('attendance.rate', 100));
    }

    public function test_demo_classes_move_the_enquiry_and_are_marked_after_they_start(): void
    {
        $tenant = $this->createCoaching();
        $course = $this->makeCourse($tenant);
        $lead = $this->makeLead($tenant);
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl("/leads/{$lead->id}/demos"), ['scheduled_at' => '2026-10-06T16:00', 'course_id' => $course->id, 'notes' => 'Bring notebook'])->assertSessionHasNoErrors();

        $demo = $this->inTenant($tenant, fn () => DemoClass::query()->sole());
        $this->assertSame([DemoStatus::Scheduled, $course->id], [$demo->status, $demo->course_id]);
        $this->assertTrue($demo->scheduled_at->equalTo($this->local($tenant, '2026-10-06 16:00')));
        $this->assertSame('demo_scheduled', $this->inTenant($tenant, fn () => $lead->fresh()->stage->code));

        $this->patch($this->appUrl("/demos/{$demo->id}"), ['status' => 'attended'])->assertSessionHasErrors('status');

        $this->travelToBookingDay('2026-10-06 17:00');
        $this->patch($this->appUrl("/demos/{$demo->id}"), ['status' => 'attended'])->assertSessionHasNoErrors();
        $this->assertSame(DemoStatus::Attended, $this->inTenant($tenant, fn () => $demo->fresh()->status));
        $this->assertTrue($this->inTenant($tenant, fn () => Activity::query()->where('lead_id', $lead->id)->where('type', 'demo_attended')->exists()));

        $this->get($this->appUrl('/demos?view=all'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('business/demos/Index'));

        $won = $this->makeLead($tenant);
        $this->inTenant($tenant, fn () => $won->update(['lead_stage_id' => $this->stage($tenant, 'converted')->id]));
        $this->post($this->appUrl("/leads/{$won->id}/demos"), ['scheduled_at' => '2026-10-08T16:00'])->assertSessionHasErrors('scheduled_at');
    }

    public function test_staff_take_attendance_but_cannot_admit_or_see_fees(): void
    {
        $tenant = $this->createCoaching();
        $batch = $this->makeBatch($tenant);
        $enrolment = $this->admit($tenant, $batch);
        $teacher = User::factory()->create();
        $this->addMember($tenant, $teacher, 'staff');
        $this->actingAs($teacher);

        $this->get($this->appUrl('/students'))->assertOk();
        $this->post($this->appUrl("/batches/{$batch->id}/attendance"), ['date' => '2026-10-05', 'marks' => [$enrolment->id => 'present']])->assertSessionHasNoErrors();

        $this->get($this->appUrl('/students/admit'))->assertForbidden();
        $this->post($this->appUrl('/students'), ['customer' => ['name' => 'Sneaky'], 'batch_id' => $batch->id])->assertForbidden();
        $this->post($this->appUrl("/students/{$enrolment->id}/payments"), ['amount' => 100, 'method' => 'cash'])->assertForbidden();
        $this->get($this->appUrl('/fees'))->assertForbidden();
        $this->post($this->appUrl('/courses'), ['name' => 'Sneaky', 'is_active' => true])->assertForbidden();
    }

    public function test_education_pages_are_hidden_without_the_engine(): void
    {
        $salon = $this->createTenant();
        $this->actingAs($this->ownerOf($salon));

        foreach (['/courses', '/students', '/fees', '/demos', '/settings/education'] as $path) {
            $this->get($this->appUrl($path))->assertNotFound();
        }

        $lead = $this->makeLead($salon);
        $this->get($this->appUrl("/leads/{$lead->id}"))->assertInertia(fn (Assert $page) => $page->where('education', null));
    }

    public function test_settings_lead_card_dashboard_and_website_section(): void
    {
        $tenant = $this->createCoaching();
        $course = $this->makeCourse($tenant, ['name' => 'Class 12 Physics', 'fee' => '42000.00']);
        $batch = $this->makeBatch($tenant, $course, ['name' => 'Weekend', 'room' => 'Secret room']);
        $this->admit($tenant, $batch);
        $lead = $this->makeLead($tenant);
        $this->actingAs($this->ownerOf($tenant));

        $this->put($this->appUrl('/settings/education'), ['default_instalments' => 3, 'reminder_days_before' => 4])->assertSessionHasErrors('reminder_days_before');
        $this->put($this->appUrl('/settings/education'), ['default_instalments' => 3, 'reminder_days_before' => 5])->assertSessionHasNoErrors();
        $this->get($this->appUrl('/students/admit'))->assertInertia(fn (Assert $page) => $page->where('defaultInstalments', 3));

        $this->get($this->appUrl("/leads/{$lead->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('education.courses.0.name', 'Class 12 Physics')
            ->where('education.courses.0.batches.0.name', 'Weekend')
            ->where('education.demos', []));

        $this->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('metrics.students.value', 1)
            ->where('metrics.admissions.value', 1));

        $this->get($this->siteUrl('bright-classes.autowave.test'))->assertOk()->assertInertia(fn (Assert $page) => $page->where(
            'sections',
            function ($sections) {
                $courses = collect($sections)->firstWhere('type', 'courses');

                return $courses['data'][0]['name'] === 'Class 12 Physics'
                    && $courses['data'][0]['batches'][0]['schedule'] !== null
                    && ! array_key_exists('room', $courses['data'][0]['batches'][0]);
            },
        ));
    }
}
