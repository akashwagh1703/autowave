<?php

namespace Tests\Feature\AI;

use App\Domain\AI\Assistant\AssistantTools;
use App\Domain\Food\Actions\BookReservation;
use App\Domain\Food\Actions\SaveDiningTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesEducationRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Assistant tools for the Phase 10 verticals: offered only with the engine and permission, no contact details. */
class VerticalAssistantToolsTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesEducationRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_coaching_students_tool_lists_batches_and_overdue_fees(): void
    {
        $tenant = $this->createCoaching();
        $owner = $this->ownerOf($tenant);
        $course = $this->makeCourse($tenant, ['name' => 'Class 10 Maths']);
        $batch = $this->makeBatch($tenant, $course, ['name' => 'Evening', 'capacity' => 10]);
        $student = $this->makeCustomer($tenant, ['name' => 'Aarav Shah', 'phone' => '9876512345', 'email' => 'aarav@example.com']);
        $this->admit($tenant, $batch, [
            'customer_id' => $student->id,
            'enrolled_on' => '2026-09-01',
            'instalments' => [['due_on' => '2026-10-01', 'amount' => '12000.00']],
        ]);

        $tools = app(AssistantTools::class);
        [$names, $result] = $this->inTenant($tenant, fn () => [
            array_column($tools->definitions($owner), 'name'),
            $tools->call('students', [], $owner),
        ]);

        $this->assertContains('students', $names);
        $this->assertNotContains('reservations', $names);
        $this->assertSame(1, $result['active_students']);
        $this->assertSame(['course' => 'Class 10 Maths', 'batch' => 'Evening'], array_intersect_key($result['batches'][0], ['course' => 1, 'batch' => 1]));
        $this->assertSame(9, $result['batches'][0]['seats_left']);
        $this->assertSame('Aarav', $result['overdue_fees'][0]['student']);
        $this->assertEquals(12000, $result['overdue_fees'][0]['overdue']);

        $json = json_encode($result);
        $this->assertStringNotContainsString('98765', $json);
        $this->assertStringNotContainsString('@example.com', $json);

        // Staff see students but not fees.
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->assertSame('Not permitted', $this->inTenant($tenant, fn () => $tools->call('students', [], $staff))['overdue_fees']);
    }

    public function test_cafe_reservations_tool_counts_guests(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $owner = $this->ownerOf($tenant);
        $guest = $this->makeCustomer($tenant, ['name' => 'Neha Kapoor', 'phone' => '9876598765']);

        $this->inTenant($tenant, function () use ($tenant, $guest) {
            $table = app(SaveDiningTable::class)->handle(['name' => 'T1', 'seats' => 4]);
            app(BookReservation::class)->handle(['customer_id' => $guest->id, 'dining_table_id' => $table->id, 'party_size' => 4, 'reserved_at' => $this->local($tenant, '2026-10-05 20:00')]);
            app(BookReservation::class)->handle(['customer_id' => $guest->id, 'party_size' => 2, 'reserved_at' => $this->local($tenant, '2026-10-05 13:00')]);
        });

        $tools = app(AssistantTools::class);
        [$names, $result] = $this->inTenant($tenant, fn () => [
            array_column($tools->definitions($owner), 'name'),
            $tools->call('reservations', ['date_from' => '2026-10-05', 'date_to' => '2026-10-05'], $owner),
        ]);

        $this->assertContains('reservations', $names);
        $this->assertNotContains('students', $names);
        $this->assertSame(2, $result['matching']);
        $this->assertSame(6, $result['guests_expected']);
        $this->assertSame(['Neha', 'Neha'], array_column($result['reservations'], 'guest'));
        $this->assertSame([null, 'T1'], array_column($result['reservations'], 'table'));
        $this->assertStringNotContainsString('98765', json_encode($result));
    }

    public function test_a_salon_gets_neither_tool(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $tools = app(AssistantTools::class);

        [$names, $students] = $this->inTenant($tenant, fn () => [
            array_column($tools->definitions($owner), 'name'),
            $tools->call('students', [], $owner),
        ]);

        $this->assertNotContains('students', $names);
        $this->assertNotContains('reservations', $names);
        $this->assertSame(['error' => 'This tool is not available.'], $students);
    }
}
