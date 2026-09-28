<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Appointment;
use App\Domain\Service\Actions\DeleteService;
use App\Domain\Service\Actions\DeleteServiceCategory;
use App\Domain\Service\Actions\SaveServiceCategory;
use App\Domain\Service\Models\Service;
use App\Domain\Service\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_a_service_is_created_with_its_category_and_resources(): void
    {
        $tenant = $this->createTenant();
        $sana = $this->makeResource($tenant);
        $hair = $this->inTenant($tenant, fn () => ServiceCategory::query()->where('name', 'Hair')->sole());

        $service = $this->makeService($tenant, ['name' => 'Haircut', 'service_category_id' => $hair->id, 'duration_minutes' => 45, 'price' => 450], [$sana]);

        $this->assertSame($hair->id, $service->service_category_id);
        $this->assertSame('450.00', $service->price);
        $this->assertSame(1, DB::table('booking_resource_service')->where('service_id', $service->id)->where('tenant_id', $tenant->id)->count());
        $this->inTenant($tenant, fn () => $this->assertTrue($sana->offers($service)));
    }

    public function test_service_names_are_unique_per_tenant_ignoring_case(): void
    {
        $tenant = $this->createTenant();
        $this->makeService($tenant, ['name' => 'Haircut']);

        $this->assertRejected('name', fn () => $this->makeService($tenant, ['name' => 'HAIRCUT']));

        // Another tenant may use the same name.
        $this->makeService($this->createTenant('Other Salon'), ['name' => 'Haircut']);
    }

    public function test_ids_from_another_tenant_are_rejected(): void
    {
        $tenant = $this->createTenant();
        $other = $this->createTenant('Other Salon');
        $theirCategory = $this->inTenant($other, fn () => ServiceCategory::query()->firstOrFail());
        $theirResource = $this->makeResource($other);

        $this->assertRejected('service_category_id', fn () => $this->makeService($tenant, ['service_category_id' => $theirCategory->id]));
        $this->assertRejected('resource_ids', fn () => $this->makeService($tenant, [], [$theirResource]));
    }

    public function test_deleting_a_service_keeps_past_appointments_readable(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $service = $this->makeService($tenant, ['name' => 'Facial'], [$resource]);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00', ['service_id' => $service->id]);

        $this->inTenant($tenant, fn () => app(DeleteService::class)->handle($service));

        $this->assertSoftDeleted('services', ['id' => $service->id]);
        $this->assertSame(0, DB::table('booking_resource_service')->where('service_id', $service->id)->count());
        $this->inTenant($tenant, fn () => $this->assertSame('Facial', Appointment::query()->findOrFail($appointment->id)->service->name));

        // The name can be reused once the old service is deleted.
        $this->makeService($tenant, ['name' => 'Facial']);
    }

    public function test_categories_are_unique_and_deleting_one_uncategorises_its_services(): void
    {
        $tenant = $this->createTenant();
        $category = $this->inTenant($tenant, fn () => app(SaveServiceCategory::class)->handle('Bridal'));
        $service = $this->makeService($tenant, ['service_category_id' => $category->id]);

        $this->assertRejected('name', fn () => $this->inTenant($tenant, fn () => app(SaveServiceCategory::class)->handle('bridal')));

        $this->inTenant($tenant, fn () => app(DeleteServiceCategory::class)->handle($category));

        $this->assertDatabaseMissing('service_categories', ['id' => $category->id]);
        $this->assertNull($this->inTenant($tenant, fn () => $service->fresh())->service_category_id);
    }

    public function test_services_can_be_managed_over_http(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/services'), [
            'name' => ' Hair   spa ',
            'duration_minutes' => 60,
            'price' => 1200,
            'is_active' => true,
            'resource_ids' => [$resource->id],
        ])->assertSessionHasNoErrors()->assertRedirect($this->appUrl('/services'));

        $service = $this->inTenant($tenant, fn () => Service::query()->where('name', 'Hair spa')->sole());

        $this->get($this->appUrl('/services?category=none'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/services/Index')
                ->where('services.meta.total', 1)
                ->where('services.data.0.name', 'Hair spa')
                ->has('categories', 5));

        $this->post($this->appUrl('/services/bulk'), ['action' => 'deactivate', 'ids' => [$service->id]])->assertRedirect();
        $this->assertFalse($this->inTenant($tenant, fn () => $service->fresh())->is_active);

        $this->post($this->appUrl('/service-categories'), ['name' => 'Bridal'])->assertSessionHasNoErrors();
        $this->assertTrue($this->inTenant($tenant, fn () => ServiceCategory::query()->where('name', 'Bridal')->exists()));

        $this->delete($this->appUrl("/services/{$service->id}"))->assertRedirect($this->appUrl('/services'));
        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    private function assertRejected(string $field, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}
