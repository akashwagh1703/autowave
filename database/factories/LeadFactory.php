<?php

namespace Database\Factories;

use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Must be used inside TenantContext::run() for a tenant with CRM provisioned.
 * Bypasses CreateLead (no activities or events); use the action for behaviour tests.
 *
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'lead_stage_id' => fn () => LeadStage::firstFor(StageOutcome::Open)?->id,
            'lead_source_id' => fn () => LeadSource::query()->where('code', config('crm.default_source'))->value('id'),
            'name' => fake()->name(),
            'phone' => '8'.fake()->unique()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'interest' => fake()->randomElement(['Haircut', 'Membership', 'Consultation', 'Package enquiry']),
        ];
    }

    public function inStage(string $code): static
    {
        return $this->state(fn () => ['lead_stage_id' => LeadStage::query()->where('code', $code)->value('id')]);
    }
}
