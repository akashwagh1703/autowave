<?php

namespace Tests\Feature\Crm;

use App\Domain\Activity\Models\Activity;
use App\Domain\Customer\Events\CustomerCreated;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Actions\ChangeLeadStage;
use App\Domain\Lead\Actions\ConvertLead;
use App\Domain\Lead\Actions\UpdateLead;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Events\LeadConverted;
use App\Domain\Lead\Events\LeadCreated;
use App\Domain\Lead\Events\LeadStatusChanged;
use App\Domain\Lead\Events\LeadUpdated;
use App\Domain\Lead\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LeadLifecycleTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_a_new_lead_starts_in_the_first_open_stage_with_the_default_source_and_a_timeline_entry(): void
    {
        Event::fake([LeadCreated::class]);
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);

        $lead = $this->makeLead($tenant, ['name' => 'Priya', 'phone' => '098765 43210'], $owner);

        $this->inTenant($tenant, function () use ($lead, $owner) {
            $lead->refresh()->load('stage', 'source');
            $this->assertSame('new', $lead->stage->code);
            $this->assertSame(config('crm.default_source'), $lead->source->code);
            $this->assertSame('+919876543210', $lead->phone_normalized);
            $this->assertSame($owner->id, $lead->created_by_user_id);

            $activity = $lead->activities()->sole();
            $this->assertSame('created', $activity->type);
            $this->assertSame($owner->id, $activity->user_id);
        });

        Event::assertDispatched(LeadCreated::class, fn (LeadCreated $event) => $event->lead->is($lead));
    }

    public function test_only_one_open_lead_per_phone_number(): void
    {
        $tenant = $this->createTenant();
        $this->makeLead($tenant, ['phone' => '+91 98765 43210']);

        try {
            $this->makeLead($tenant, ['phone' => '9876543210']);
            $this->fail('A duplicate open lead was created.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }

        // The same number is fine in another tenant.
        $other = $this->createTenant('Other Salon');
        $this->assertNotNull($this->makeLead($other, ['phone' => '9876543210'])->id);
    }

    public function test_a_closed_lead_does_not_block_a_new_enquiry_from_the_same_number(): void
    {
        $tenant = $this->createTenant();
        $old = $this->makeLead($tenant, ['phone' => '9876543210']);
        $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->toOutcome($old, StageOutcome::Lost));

        $new = $this->makeLead($tenant, ['phone' => '9876543210']);

        $this->assertNotSame($old->id, $new->id);
    }

    public function test_a_lead_is_linked_to_an_existing_customer_with_the_same_phone(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant, ['name' => 'Kavya', 'phone' => '98220 11111']);

        $lead = $this->makeLead($tenant, ['phone' => '+91-98220-11111']);

        $this->assertSame($customer->id, $lead->customer_id);
        $this->inTenant($tenant, fn () => $this->assertSame($customer->id, $lead->activities()->sole()->customer_id));
    }

    public function test_converting_creates_a_customer_backfills_the_timeline_and_fires_events(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $lead = $this->makeLead($tenant, ['name' => 'Priya', 'phone' => '9876500001', 'email' => 'priya@example.com', 'next_followup_at' => now()->addDay()], $owner);
        Event::fake([LeadConverted::class, LeadStatusChanged::class, CustomerCreated::class]);

        $converted = $this->inTenant($tenant, fn () => app(ConvertLead::class)->handle($lead, $owner));

        $this->inTenant($tenant, function () use ($converted, $lead) {
            $customer = Customer::query()->sole();
            $this->assertSame('Priya', $customer->name);
            $this->assertSame('+919876500001', $customer->phone_normalized);
            $this->assertSame('priya@example.com', $customer->email);

            $this->assertSame($customer->id, $converted->customer_id);
            $this->assertNotNull($converted->converted_at);
            $this->assertNull($converted->next_followup_at);
            $this->assertSame(StageOutcome::Won, $converted->stage->outcome);

            // Every lead activity is now on the customer's timeline too.
            $this->assertSame(0, Activity::query()->where('lead_id', $lead->id)->whereNull('customer_id')->count());
            $this->assertTrue(Activity::query()->where('lead_id', $lead->id)->where('type', 'converted')->exists());
            $this->assertTrue(Activity::query()->where('customer_id', $customer->id)->whereNull('lead_id')->where('type', 'created')->exists());
        });

        Event::assertDispatched(LeadConverted::class, fn (LeadConverted $event) => $event->customerCreated);
        Event::assertDispatched(LeadStatusChanged::class);
        Event::assertDispatched(CustomerCreated::class);
    }

    public function test_converting_reuses_an_existing_customer_matched_by_email(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant, ['phone' => null, 'email' => 'Asha@Example.com']);
        $lead = $this->makeLead($tenant, ['phone' => '9876500002', 'email' => 'asha@example.com']);

        $converted = $this->inTenant($tenant, fn () => app(ConvertLead::class)->handle($lead));

        $this->assertSame($customer->id, $converted->customer_id);
        $this->inTenant($tenant, fn () => $this->assertSame(1, Customer::query()->count()));
    }

    public function test_conversion_is_transactional(): void
    {
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant, ['phone' => '9876500003']);

        Activity::creating(function (Activity $activity) {
            if ($activity->type === 'converted') {
                throw new RuntimeException('Simulated failure');
            }
        });

        try {
            $this->inTenant($tenant, fn () => app(ConvertLead::class)->handle($lead));
            $this->fail('Conversion should have failed.');
        } catch (RuntimeException) {
        }

        $this->inTenant($tenant, function () use ($lead) {
            $this->assertSame(0, Customer::query()->count());
            $fresh = $lead->fresh();
            $this->assertNull($fresh->customer_id);
            $this->assertNull($fresh->converted_at);
            $this->assertSame('new', $fresh->stage->code);
        });
    }

    public function test_marking_lost_records_the_reason_and_reactivation_clears_it(): void
    {
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant);
        $change = app(ChangeLeadStage::class);

        $lost = $this->inTenant($tenant, fn () => $change->toOutcome($lead, StageOutcome::Lost, reason: 'Too expensive'));
        $this->assertNotNull($lost->lost_at);
        $this->assertSame('Too expensive', $lost->lost_reason);

        $reopened = $this->inTenant($tenant, fn () => $change->handle($lost, $this->stage($tenant, 'follow_up')));
        $this->assertNull($reopened->lost_at);
        $this->assertNull($reopened->lost_reason);

        $this->inTenant($tenant, fn () => $this->assertSame(
            ['created', 'lost', 'reactivated'],
            $lead->activities()->orderBy('id')->pluck('type')->all(),
        ));
    }

    public function test_reactivation_is_refused_when_another_open_lead_has_the_same_phone(): void
    {
        $tenant = $this->createTenant();
        $old = $this->makeLead($tenant, ['phone' => '9876500004']);
        $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->toOutcome($old, StageOutcome::Lost));
        $this->makeLead($tenant, ['phone' => '9876500004']);

        $this->expectException(ValidationException::class);

        $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->toOutcome($old->fresh(), StageOutcome::Open));
    }

    public function test_moving_between_open_stages_logs_a_stage_change(): void
    {
        Event::fake([LeadStatusChanged::class]);
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant);

        $moved = $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->handle($lead, $this->stage($tenant, 'qualified')));

        $this->assertSame('qualified', $moved->stage->code);
        $this->inTenant($tenant, function () use ($lead) {
            $activity = $lead->activities()->where('type', 'stage_changed')->sole();
            $this->assertSame('New', $activity->metadata['from']['name']);
            $this->assertSame('Qualified', $activity->metadata['to']['name']);
        });
        Event::assertDispatched(LeadStatusChanged::class, fn (LeadStatusChanged $event) => $event->from->code === 'new' && $event->to->code === 'qualified');
    }

    public function test_an_inactive_stage_cannot_be_targeted(): void
    {
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant);
        $stage = $this->stage($tenant, 'qualified');
        $this->inTenant($tenant, fn () => $stage->update(['is_active' => false]));

        $this->expectException(ValidationException::class);

        $this->inTenant($tenant, fn () => app(ChangeLeadStage::class)->handle($lead, $stage->fresh()));
    }

    public function test_updating_details_logs_changed_fields_and_fires_lead_updated(): void
    {
        Event::fake([LeadUpdated::class]);
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant, ['interest' => 'Haircut']);

        $this->inTenant($tenant, fn () => app(UpdateLead::class)->handle($lead, ['interest' => 'Hair colour', 'estimated_value' => '2500']));

        $this->inTenant($tenant, function () use ($lead) {
            $activity = $lead->activities()->where('type', 'updated')->sole();
            $this->assertEqualsCanonicalizing(['interest', 'estimated_value'], $activity->metadata['changed']);
        });
        Event::assertDispatched(LeadUpdated::class);

        // No changes, no noise.
        $this->inTenant($tenant, fn () => app(UpdateLead::class)->handle($lead->fresh(), ['interest' => 'Hair colour']));
        $this->inTenant($tenant, fn () => $this->assertSame(1, $lead->activities()->where('type', 'updated')->count()));
    }

    public function test_events_are_not_dispatched_when_the_transaction_rolls_back(): void
    {
        Event::fake([LeadCreated::class]);
        $tenant = $this->createTenant();

        try {
            DB::transaction(function () use ($tenant) {
                $this->makeLead($tenant);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(LeadCreated::class);
        $this->inTenant($tenant, fn () => $this->assertSame(0, Lead::query()->count()));
    }

    public function test_follow_up_due_uses_the_end_of_today_in_the_tenant_timezone(): void
    {
        $tenant = $this->createTenant(options: ['timezone' => 'Asia/Kolkata']);
        $this->travelTo(now('Asia/Kolkata')->setTime(10, 0));

        $today = $this->makeLead($tenant, ['next_followup_at' => now('Asia/Kolkata')->setTime(22, 0)->utc()]);
        $overdue = $this->makeLead($tenant, ['next_followup_at' => now()->subDays(2)]);
        $this->makeLead($tenant, ['next_followup_at' => now('Asia/Kolkata')->addDay()->setTime(9, 0)->utc()]);
        $this->makeLead($tenant);

        $ids = $this->inTenant($tenant, fn () => Lead::query()->followUpDue()->pluck('id')->sort()->values()->all());

        $this->assertSame(collect([$today->id, $overdue->id])->sort()->values()->all(), $ids);
    }
}
