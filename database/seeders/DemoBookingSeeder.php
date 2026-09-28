<?php

namespace Database\Seeders;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Actions\ChangeAppointmentStatus;
use App\Domain\Booking\Actions\RecordAppointmentPayment;
use App\Domain\Booking\Actions\SaveBookingResource;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Customer\Models\Customer;
use App\Domain\Service\Actions\SaveService;
use App\Domain\Service\Models\Service;
use App\Domain\Service\Models\ServiceCategory;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo services, staff/turfs and appointments for ABC Salon and ABC Turf, created through
 * the domain actions. Runs once per tenant (skips if the tenant already has resources).
 */
class DemoBookingSeeder extends Seeder
{
    public function run(TenantContext $context, SaveService $saveService, SaveBookingResource $saveResource, BookAppointment $book, ChangeAppointmentStatus $changeStatus, RecordAppointmentPayment $recordPayment): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoBookingSeeder must not run in production.');
        }

        if ($salon = Tenant::query()->where('slug', 'abc-salon')->first()) {
            $context->run($salon, fn (Tenant $tenant) => $this->salon($tenant, $saveService, $saveResource, $book, $changeStatus));
        }

        if ($turf = Tenant::query()->where('slug', 'abc-turf')->first()) {
            $context->run($turf, function () use ($saveResource, $book, $recordPayment) {
                $this->turf($saveResource, $book);
                $this->turfRates($saveResource, $recordPayment);
            });
        }
    }

    /** Hourly, evening-peak and weekend rates for the turfs, and an advance on the first booking (Phase 10). */
    private function turfRates(SaveBookingResource $saveResource, RecordAppointmentPayment $recordPayment): void
    {
        $rates = [
            'Turf A (5-a-side)' => [1000, 1400, 1600],
            'Turf B (7-a-side)' => [1500, 2000, 2400],
        ];

        foreach ($rates as $name => [$hourly, $peak, $weekend]) {
            $resource = BookingResource::query()->where('name', $name)->first();

            if (! $resource || $resource->hourly_rate !== null) {
                continue;
            }

            $saveResource->handle([
                'name' => $resource->name,
                'color' => $resource->color,
                'hourly_rate' => $hourly,
                'rates' => [
                    ['label' => 'Evening peak', 'weekdays' => [1, 2, 3, 4, 5], 'from' => '18:00', 'to' => '23:00', 'hourly_rate' => $peak],
                    ['label' => 'Weekend', 'weekdays' => [6, 7], 'from' => '06:00', 'to' => '24:00', 'hourly_rate' => $weekend],
                ],
            ], $resource);
        }

        $appointment = Appointment::query()
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
            ->where('price', '>', 500)
            ->doesntHave('payments')
            ->oldest('id')
            ->first();

        if ($appointment) {
            $owner = User::query()->where('email', 'owner@abc-turf.test')->first();
            $recordPayment->handle($appointment, ['amount' => 500, 'method' => 'upi', 'reference' => 'ADV-DEMO-1'], $owner);
        }
    }

    private function salon(Tenant $tenant, SaveService $saveService, SaveBookingResource $saveResource, BookAppointment $book, ChangeAppointmentStatus $changeStatus): void
    {
        if (BookingResource::query()->exists()) {
            return;
        }

        $owner = User::query()->where('email', 'owner@abc-salon.test')->first();
        $category = fn (string $name) => ServiceCategory::query()->where('name', $name)->value('id');

        $services = collect([
            ['name' => 'Haircut & styling', 'service_category_id' => $category('Hair'), 'duration_minutes' => 45, 'price' => 600],
            ['name' => 'Hair colour', 'service_category_id' => $category('Hair'), 'duration_minutes' => 90, 'price' => 2500],
            ['name' => 'Keratin treatment', 'service_category_id' => $category('Hair'), 'duration_minutes' => 120, 'price' => 6000],
            ['name' => 'Classic facial', 'service_category_id' => $category('Skin'), 'duration_minutes' => 60, 'price' => 1500],
            ['name' => 'Manicure', 'service_category_id' => $category('Nails'), 'duration_minutes' => 30, 'price' => 500],
            ['name' => 'Bridal makeup', 'service_category_id' => $category('Makeup'), 'duration_minutes' => 180, 'price' => 15000],
        ])->mapWithKeys(fn (array $data) => [$data['name'] => $saveService->handle($data, actor: $owner)]);

        $membership = fn (string $email) => TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->value('id');

        $ids = fn (array $names) => $services->only($names)->map(fn (Service $service) => $service->id)->values()->all();

        $sana = $saveResource->handle([
            'name' => 'Sana',
            'description' => 'Senior stylist',
            'color' => '#db2777',
            'tenant_user_id' => $membership('staff@abc-salon.test'),
            'service_ids' => $ids(['Haircut & styling', 'Hair colour', 'Keratin treatment', 'Bridal makeup']),
        ]);

        $riya = $saveResource->handle([
            'name' => 'Riya',
            'description' => 'Skin and nail specialist',
            'color' => '#0ea5e9',
            'service_ids' => $ids(['Classic facial', 'Manicure', 'Haircut & styling']),
        ]);

        $kavya = Customer::query()->where('name', 'Kavya Rao')->first();
        $rohan = Customer::query()->where('name', 'Rohan Mehta')->first();
        // Default salon hours are Monday–Saturday.
        $nextOpenDay = fn ($after) => $after->copy()->addDay()->isSunday() ? $after->copy()->addDays(2)->startOfDay() : $after->copy()->addDay()->startOfDay();
        $first = $nextOpenDay(TenantTime::now());
        $second = $nextOpenDay($first);

        $booked = [
            [$sana, 'Haircut & styling', $first->copy()->setTime(11, 0), $rohan ? ['customer_id' => $rohan->id] : ['customer' => ['name' => 'Rohan Mehta']]],
            [$sana, 'Hair colour', $first->copy()->setTime(14, 0), $kavya ? ['customer_id' => $kavya->id] : ['customer' => ['name' => 'Kavya Rao']]],
            [$riya, 'Classic facial', $first->copy()->setTime(12, 0), ['customer' => ['name' => 'Ishita Verma', 'phone' => '98765 20001']]],
            [$riya, 'Manicure', $second->copy()->setTime(16, 30), ['customer' => ['name' => 'Pooja Nair', 'phone' => '98765 20002']]],
        ];

        foreach ($booked as [$resource, $service, $start, $customer]) {
            $book->handle([
                ...$customer,
                'booking_resource_id' => $resource->id,
                'service_id' => $services[$service]->id,
                'starts_at' => $start->copy()->utc(),
            ], $owner);
        }

        // History: past visits cannot be booked through the action (it refuses past times).
        $history = [
            [$sana, 'Haircut & styling', 7, 11, $kavya, AppointmentStatus::Completed],
            [$riya, 'Classic facial', 5, 15, $kavya, AppointmentStatus::Completed],
            [$sana, 'Hair colour', 3, 12, $rohan, AppointmentStatus::NoShow],
            [$riya, 'Manicure', 2, 17, $rohan, AppointmentStatus::Completed],
        ];

        foreach ($history as [$resource, $service, $daysAgo, $hour, $customer, $status]) {
            if (! $customer) {
                continue;
            }

            $start = TenantTime::now()->subDays($daysAgo)->setTime($hour, 0)->utc();
            $appointment = Appointment::query()->create([
                'customer_id' => $customer->id,
                'booking_resource_id' => $resource->id,
                'service_id' => $services[$service]->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes($services[$service]->duration_minutes),
                'status' => AppointmentStatus::Confirmed,
                'price' => $services[$service]->price,
                'confirmed_at' => $start->copy()->subDay(),
                'created_by_user_id' => $owner?->id,
            ]);
            $changeStatus->handle($appointment, $status, $owner);
        }
    }

    private function turf(SaveBookingResource $saveResource, BookAppointment $book): void
    {
        if (BookingResource::query()->exists()) {
            return;
        }

        $owner = User::query()->where('email', 'owner@abc-turf.test')->first();
        $turfA = $saveResource->handle(['name' => 'Turf A (5-a-side)', 'color' => '#16a34a']);
        $saveResource->handle(['name' => 'Turf B (7-a-side)', 'color' => '#f59e0b']);

        $book->handle([
            'customer' => ['name' => 'Weekend Warriors FC', 'phone' => '98765 30001'],
            'booking_resource_id' => $turfA->id,
            'starts_at' => TenantTime::now()->addDay()->setTime(19, 0)->utc(),
            'duration_minutes' => 60,
            'price' => 1200,
        ], $owner);
    }
}
