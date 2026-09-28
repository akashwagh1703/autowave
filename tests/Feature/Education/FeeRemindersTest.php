<?php

namespace Tests\Feature\Education;

use App\Domain\Activity\Models\Activity;
use App\Domain\Education\Actions\ChangeEnrolmentStatus;
use App\Domain\Education\Actions\ScheduleDemo;
use App\Domain\Education\Enums\EnrolmentStatus;
use App\Domain\Education\Services\FeeReminders;
use App\Domain\Messaging\Models\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesEducationRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Fee reminders and the education automation triggers (ADR-020). */
class FeeRemindersTest extends TestCase
{
    use CreatesAutomations, CreatesBookingRecords, CreatesCrmRecords, CreatesEducationRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_due_soon_and_overdue_fire_once_per_instalment_for_active_students(): void
    {
        $tenant = $this->createCoaching();
        $this->pauseDefaultAutomations($tenant);
        $batch = $this->makeBatch($tenant, $this->makeCourse($tenant, ['name' => 'Class 10 Maths']));
        $student = $this->makeCustomer($tenant, ['name' => 'Aarav Shah', 'phone' => '+91 98765 43210']);

        $this->makeAutomation($tenant, 'fee.due_soon', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hi {{customer.first_name}}, {{fee.amount_due}} for {{enrolment.course}} is due.']],
        ]);

        $this->admit($tenant, $batch, [
            'customer_id' => $student->id,
            'fee_total' => 3000,
            'instalments' => [
                ['due_on' => '2026-10-01', 'amount' => 1000], // overdue
                ['due_on' => '2026-10-07', 'amount' => 1000], // within 3 days
                ['due_on' => '2026-12-01', 'amount' => 1000], // later
            ],
        ]);
        $paid = $this->admit($tenant, $batch, ['fee_total' => 1000, 'instalments' => [['due_on' => '2026-10-06', 'amount' => 1000]], 'payment' => ['amount' => 1000, 'method' => 'cash']]);
        $dropped = $this->admit($tenant, $batch, ['fee_total' => 1000, 'instalments' => [['due_on' => '2026-10-01', 'amount' => 1000]]]);
        $this->inTenant($tenant, fn () => app(ChangeEnrolmentStatus::class)->handle($dropped, EnrolmentStatus::Dropped));

        $run = fn () => $this->inTenant($tenant, fn () => app(FeeReminders::class)->run());

        $this->assertSame(['due_soon' => 1, 'overdue' => 1], $run());
        $this->assertSame(['due_soon' => 0, 'overdue' => 0], $run());
        $this->assertNotNull($paid);

        $message = $this->inTenant($tenant, fn () => OutboundMessage::query()->sole());
        $this->assertSame('+919876543210', $message->recipient);
        $this->assertStringStartsWith('Hi Aarav, 1,000.00 for Class 10 Maths is due.', $message->body);

        $this->artisan('education:fee-reminders')->assertSuccessful();
    }

    public function test_the_default_overdue_template_adds_a_collection_task(): void
    {
        $tenant = $this->createCoaching();
        $student = $this->makeCustomer($tenant, ['name' => 'Kabir Patil']);
        $this->admit($tenant, $this->makeBatch($tenant, $this->makeCourse($tenant, ['name' => 'Physics'])), [
            'customer_id' => $student->id, 'fee_total' => 1500, 'instalments' => [['due_on' => '2026-10-01', 'amount' => 1500]],
        ]);

        $this->inTenant($tenant, fn () => app(FeeReminders::class)->run());

        $task = $this->inTenant($tenant, fn () => Activity::query()->where('type', 'task')->sole());
        $this->assertSame(['Collect overdue fee of 1,500.00 from Kabir Patil (Physics)', $student->id], [$task->body, $task->customer_id]);
    }

    public function test_admission_and_demo_triggers_run_automations(): void
    {
        $tenant = $this->createCoaching();
        $this->pauseDefaultAutomations($tenant);
        $batch = $this->makeBatch($tenant, $this->makeCourse($tenant, ['name' => 'Chemistry']), ['name' => 'Morning']);

        $this->makeAutomation($tenant, 'enrolment.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Welcome {{customer.first_name}} to {{enrolment.course}} ({{enrolment.batch}}).']],
        ]);
        $this->makeAutomation($tenant, 'demo.scheduled', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Demo for {{lead.first_name}} on {{demo_class.date}}.']],
        ]);

        $this->admit($tenant, $batch, ['customer' => ['name' => 'Sara Deshpande', 'phone' => '+91 98765 00001']]);
        $lead = $this->makeLead($tenant, ['name' => 'Diya Menon', 'phone' => '+91 98765 00002']);
        $this->inTenant($tenant, fn () => app(ScheduleDemo::class)->handle($lead, ['scheduled_at' => $this->local($tenant, '2026-10-07 16:00')]));

        $bodies = $this->inTenant($tenant, fn () => OutboundMessage::query()->orderBy('id')->pluck('body')->all());
        $this->assertSame('Welcome Sara to Chemistry (Morning).', $bodies[0]);
        $this->assertStringStartsWith('Demo for Diya on ', $bodies[1]);
    }
}
