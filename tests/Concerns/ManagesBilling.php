<?php

namespace Tests\Concerns;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;

trait ManagesBilling
{
    /** Manual payment details filled in, plus any overrides (top-level groups replace whole). */
    protected function configureBilling(array $values = []): void
    {
        app(BillingSettings::class)->update([
            'manual_enabled' => true,
            'upi' => ['id' => 'autowave@okaxis', 'payee' => 'AutoWave'],
            'bank' => ['account_name' => 'AutoWave', 'account_number' => '123456789012', 'ifsc' => 'HDFC0001234', 'bank_name' => 'HDFC Bank'],
            'seller' => ['name' => 'AutoWave', 'address' => 'Pune, Maharashtra', 'email' => 'billing@autowave.test', 'phone' => null],
            ...$values,
        ]);
    }

    protected function enableGst(string $gstin = '27ABCDE1234F1Z5'): void
    {
        app(BillingSettings::class)->update(['gst' => ['enabled' => true, 'gstin' => $gstin, 'state_code' => substr($gstin, 0, 2)]]);
    }

    protected function subscriptionOf(Tenant $tenant): Subscription
    {
        return Subscription::withoutTenantScope()->with(['plan', 'nextPlan'])->where('tenant_id', $tenant->id)->firstOrFail();
    }

    /** Moves the end of the trial or paid period, e.g. into the past to test what happens after it. */
    protected function endsAt(Tenant $tenant, ?Carbon $endsAt, array $attributes = []): void
    {
        Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)->update(['ends_at' => $endsAt, ...$attributes]);
        $this->app->forgetScopedInstances();
    }
}
