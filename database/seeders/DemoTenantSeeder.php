<?php

namespace Database\Seeders;

use App\Domain\RBAC\Actions\AssignRole;
use App\Domain\RBAC\Models\Role;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Tenant\Actions\CreateTenant;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo data: sample tenants to exercise isolation by hand. Password for all: "password".
 * Refuses to run in production.
 */
class DemoTenantSeeder extends Seeder
{
    /** Saffron Arena Sports Turf, Nashik (public Google listing details for a realistic turf demo). */
    private const TURF_DEMO = [
        'name' => 'Saffron Arena',
        'branding' => [
            'primary_color' => '#c2410c',
            'tagline' => 'Cricket, football and more — floodlit turf in Nashik Road',
        ],
        'profile' => [
            'phone' => '+91 75584 30055',
            'email' => 'hello@abc-turf.test',
            'city' => 'Nashik',
            'address' => 'STP Road, Pushpak Nagar, Lokhande Mala, Nashik Road, Nashik, Maharashtra 422006',
            'description' => 'Multisport turf near Sandip University: cricket and football pitches, pickleball, parking and Saffron Sip cafe. Open 24 hours.',
        ],
    ];

    public function run(CreateTenant $createTenant, AssignRole $assignRole, TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoTenantSeeder must not run in production.');
        }

        $salon = $this->tenant($createTenant, 'owner@abc-salon.test', 'Asha Owner', 'ABC Salon', 'abc-salon', 'beauty_salon', [
            'branding' => ['primary_color' => '#db2777', 'tagline' => 'Hair, skin and nails in the heart of Pune'],
            'profile' => ['phone' => '+91 98765 43210', 'city' => 'Pune', 'description' => 'A neighbourhood salon offering haircuts, colour, facials and bridal packages.'],
        ]);
        $this->tenant($createTenant, 'owner@abc-studio.test', 'Arjun Owner', 'ABC Studio', 'abc-studio', 'photo_studio', [
            'branding' => ['primary_color' => '#0f766e', 'tagline' => 'Weddings, portraits and product shoots'],
            'profile' => ['phone' => '+91 90000 44444', 'city' => 'Pune', 'description' => 'A photo studio for weddings, maternity, portraits, products and passport photos.'],
        ]);
        $turf = $this->tenant(
            $createTenant,
            'owner@abc-turf.test',
            'Tarun Owner',
            self::TURF_DEMO['name'],
            'abc-turf',
            'turf',
            [
                'branding' => self::TURF_DEMO['branding'],
                'profile' => self::TURF_DEMO['profile'],
            ],
        );
        $this->refreshTurfDemo($context, $turf);
        $coaching = $this->tenant($createTenant, 'owner@abc-coaching.test', 'Kiran Owner', 'ABC Coaching', 'abc-coaching', 'coaching', [
            'branding' => ['primary_color' => '#2563eb', 'tagline' => 'Maths and science coaching for classes 8 to 12'],
            'profile' => ['phone' => '+91 90000 11111', 'city' => 'Pune', 'description' => 'Small batches, weekly tests and personal doubt-solving for school and board exams.'],
        ]);
        $this->tenant($createTenant, 'owner@abc-cafe.test', 'Chetan Owner', 'ABC Cafe', 'abc-cafe', 'cafe', [
            'branding' => ['primary_color' => '#b45309', 'tagline' => 'All-day breakfast, coffee and comfort food'],
            'profile' => ['phone' => '+91 90000 22222', 'city' => 'Pune', 'description' => 'A cosy neighbourhood cafe with indoor and garden seating.'],
        ]);
        $this->tenant($createTenant, 'owner@abc-store.test', 'Sunil Owner', 'ABC Store', 'abc-store', 'local_store', [
            'branding' => ['primary_color' => '#0d9488', 'tagline' => 'Daily groceries, delivered in your neighbourhood'],
            'profile' => ['phone' => '+91 90000 33333', 'city' => 'Pune'],
        ]);

        $staff = $this->user('staff@abc-salon.test', 'Sana Staff');
        $this->member($context, $assignRole, $salon, $staff, 'staff');

        $teacher = $this->user('teacher@abc-coaching.test', 'Tanvi Teacher');
        $this->member($context, $assignRole, $coaching, $teacher, 'staff');

        $manager = $this->user('manager@autowave.test', 'Meera Manager');
        $this->member($context, $assignRole, $salon, $manager, 'manager');
        $this->member($context, $assignRole, $turf, $manager, 'manager');

        $this->command?->info('Demo tenants: abc-salon, abc-studio, abc-turf (Saffron Arena, Nashik), abc-coaching, abc-cafe, abc-store. Owners: owner@<slug>.test; also staff@abc-salon.test, teacher@abc-coaching.test, manager@autowave.test (password: password).');
    }

    /** Keep the turf demo aligned with the Saffron Arena listing when the seeder is re-run. */
    private function refreshTurfDemo(TenantContext $context, Tenant $turf): void
    {
        $turf->forceFill(['name' => self::TURF_DEMO['name']])->save();

        $context->run($turf, function () use ($context) {
            $existingBranding = $context->setting('branding', []);
            $existingProfile = $context->setting('business_profile', []);

            TenantSetting::query()->updateOrCreate(['key' => 'branding'], ['value' => [
                ...(is_array($existingBranding) ? $existingBranding : []),
                'business_name' => self::TURF_DEMO['name'],
                'primary_color' => self::TURF_DEMO['branding']['primary_color'],
                'tagline' => self::TURF_DEMO['branding']['tagline'],
            ]]);

            TenantSetting::query()->updateOrCreate(['key' => 'business_profile'], ['value' => [
                ...(is_array($existingProfile) ? $existingProfile : []),
                ...self::TURF_DEMO['profile'],
            ]]);

            $hours = collect(range(1, 7))->map(fn (int $day) => [
                'weekday' => $day,
                'starts_at' => '00:00',
                'ends_at' => '23:59',
            ])->all();

            app(BookingSettings::class)->update(['default_hours' => $hours]);
        });
    }

    private function tenant(CreateTenant $createTenant, string $email, string $name, string $business, string $slug, string $type, array $options): Tenant
    {
        return Tenant::query()->where('slug', $slug)->first()
            ?? $createTenant->handle($this->user($email, $name), $business, $type, ['slug' => $slug, ...$options]);
    }

    private function user(string $email, string $name): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        return $user;
    }

    private function member(TenantContext $context, AssignRole $assignRole, Tenant $tenant, User $user, string $roleSlug): void
    {
        $context->run($tenant, function (Tenant $tenant) use ($assignRole, $user, $roleSlug) {
            $membership = TenantUser::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['status' => MembershipStatus::Active, 'joined_at' => now()],
            );

            $assignRole->handle($membership, Role::query()->where('slug', $roleSlug)->firstOrFail());
        });
    }
}
