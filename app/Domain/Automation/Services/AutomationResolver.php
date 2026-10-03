<?php

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Support\AutomationCatalog;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Trigger → runs. For each of the tenant's active automations on the trigger, creates a run with a
 * snapshot of its steps and schedules the first step.
 *
 * - Deduplication: a run's dedupe_key is unique per automation. The event key identifies the event
 *   (e.g. "appointment:12" for appointment.confirmed, which happens once), so the same event
 *   delivered twice starts one run. `once_per_subject` automations key on the subject alone.
 * - Loop guard: steps run with Context `automation_depth` = run depth + 1. Events raised by those
 *   steps start runs one level deeper; nothing starts at automation.max_depth or beyond.
 */
class AutomationResolver
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StepDispatcher $dispatcher,
        private readonly RunLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  small, non-secret facts about the event (shown on the run page)
     * @return Collection<int, AutomationRun>
     */
    public function start(string $trigger, Model $subject, ?string $eventKey = null, array $payload = []): Collection
    {
        $tenantId = (int) $subject->getAttribute('tenant_id');

        if ($this->context->id() === $tenantId) {
            return $this->startInTenant($trigger, $subject, $eventKey, $payload);
        }

        $tenant = Tenant::query()->find($tenantId);

        return $tenant ? $this->context->run($tenant, fn () => $this->startInTenant($trigger, $subject, $eventKey, $payload)) : collect();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, AutomationRun>
     */
    private function startInTenant(string $trigger, Model $subject, ?string $eventKey, array $payload): Collection
    {
        if (! $this->context->tenant()->isActive()
            || ! app(Entitlements::class)->canOperate($this->context->tenant())
            || ! $this->context->hasModule('automation')
            || ! app(AutomationCatalog::class)->trigger($trigger)) {
            return collect();
        }

        $depth = (int) Context::get('automation_depth', 0);

        $automations = Automation::query()->active()->where('trigger', $trigger)->with('nodes')->orderBy('id')->get();

        if ($automations->isEmpty()) {
            return collect();
        }

        if ($depth >= (int) config('automation.max_depth')) {
            Log::warning('automation.loop_prevented', [
                'tenant_id' => $this->context->id(),
                'trigger' => $trigger,
                'depth' => $depth,
                'automation_ids' => $automations->modelKeys(),
            ]);

            return collect();
        }

        $subjectType = AutomationRun::subjectTypeOf($subject);
        $eventKey ??= (string) Str::uuid();

        return $automations
            ->map(fn (Automation $automation) => $this->startRun($automation, $trigger, $subjectType, $subject, $eventKey, $payload, $depth))
            ->filter()
            ->values();
    }

    /** @param  array<string, mixed>  $payload */
    private function startRun(Automation $automation, string $trigger, string $subjectType, Model $subject, string $eventKey, array $payload, int $depth): ?AutomationRun
    {
        $steps = $automation->stepDefinitions();

        if ($steps === []) {
            return null;
        }

        $dedupeKey = Str::limit($automation->once_per_subject ? "{$subjectType}:{$subject->getKey()}" : $eventKey, 191, '');

        if (AutomationRun::query()->where('automation_id', $automation->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            // Savepoint: losing the race on the unique key must not abort an enclosing transaction.
            $run = DB::transaction(fn () => AutomationRun::query()->create([
                'automation_id' => $automation->id,
                'trigger' => $trigger,
                'subject_type' => $subjectType,
                'subject_id' => $subject->getKey(),
                'dedupe_key' => $dedupeKey,
                'depth' => $depth,
                'status' => RunStatus::Pending,
                'steps' => $steps,
                'payload' => $payload ?: null,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->logger->log($run, 'run.created', 'Queued by “'.AutomationCatalog::triggerLabel($trigger).'”.', context: $depth > 0 ? ['depth' => $depth] : []);
        $this->dispatcher->schedule($run, 0, now());

        return $run;
    }
}
