<?php

namespace Tests\Concerns;

use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Actions\CreateLead;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;

trait CreatesCrmRecords
{
    /**
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    protected function inTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->run($tenant, $callback);
    }

    /** @param  array<string, mixed>  $data */
    protected function makeLead(Tenant $tenant, array $data = [], ?User $actor = null): Lead
    {
        static $sequence = 0;
        $sequence++;

        return $this->inTenant($tenant, fn () => app(CreateLead::class)->handle([
            'name' => 'Lead '.$sequence,
            'phone' => '9000'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            ...$data,
        ], $actor));
    }

    /** @param  array<string, mixed>  $data */
    protected function makeCustomer(Tenant $tenant, array $data = [], ?User $actor = null): Customer
    {
        static $sequence = 0;
        $sequence++;

        return $this->inTenant($tenant, fn () => app(CreateCustomer::class)->handle([
            'name' => 'Customer '.$sequence,
            'phone' => '7000'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            ...$data,
        ], $actor));
    }

    protected function stage(Tenant $tenant, string $code): LeadStage
    {
        return $this->inTenant($tenant, fn () => LeadStage::query()->where('code', $code)->firstOrFail());
    }

    protected function membershipOf(Tenant $tenant, User $user): TenantUser
    {
        return TenantUser::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
    }
}
