<?php

namespace App\Domain\Onboarding\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Business\Models\BusinessType;
use App\Domain\Tenant\Actions\CreateTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

/**
 * Self-service onboarding: turns the wizard's answers into a fully provisioned
 * workspace with no Super Admin involvement (master prompt §20).
 */
class OnboardBusiness
{
    public function __construct(
        private readonly CreateTenant $createTenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     slug: string,
     *     modules: list<string>,
     *     website_template: string,
     *     primary_color?: ?string,
     *     tagline?: ?string,
     *     phone: string,
     *     email?: ?string,
     *     city: string,
     *     address?: ?string,
     *     description?: ?string,
     * }  $data
     */
    public function handle(User $user, BusinessType $type, array $data): Tenant
    {
        $tenant = $this->createTenant->handle($user, $data['name'], $type, [
            'slug' => $data['slug'],
            'created_by' => $user,
            'modules' => $data['modules'],
            'website_template' => $data['website_template'],
            'branding' => [
                'primary_color' => $data['primary_color'] ?? null,
                'tagline' => $data['tagline'] ?? null,
            ],
            'profile' => [
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'city' => $data['city'],
                'address' => $data['address'] ?? null,
                'description' => $data['description'] ?? null,
            ],
        ]);

        $this->audit->log('tenant.created', $tenant, [
            'source' => 'onboarding',
            'business_type' => $type->code,
            'business_type_version' => $type->version,
            'website_template' => $data['website_template'],
        ], $tenant->id);

        return $tenant;
    }

    public static function limitReached(User $user): bool
    {
        return Tenant::query()->where('created_by_user_id', $user->getKey())->count()
            >= (int) config('autowave.onboarding.max_businesses_per_user', 3);
    }
}
