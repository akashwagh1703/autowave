<?php

namespace App\Domain\Automation\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Support\DefinitionValidator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates an automation from a builder definition (see DefinitionValidator). The steps
 * are replaced as a whole. Runs already in progress keep the steps they started with.
 */
class SaveAutomation
{
    public function __construct(
        private readonly DefinitionValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input, ?Automation $automation = null, ?User $actor = null, ?string $templateKey = null): Automation
    {
        $definition = $this->validator->validate($input);

        if (! $automation && Automation::query()->count() >= (int) config('automation.limits.automations')) {
            throw ValidationException::withMessages(['name' => __('This business already has the maximum of :max automations.', ['max' => config('automation.limits.automations')])]);
        }

        return DB::transaction(function () use ($definition, $automation, $actor, $templateKey) {
            $attributes = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'trigger' => $definition['trigger'],
                'is_active' => $definition['is_active'],
                'once_per_subject' => $definition['once_per_subject'],
            ];

            $creating = $automation === null;

            if ($automation) {
                $automation->update($attributes);
                $automation->nodes()->delete();
            } else {
                $automation = Automation::query()->create([
                    ...$attributes,
                    'template_key' => $templateKey,
                    'created_by_user_id' => $actor?->id,
                ]);
            }

            foreach ($definition['steps'] as $position => $step) {
                $automation->nodes()->create([
                    'tenant_id' => $automation->tenant_id,
                    'position' => $position,
                    'type' => $step['type'],
                    'action' => $step['action'],
                    'config' => $step['config'],
                ]);
            }

            $automation->unsetRelation('nodes');

            if ($actor) {
                $this->audit->log($creating ? 'automation.created' : 'automation.updated', $automation, [
                    'name' => $automation->name,
                    'trigger' => $automation->trigger,
                    'steps' => count($definition['steps']),
                    'is_active' => $automation->is_active,
                ]);
            }

            return $automation;
        });
    }
}
