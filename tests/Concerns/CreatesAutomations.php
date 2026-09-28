<?php

namespace Tests\Concerns;

use App\Domain\Automation\Actions\SaveAutomation;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Tests\Fixtures\FlakyStep;

/** Automation fixtures. Use with CreatesCrmRecords and CreatesTenants. */
trait CreatesAutomations
{
    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $attributes
     */
    protected function makeAutomation(Tenant $tenant, string $trigger, array $steps, array $attributes = []): Automation
    {
        return $this->inTenant($tenant, fn () => app(SaveAutomation::class)->handle([
            'name' => 'Automation '.uniqid(),
            'trigger' => $trigger,
            'is_active' => true,
            'steps' => $steps,
            ...$attributes,
        ]));
    }

    /** Pause the default automations so a test only sees its own. */
    protected function pauseDefaultAutomations(Tenant $tenant): void
    {
        $this->inTenant($tenant, fn () => Automation::query()->whereNotNull('template_key')->update(['is_active' => false]));
    }

    protected function template(Tenant $tenant, string $key): Automation
    {
        return $this->inTenant($tenant, fn () => Automation::query()->where('template_key', $key)->firstOrFail());
    }

    /** @return Collection<int, AutomationRun> */
    protected function runsOf(Tenant $tenant, Automation $automation): Collection
    {
        return $this->inTenant($tenant, fn () => AutomationRun::query()->where('automation_id', $automation->id)->orderBy('id')->get());
    }

    protected function runOf(Tenant $tenant, Automation $automation): AutomationRun
    {
        return $this->runsOf($tenant, $automation)->sole();
    }

    /** @return list<string> */
    protected function logEvents(Tenant $tenant, AutomationRun $run): array
    {
        return $this->inTenant($tenant, fn () => $run->logs()->pluck('event')->all());
    }

    protected function registerFlakyAction(int $failures): void
    {
        FlakyStep::$failures = $failures;
        FlakyStep::$handled = 0;
        config(['automation.actions.flaky' => ['label' => 'Flaky', 'group' => 'Test', 'class' => FlakyStep::class, 'entities' => ['lead', 'customer', 'appointment']]]);
    }
}
