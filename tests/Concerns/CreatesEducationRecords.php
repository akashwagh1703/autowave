<?php

namespace Tests\Concerns;

use App\Domain\Education\Actions\AdmitStudent;
use App\Domain\Education\Actions\SaveBatch;
use App\Domain\Education\Actions\SaveCourse;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

/** Education fixtures. Use with CreatesCrmRecords and CreatesTenants. */
trait CreatesEducationRecords
{
    /** A coaching centre (education engine, leads, messaging). */
    protected function createCoaching(string $name = 'Bright Classes'): Tenant
    {
        return $this->createTenant($name, 'coaching');
    }

    /** @param  array<string, mixed>  $data  a course priced 12000.00 */
    protected function makeCourse(Tenant $tenant, array $data = []): Course
    {
        static $sequence = 0;
        $sequence++;

        return $this->inTenant($tenant, fn () => app(SaveCourse::class)->handle(['name' => 'Course '.$sequence, 'fee' => '12000.00', ...$data]));
    }

    /** @param  array<string, mixed>  $data  a Mon/Wed/Fri batch, 17:00–18:00 */
    protected function makeBatch(Tenant $tenant, ?Course $course = null, array $data = []): Batch
    {
        static $sequence = 0;
        $sequence++;
        $course ??= $this->makeCourse($tenant);

        return $this->inTenant($tenant, fn () => app(SaveBatch::class)->handle([
            'course_id' => $course->id,
            'name' => 'Batch '.$sequence,
            'weekdays' => [1, 3, 5],
            'start_time' => '17:00',
            'end_time' => '18:00',
            ...$data,
        ]));
    }

    /**
     * Admits a student through AdmitStudent; without a student a new customer is created.
     *
     * @param  array<string, mixed>  $data
     */
    protected function admit(Tenant $tenant, Batch $batch, array $data = [], ?User $actor = null): Enrolment
    {
        if (! isset($data['customer']) && ! isset($data['customer_id']) && ! isset($data['lead_id'])) {
            $data['customer_id'] = $this->makeCustomer($tenant)->id;
        }

        return $this->inTenant($tenant, fn () => app(AdmitStudent::class)->handle(['batch_id' => $batch->id, ...$data], $actor));
    }
}
