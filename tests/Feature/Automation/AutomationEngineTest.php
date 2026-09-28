<?php

namespace Tests\Feature\Automation;

use App\Domain\Activity\Models\Activity;
use App\Domain\Automation\Actions\ControlAutomationRun;
use App\Domain\Automation\Actions\ToggleAutomation;
use App\Domain\Automation\Enums\JobStatus;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Jobs\RunAutomationStep;
use App\Domain\Automation\Models\AutomationJob;
use App\Domain\Automation\Models\AutomationLog;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\AutomationResolver;
use App\Domain\Automation\Services\StepDispatcher;
use App\Domain\Automation\Services\StepRunner;
use App\Domain\Booking\Actions\ChangeAppointmentStatus;
use App\Domain\Booking\Actions\RescheduleAppointment;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Lead\Actions\ChangeLeadStage;
use App\Domain\Lead\Actions\DeleteLead;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Jobs\SendOutboundMessage;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\Fixtures\FlakyStep;
use Tests\TestCase;

class AutomationEngineTest extends TestCase
{
    use CreatesAutomations, CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_a_trigger_starts_a_run_that_snapshots_the_steps_and_waits(): void
    {
        $tenant = $this->createTenant();
        $followup = $this->template($tenant, 'new_lead_followup');

        $lead = $this->makeLead($tenant, ['name' => 'Priya Sharma']);

        $run = $this->runOf($tenant, $followup);
        $this->assertSame('lead.created', $run->trigger);
        $this->assertSame('lead', $run->subject_type);
        $this->assertSame($lead->id, $run->subject_id);
        $this->assertSame("lead:{$lead->id}", $run->dedupe_key);
        $this->assertSame(RunStatus::Waiting, $run->status);
        $this->assertSame(['wait', 'condition', 'action'], array_column($run->steps, 'type'));

        $this->inTenant($tenant, function () use ($run) {
            $jobs = $run->jobs()->get();
            $this->assertSame([JobStatus::Completed, JobStatus::Pending], $jobs->pluck('status')->all());
            $this->assertEqualsWithDelta(now()->addHours(4)->timestamp, $jobs[1]->run_at->timestamp, 5);
        });

        $this->assertSame(['run.created', 'run.started', 'wait.scheduled'], $this->logEvents($tenant, $run));
    }

    public function test_the_step_after_a_wait_runs_only_when_due_and_the_condition_passes(): void
    {
        $tenant = $this->createTenant();
        $followup = $this->template($tenant, 'new_lead_followup');
        $lead = $this->makeLead($tenant, ['name' => 'Priya Sharma']);

        $this->travel(3)->hours();
        $this->artisan('automation:dispatch-due')->assertSuccessful();
        $this->assertSame(RunStatus::Waiting, $this->runOf($tenant, $followup)->status);

        $this->travel(61)->minutes();
        $this->artisan('automation:dispatch-due')->assertSuccessful();

        $run = $this->runOf($tenant, $followup);
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(
            ['run.created', 'run.started', 'wait.scheduled', 'condition.passed', 'action.completed', 'run.completed'],
            $this->logEvents($tenant, $run),
        );

        $this->inTenant($tenant, function () use ($lead, $followup) {
            $task = Activity::query()->where('lead_id', $lead->id)->where('type', 'task')->sole();
            $this->assertSame('Follow up with Priya Sharma', $task->body);
            $this->assertSame('automation', $task->metadata['via']);
            $this->assertSame($followup->name, $task->metadata['automation_name']);
            $this->assertNotNull($lead->refresh()->next_followup_at);
        });
    }

    public function test_a_failed_condition_skips_the_rest_of_the_run(): void
    {
        $tenant = $this->createTenant();
        $followup = $this->template($tenant, 'new_lead_followup');
        $lead = $this->makeLead($tenant);
        $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->handle($lead, $this->stage($tenant, 'contacted')));

