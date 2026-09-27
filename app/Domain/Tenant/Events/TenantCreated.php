<?php

namespace App\Domain\Tenant\Events;

use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TenantCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $owner,
    ) {}
}
