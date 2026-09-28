<?php

namespace App\Domain\Lead\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Events\LeadCreated;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\Phone;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Creates a lead in the current tenant.
 *
 * - Stage defaults to the first open stage; source defaults to crm.default_source.
 * - A live customer with the same phone (or email) is linked automatically.
 * - Only one open lead per phone number: a duplicate is rejected with a validation error.
 * - Assigns the given member, or auto-assigns when the tenant's `crm.auto_assign` setting is on.
 */
class CreateLead
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RecordActivity $recordActivity,
        private readonly AssignLead $assignLead,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     phone?: ?string,
     *     email?: ?string,
     *     interest?: ?string,
     *     estimated_value?: numeric-string|float|int|null,
     *     lead_stage_id?: ?int,
     *     lead_source_id?: ?int,
     *     source?: ?string,
     *     assigned_tenant_user_id?: ?int,
     *     next_followup_at?: ?DateTimeInterface,
     *     notes?: ?string,
     * }  $data  `source` is a source code, used when lead_source_id is absent
     */
    public function handle(array $data, ?User $actor = null): Lead
    {
        $stage = $this->stage($data['lead_stage_id'] ?? null);
        $source = $this->source($data['lead_source_id'] ?? null, $data['source'] ?? null);
        $phone = Phone::normalize($data['phone'] ?? null);

        if ($existing = self::openDuplicateOf($phone)) {
            throw ValidationException::withMessages([
                'phone' => "An open lead with this phone number already exists ({$existing->name}).",
            ]);
        }

        $assignee = isset($data['assigned_tenant_user_id'])
            ? TenantUser::query()->where('tenant_id', $this->context->tenant()->id)->find($data['assigned_tenant_user_id'])
            : null;

        if (isset($data['assigned_tenant_user_id']) && (! $assignee || ! $this->assignLead->isAssignable($assignee))) {
            throw ValidationException::withMessages(['assigned_tenant_user_id' => 'This team member cannot be assigned leads.']);
        }

        return DB::transaction(function () use ($data, $actor, $stage, $source, $phone, $assignee) {
            $lead = Lead::query()->create([
                'lead_stage_id' => $stage->id,
                'lead_source_id' => $source?->id,
                'customer_id' => $this->matchingCustomer($phone, $data['email'] ?? null)?->id,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'interest' => $data['interest'] ?? null,
                'estimated_value' => $data['estimated_value'] ?? null,
                'next_followup_at' => $data['next_followup_at'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);
            $lead->setRelation('stage', $stage)->setRelation('source', $source);

            $this->recordActivity->handle('created', lead: $lead, actor: $actor, metadata: [
                'source' => $source?->name,
                'stage' => $stage->name,
            ]);

            if (filled($data['notes'] ?? null)) {
                $this->recordActivity->handle('note', lead: $lead, actor: $actor, body: $data['notes']);
            }

            if ($assignee) {
                $this->assignLead->handle($lead, $assignee, $actor);
            } elseif ($this->context->setting('crm', [])['auto_assign'] ?? false) {
                $this->assignLead->autoAssign($lead, $actor);
            }

            LeadCreated::dispatch($lead);

            return $lead;
        });
    }

    public static function openDuplicateOf(?string $normalizedPhone, ?int $ignoreId = null): ?Lead
    {
        if (! $normalizedPhone) {
            return null;
        }

        return Lead::query()->open()
            ->where('phone_normalized', $normalizedPhone)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->first();
    }

    private function stage(?int $id): LeadStage
    {
        if ($id === null) {
            return LeadStage::firstFor(StageOutcome::Open)
                ?? throw new LogicException('The tenant has no active open lead stage; run ProvisionCrm.');
        }

        $stage = LeadStage::query()->active()->find($id);

        if (! $stage || $stage->outcome !== StageOutcome::Open) {
            throw ValidationException::withMessages(['lead_stage_id' => 'New leads must start in an active open stage.']);
        }

        return $stage;
    }

    private function source(?int $id, ?string $code): ?LeadSource
    {
        if ($id !== null) {
            return LeadSource::query()->active()->find($id)
                ?? throw ValidationException::withMessages(['lead_source_id' => 'Choose an active lead source.']);
        }

        return LeadSource::query()->where('code', $code ?? config('crm.default_source'))->first();
    }

    private function matchingCustomer(?string $normalizedPhone, ?string $email): ?Customer
    {
        if ($normalizedPhone) {
            return Customer::query()->where('phone_normalized', $normalizedPhone)->first();
        }

        return filled($email) ? Customer::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first() : null;
    }
}