        $this->travel(5)->hours();
        $this->artisan('automation:dispatch-due')->assertSuccessful();

        $run = $this->runOf($tenant, $followup);
        $this->assertSame(RunStatus::Skipped, $run->status);
        $this->assertContains('condition.failed', $this->logEvents($tenant, $run));
        $this->assertNotContains('action.completed', $this->logEvents($tenant, $run));
        $this->assertStringContainsString('lead stage is New (it is Contacted)', $this->inTenant($tenant, fn () => $run->logs()->where('event', 'condition.failed')->value('message')));
        $this->assertFalse($this->inTenant($tenant, fn () => Activity::query()->where('lead_id', $lead->id)->where('type', 'task')->exists()));
    }

    public function test_lead_created_to_message_to_execution_log_milestone(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hi {{lead.first_name}}, thanks for contacting {{business.name}}!']],
        ]);

        Queue::fake();
        $lead = $this->makeLead($tenant, ['name' => 'Priya Sharma', 'phone' => '9876543210']);

        // The lead's event queued the first step on the automation queue.
        $run = $this->runOf($tenant, $automation);
        $job = $this->inTenant($tenant, fn () => $run->jobs()->sole());
        $this->assertSame(JobStatus::Queued, $job->status);
        Queue::assertPushedOn('automation', RunAutomationStep::class, fn (RunAutomationStep $step) => $step->automationJobId === $job->id);

        // The worker runs the step: the message is stored and handed to the messaging queue.
        app(StepRunner::class)->run($job->id);
        $message = $this->inTenant($tenant, fn () => OutboundMessage::query()->sole());
        $this->assertSame('whatsapp', $message->channel);
        $this->assertSame('+919876543210', $message->recipient);
        $this->assertSame('Hi Priya, thanks for contacting ABC Salon!', $message->body);
        $this->assertSame(MessageStatus::Queued, $message->status);
        $this->assertTrue($message->simulated);
        Queue::assertPushedOn('messaging', SendOutboundMessage::class);

        // The messaging worker sends it.
        app()->call([new SendOutboundMessage($message->id), 'handle']);

        $this->assertSame(MessageStatus::Sent, $message->refresh()->status);
        $this->assertStringStartsWith('sim-', $message->provider_message_id);
        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        $this->assertSame(['run.created', 'run.started', 'action.completed', 'run.completed', 'message.sent'], $this->logEvents($tenant, $run));

        $this->inTenant($tenant, function () use ($lead, $message) {
            $activity = Activity::query()->where('lead_id', $lead->id)->where('type', 'whatsapp')->sole();
            $this->assertSame('automation', $activity->metadata['via']);
            $this->assertTrue($activity->metadata['simulated']);
            $this->assertSame($message->id, $activity->metadata['message_id']);
        });
    }

    public function test_the_whole_chain_runs_through_the_sync_queue(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'lead.phone', 'operator' => 'is_set']]]],
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Welcome {{lead.name}}']],
            ['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'Call {{lead.name}}', 'due_in_hours' => 1]],
        ]);

        $this->makeLead($tenant, ['name' => 'Priya']);

        $run = $this->runOf($tenant, $automation);
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(MessageStatus::Sent, $this->inTenant($tenant, fn () => OutboundMessage::query()->sole()->status));
        $this->assertSame([JobStatus::Completed, JobStatus::Completed, JobStatus::Completed], $this->inTenant($tenant, fn () => $run->jobs()->pluck('status')->all()));
    }

    public function test_a_failing_action_is_retried_and_does_not_repeat_work(): void
    {
        $this->registerFlakyAction(failures: 1);
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hello']],
            ['type' => 'action', 'action' => 'flaky', 'config' => []],
        ]);

        Queue::fake();
        $this->makeLead($tenant);
        $run = $this->runOf($tenant, $automation);
        $runner = app(StepRunner::class);

        $runner->run($this->jobFor($tenant, $run, 0)->id);
        $second = $this->jobFor($tenant, $run, 1);

        try {
            $runner->run($second->id);
            $this->fail('The flaky action did not throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('Provider unavailable', $e->getMessage());
        }

        $second->refresh();
        $this->assertSame(JobStatus::Queued, $second->status);
        $this->assertSame(1, $second->attempts);
        $this->assertSame('Provider unavailable', $second->error);
        $this->assertSame(RunStatus::Running, $run->refresh()->status);
        $failure = $this->inTenant($tenant, fn () => $run->logs()->where('event', 'step.failed')->sole());
        $this->assertSame('warning', $failure->level);

        // The queue retries the step; the message from step 1 is not sent again.
        $runner->run($second->id);

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        $this->assertSame(2, $second->refresh()->attempts);
        $this->assertSame(1, FlakyStep::$handled);
        $this->assertSame(1, $this->inTenant($tenant, fn () => OutboundMessage::query()->count()));
    }

    public function test_a_step_that_runs_out_of_attempts_fails_the_run_and_can_be_retried(): void
    {
        $this->registerFlakyAction(failures: 5);
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'flaky', 'config' => []],
        ]);

        // Sync queue: no retries, so the first failure calls RunAutomationStep::failed().
        $this->makeLead($tenant);

        $run = $this->runOf($tenant, $automation);
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('Provider unavailable', $run->error);
        $this->assertSame(JobStatus::Failed, $this->jobFor($tenant, $run, 0)->status);

        $log = $this->inTenant($tenant, fn () => AutomationLog::query()->where('automation_run_id', $run->id)->where('event', 'run.failed')->sole());
        $this->assertSame('error', $log->level);
        $this->assertStringContainsString('Provider unavailable', $log->message);

        // Fixed: a manual retry resumes from the failed step.
        FlakyStep::$failures = 0;
        $this->inTenant($tenant, fn () => app(ControlAutomationRun::class)->retry($run, $owner));

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        $this->assertContains('run.retried', $this->logEvents($tenant, $run));
        $this->assertSame(1, FlakyStep::$handled);
    }

    public function test_duplicate_events_and_duplicate_queue_messages_do_not_run_twice(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hello']],
        ]);

        Queue::fake();
        $lead = $this->makeLead($tenant);

        // The same event delivered again starts no second run.
        $again = $this->inTenant($tenant, fn () => app(AutomationResolver::class)->start('lead.created', $lead, "lead:{$lead->id}"));
        $this->assertCount(0, $again);
        $run = $this->runOf($tenant, $automation);

        // Scheduling the same step again returns the existing row and does not dispatch it again.
        $job = $this->jobFor($tenant, $run, 0);
        $this->assertSame($job->id, $this->inTenant($tenant, fn () => app(StepDispatcher::class)->schedule($run, 0, now()))->id);
        $this->assertFalse(app(StepDispatcher::class)->dispatch($job->id));
        Queue::assertPushed(RunAutomationStep::class, 1);

        // Two workers receiving the same step: only the first claims it.
        app(StepRunner::class)->run($job->id);
        app(StepRunner::class)->run($job->id);

        $this->assertSame(1, $job->refresh()->attempts);
        $this->assertSame(1, $this->inTenant($tenant, fn () => OutboundMessage::query()->count()));
    }

    public function test_once_per_subject_automations_start_one_run_per_record(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.status_changed', [
            ['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'Check {{lead.name}}', 'due_in_hours' => 0]],
        ], ['once_per_subject' => true]);

        $lead = $this->makeLead($tenant);
        $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->handle($lead, $this->stage($tenant, 'contacted')));
        $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->handle($lead->refresh(), $this->stage($tenant, 'qualified')));

        $run = $this->runOf($tenant, $automation);
        $this->assertSame("lead:{$lead->id}", $run->dedupe_key);
        $this->assertEquals(['from' => 'new', 'to' => 'contacted'], $run->payload);
    }

    public function test_automations_triggering_each_other_stop_at_the_depth_limit(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $condition = fn (string $stage) => ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'lead.stage', 'operator' => 'equals', 'value' => $stage]]]];
        $toContacted = $this->makeAutomation($tenant, 'lead.status_changed', [$condition('qualified'), ['type' => 'action', 'action' => 'update_lead', 'config' => ['stage' => 'contacted']]]);
        $toQualified = $this->makeAutomation($tenant, 'lead.status_changed', [$condition('contacted'), ['type' => 'action', 'action' => 'update_lead', 'config' => ['stage' => 'qualified']]]);

        $lead = $this->makeLead($tenant);
        $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->handle($lead, $this->stage($tenant, 'contacted')));

        $runs = $this->inTenant($tenant, fn () => AutomationRun::query()->whereIn('automation_id', [$toContacted->id, $toQualified->id])->get());
        $maxDepth = config('automation.max_depth');
        $this->assertSame($maxDepth - 1, $runs->max('depth'));
        $this->assertCount($maxDepth * 2, $runs);
        $this->assertNull(Context::get('automation_depth'));

        // Directly: nothing starts at the limit.
        Context::add('automation_depth', $maxDepth);
        $this->assertCount(0, $this->inTenant($tenant, fn () => app(AutomationResolver::class)->start('lead.status_changed', $lead)));
        Context::forget('automation_depth');
    }

    public function test_appointment_waits_move_when_the_appointment_is_rescheduled(): void
    {
        $this->travelToBookingDay();
        $tenant = $this->createTenant();
        $reminder = $this->template($tenant, 'appointment_reminder');
        $this->inTenant($tenant, fn () => app(ToggleAutomation::class)->handle($reminder, true));
        $resource = $this->makeResource($tenant);

        $appointment = $this->book($tenant, $resource, '2026-10-08 11:00');

        $run = $this->runOf($tenant, $reminder);
        $wait = $this->jobFor($tenant, $run, 1);
        $this->assertSame(AutomationJob::ANCHOR_APPOINTMENT_START, $wait->anchor);
        $this->assertSame(-1440, $wait->offset_minutes);
        $this->assertTrue($wait->run_at->eq($this->local($tenant, '2026-10-07 11:00')));

        $this->inTenant($tenant, fn () => app(RescheduleAppointment::class)->handle($appointment, $this->local($tenant, '2026-10-09 15:00')));

        $this->assertTrue($wait->refresh()->run_at->eq($this->local($tenant, '2026-10-08 15:00')));
        $this->assertContains('wait.retimed', $this->logEvents($tenant, $run));

        // At reminder time the condition (still confirmed) passes; WhatsApp is paused by default,
        // so the owner turned this automation on and the message goes out.
        $this->travelTo($this->local($tenant, '2026-10-08 15:01'));
        $this->artisan('automation:dispatch-due')->assertSuccessful();

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        $message = $this->inTenant($tenant, fn () => OutboundMessage::query()->where('automation_run_id', $run->id)->sole());
        $this->assertStringContainsString('Fri, 9 Oct', $message->body);
        $this->assertStringContainsString('3:00 PM', $message->body);
    }

    public function test_a_run_is_cancelled_when_the_automation_is_turned_off_or_the_subject_is_gone(): void
    {
        $tenant = $this->createTenant();
        $followup = $this->template($tenant, 'new_lead_followup');
        $first = $this->makeLead($tenant);
        $second = $this->makeLead($tenant);
        $this->inTenant($tenant, fn () => app(DeleteLead::class)->handle($second));

        $this->travel(5)->hours();
        $this->artisan('automation:dispatch-due')->assertSuccessful();

        [$firstRun, $secondRun] = $this->runsOf($tenant, $followup)->all();
        $this->assertSame(RunStatus::Completed, $firstRun->status);
        $this->assertSame(RunStatus::Cancelled, $secondRun->status);
        $this->assertStringContainsString('no longer exists', $this->inTenant($tenant, fn () => $secondRun->logs()->where('event', 'run.cancelled')->value('message')));

        $third = $this->makeLead($tenant);
        $this->inTenant($tenant, fn () => app(ToggleAutomation::class)->handle($followup, false));
        $this->travel(5)->hours();
        $this->artisan('automation:dispatch-due')->assertSuccessful();

        $thirdRun = $this->runsOf($tenant, $followup)->last();
        $this->assertSame($third->id, $thirdRun->subject_id);
        $this->assertSame(RunStatus::Cancelled, $thirdRun->status);
        $this->assertStringContainsString('turned off', $this->inTenant($tenant, fn () => $thirdRun->logs()->where('event', 'run.cancelled')->value('message')));
    }

    public function test_no_runs_start_when_the_automation_module_is_off(): void
    {
        $tenant = $this->createTenant();
        app(ModuleManager::class)->disable($tenant, 'automation');

        $this->makeLead($tenant);

        $this->assertSame(0, $this->inTenant($tenant, fn () => AutomationRun::query()->count()));
    }

    public function test_the_scheduler_recovers_steps_lost_from_the_queue(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'Call', 'due_in_hours' => 0]],
        ]);

        Queue::fake();
        $this->makeLead($tenant);
        $run = $this->runOf($tenant, $automation);
        $job = $this->jobFor($tenant, $run, 0);
        $this->assertSame(JobStatus::Queued, $job->status);

        // The queue lost the message; the scheduler re-dispatches it after the stuck window.
        $this->artisan('automation:dispatch-due')->assertSuccessful();
        Queue::assertPushed(RunAutomationStep::class, 1);

        $this->travel(config('automation.stuck_queued_minutes') + 1)->minutes();
        $this->artisan('automation:dispatch-due')->assertSuccessful();
        Queue::assertPushed(RunAutomationStep::class, 2);
        $this->assertSame(JobStatus::Queued, $job->refresh()->status);

        // A worker that died mid-step: re-queued while attempts remain.
        AutomationJob::withoutTenantScope()->whereKey($job->id)->update(['status' => JobStatus::Running, 'attempts' => 1, 'updated_at' => now()->subMinutes(config('automation.stuck_running_minutes') + 1)]);
        $this->artisan('automation:dispatch-due')->assertSuccessful();
        Queue::assertPushed(RunAutomationStep::class, 3);

        // …and failed once they are used up.
        AutomationJob::withoutTenantScope()->whereKey($job->id)->update(['status' => JobStatus::Running, 'attempts' => config('automation.tries'), 'updated_at' => now()->subMinutes(config('automation.stuck_running_minutes') + 1)]);
        $this->artisan('automation:dispatch-due')->assertSuccessful();
        $this->assertSame(JobStatus::Failed, $job->refresh()->status);
        $this->assertSame(RunStatus::Failed, $run->refresh()->status);
    }

    public function test_the_no_show_default_adds_a_rebooking_task(): void
    {
        $this->travelToBookingDay();
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $customer = $this->makeCustomer($tenant, ['name' => 'Meera Iyer']);
        $appointment = $this->book($tenant, $resource, '2026-10-05 11:00', ['customer_id' => $customer->id]);
        $this->travelTo($this->local($tenant, '2026-10-05 13:00'));

        $this->inTenant($tenant, fn () => app(ChangeAppointmentStatus::class)->handle($appointment, AppointmentStatus::NoShow));

        $task = $this->inTenant($tenant, fn () => Activity::query()->where('type', 'task')->sole());
        $this->assertSame('Call Meera Iyer to rebook the missed appointment', $task->body);
        $this->assertSame($customer->id, $task->customer_id);
        $this->assertSame($appointment->id, $task->appointment_id);
    }

    private function jobFor(Tenant $tenant, AutomationRun $run, int $step): AutomationJob
    {
        return $this->inTenant($tenant, fn () => $run->jobs()->where('step_index', $step)->firstOrFail());
    }
}
