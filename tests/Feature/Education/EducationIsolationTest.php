<?php

namespace Tests\Feature\Education;

use App\Domain\Education\Actions\RecordFeePayment;
use App\Domain\Education\Actions\ScheduleDemo;
use App\Domain\Education\Enums\EnrolmentStatus;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Models\DemoClass;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeePayment;
use App\Domain\Tenant\Exceptions\CrossTenantWrite;
use App\Domain\Tenant\Exceptions\MissingTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesEducationRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Mandatory cross-tenant tests (master prompt §86) for courses, batches, students, fees and demos. */
class EducationIsolationTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesEducationRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_tenant_a_cannot_touch_tenant_b_courses_batches_or_students(): void
    {
        $a = $this->createCoaching('Tenant A');
        $b = $this->createCoaching('Tenant B');
        $theirCourse = $this->makeCourse($b, ['name' => 'Their course']);
        $theirBatch = $this->makeBatch($b, $theirCourse);
        $theirs = $this->admit($b, $theirBatch, ['fee_total' => 5000]);
        $theirPayment = $this->inTenant($b, fn () => app(RecordFeePayment::class)->handle($theirs, ['amount' => 1000, 'method' => 'cash']));
        $mine = $this->admit($a, $this->makeBatch($a));
        $this->actingAs($this->ownerOf($a));

        $this->put($this->appUrl("/courses/{$theirCourse->id}"), ['name' => 'Hijacked', 'is_active' => true])->assertNotFound();
        $this->delete($this->appUrl("/courses/{$theirCourse->id}"))->assertNotFound();
        $this->get($this->appUrl("/batches/{$theirBatch->id}"))->assertNotFound();
        $this->put($this->appUrl("/batches/{$theirBatch->id}"), ['course_id' => $theirCourse->id, 'name' => 'Hijacked', 'is_active' => true])->assertNotFound();
        $this->post($this->appUrl("/batches/{$theirBatch->id}/attendance"), ['date' => '2026-10-05', 'marks' => [$theirs->id => 'present']])->assertNotFound();
        $this->get($this->appUrl("/students/{$theirs->id}"))->assertNotFound();
        $this->patch($this->appUrl("/students/{$theirs->id}/status"), ['status' => 'dropped'])->assertNotFound();
        $this->put($this->appUrl("/students/{$theirs->id}/fees"), ['fee_total' => 1, 'instalments' => []])->assertNotFound();
        $this->post($this->appUrl("/students/{$theirs->id}/payments"), ['amount' => 10, 'method' => 'cash'])->assertNotFound();
        $this->delete($this->appUrl("/students/{$mine->id}/payments/{$theirPayment->id}"))->assertNotFound();

        $this->get($this->appUrl('/students?status=all'))->assertInertia(fn (Assert $page) => $page->where('students.meta.total', 1)->where('students.data.0.id', $mine->id));
        $this->get($this->appUrl('/courses'))->assertInertia(fn (Assert $page) => $page->where('courses', fn ($courses) => ! collect($courses)->pluck('id')->contains($theirCourse->id)));

        $fresh = Enrolment::withoutTenantScope()->findOrFail($theirs->id);
        $this->assertSame([EnrolmentStatus::Active, '5000.00', '1000.00'], [$fresh->status, (string) $fresh->fee_total, (string) $fresh->amount_paid]);
        $this->assertSame('Their course', Course::withoutTenantScope()->findOrFail($theirCourse->id)->name);
        $this->assertSame(1, FeePayment::withoutTenantScope()->where('enrolment_id', $theirs->id)->count());
    }

    public function test_tenant_a_cannot_admit_into_or_with_tenant_b_records(): void
    {
        $a = $this->createCoaching('Tenant A');
        $b = $this->createCoaching('Tenant B');
        $myBatch = $this->makeBatch($a);
        $theirBatch = $this->makeBatch($b);
        $theirCustomer = $this->makeCustomer($b);
        $theirLead = $this->makeLead($b);
        $myCourse = $this->makeCourse($a);
        $this->actingAs($this->ownerOf($a));

        $this->post($this->appUrl('/students'), ['customer' => ['name' => 'Mine'], 'batch_id' => $theirBatch->id])->assertSessionHasErrors('batch_id');
        $this->post($this->appUrl('/students'), ['customer_id' => $theirCustomer->id, 'batch_id' => $myBatch->id])->assertSessionHasErrors('customer_id');
        $this->post($this->appUrl('/students'), ['lead_id' => $theirLead->id, 'batch_id' => $myBatch->id])->assertSessionHasErrors('lead_id');
        $this->post($this->appUrl('/batches'), ['course_id' => $this->makeCourse($b)->id, 'name' => 'Sneaky', 'is_active' => true])->assertSessionHasErrors('course_id');
        $this->post($this->appUrl("/leads/{$theirLead->id}/demos"), ['scheduled_at' => '2026-10-06T16:00'])->assertNotFound();

        $myLead = $this->makeLead($a);
        $this->post($this->appUrl("/leads/{$myLead->id}/demos"), ['scheduled_at' => '2026-10-06T16:00', 'batch_id' => $theirBatch->id])->assertSessionHasErrors('batch_id');
        $theirDemo = $this->inTenant($b, fn () => app(ScheduleDemo::class)->handle($theirLead, ['scheduled_at' => $this->local($b, '2026-10-06 16:00')]));
        $this->patch($this->appUrl("/demos/{$theirDemo->id}"), ['status' => 'cancelled'])->assertNotFound();

        $this->assertSame(0, Enrolment::withoutTenantScope()->where('tenant_id', $a->id)->count());
        $this->assertSame(0, DemoClass::withoutTenantScope()->where('tenant_id', $a->id)->count());
        $this->assertNotNull($myCourse);
    }

    public function test_composite_foreign_keys_reject_cross_tenant_references(): void
    {
        $a = $this->createCoaching('Tenant A');
        $b = $this->createCoaching('Tenant B');
        $mine = $this->admit($a, $this->makeBatch($a));
        $myBatch = $this->inTenant($a, fn () => Batch::query()->sole());
        $theirBatch = $this->makeBatch($b);
        $theirCourse = $this->inTenant($b, fn () => Course::query()->sole());
        $theirCustomer = $this->makeCustomer($b);

        $attempts = [
            fn () => DB::table('enrolments')->where('id', $mine->id)->update(['batch_id' => $theirBatch->id]),
            fn () => DB::table('enrolments')->where('id', $mine->id)->update(['customer_id' => $theirCustomer->id]),
            fn () => DB::table('batches')->where('id', $myBatch->id)->update(['course_id' => $theirCourse->id]),
        ];

        foreach ($attempts as $index => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("Cross-tenant write #{$index} was accepted.");
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->errorInfo[0]);
            }
        }
    }

    public function test_education_models_fail_closed_without_a_tenant(): void
    {
        $a = $this->createCoaching('Tenant A');
        $b = $this->createCoaching('Tenant B');
        $this->admit($a, $this->makeBatch($a));

        $this->assertSame(0, Course::query()->count());
        $this->assertSame(0, Enrolment::query()->count());

        try {
            $this->inTenant($a, fn () => Course::query()->create(['tenant_id' => $b->id, 'name' => 'Sneaky', 'sort_order' => 1]));
            $this->fail('A cross-tenant write was accepted.');
        } catch (CrossTenantWrite) {
        }

        $this->expectException(MissingTenantContext::class);
        Course::query()->create(['name' => 'Orphan', 'sort_order' => 1]);
    }
}
