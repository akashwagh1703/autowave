<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Services + booking (Phase 4, ADR-014): service catalogue, bookable resources with working hours
 * and time off, and appointments.
 *
 * Cross-row references use composite foreign keys (id, tenant_id), as in the CRM tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_category_id')->nullable();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['service_category_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('service_categories');

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'service_category_id']);
            $table->index(['tenant_id', 'name']);
            $table->index('service_category_id');
            $table->index('created_by_user_id');
        });

        Schema::create('booking_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_user_id')->nullable();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->string('color', 7)->default('#6366f1');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['tenant_user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('tenant_users');

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'sort_order']);
            $table->index('tenant_user_id');
        });

        // A team member is linked to at most one live resource (their own calendar).
        DB::statement('CREATE UNIQUE INDEX booking_resources_member_unique ON booking_resources (tenant_id, tenant_user_id) WHERE deleted_at IS NULL AND tenant_user_id IS NOT NULL');

        Schema::create('booking_resource_service', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('booking_resource_id');
            $table->unsignedBigInteger('service_id');

            $table->primary(['booking_resource_id', 'service_id']);
            $table->foreign(['booking_resource_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('booking_resources')->cascadeOnDelete();
            $table->foreign(['service_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('services')->cascadeOnDelete();
            $table->index(['tenant_id', 'service_id']);
        });

        Schema::create('resource_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('booking_resource_id');
            $table->unsignedSmallInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');

            $table->foreign(['booking_resource_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('booking_resources')->cascadeOnDelete();
            $table->index(['booking_resource_id', 'weekday']);
            $table->index('tenant_id');
        });

        DB::statement('ALTER TABLE resource_working_hours ADD CONSTRAINT resource_working_hours_valid CHECK (weekday BETWEEN 1 AND 7 AND ends_at > starts_at)');

        Schema::create('resource_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('booking_resource_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 150)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['booking_resource_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('booking_resources')->cascadeOnDelete();
            $table->index(['tenant_id', 'booking_resource_id', 'starts_at']);
            $table->index('booking_resource_id');
            $table->index('created_by_user_id');
        });

        DB::statement('ALTER TABLE resource_time_off ADD CONSTRAINT resource_time_off_valid CHECK (ends_at > starts_at)');

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('booking_resource_id');
            $table->unsignedBigInteger('service_id')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 12)->default('confirmed');
            $table->decimal('price', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 30)->default('manual');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['customer_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('customers');
            $table->foreign(['booking_resource_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('booking_resources');
            $table->foreign(['service_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('services');

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['tenant_id', 'booking_resource_id', 'starts_at']);
            $table->index(['tenant_id', 'customer_id', 'starts_at']);
            $table->index(['tenant_id', 'status']);
            $table->index('customer_id');
            $table->index('booking_resource_id');
            $table->index('service_id');
            $table->index('created_by_user_id');
        });

        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_valid CHECK (ends_at > starts_at AND status IN ('pending', 'confirmed', 'completed', 'cancelled', 'no_show'))");

        // Double-booking guard: a resource cannot hold two overlapping appointments that occupy time.
        // The resource id is expressed as a single-value int8range so plain GiST range operators work
        // without the btree_gist extension (unavailable on the shared PostgreSQL 11 server).
        DB::statement(<<<'SQL'
            ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap EXCLUDE USING gist (
                int8range(booking_resource_id, booking_resource_id, '[]') WITH &&,
                tsrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (status IN ('pending', 'confirmed', 'completed'))
            SQL);

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('appointment_id')->nullable()->after('customer_id');

            $table->foreign(['appointment_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('appointments')->cascadeOnDelete();
            $table->index(['tenant_id', 'appointment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['appointment_id', 'tenant_id']);
            $table->dropIndex(['tenant_id', 'appointment_id', 'occurred_at']);
            $table->dropColumn('appointment_id');
        });

        Schema::dropIfExists('appointments');
        Schema::dropIfExists('resource_time_off');
        Schema::dropIfExists('resource_working_hours');
        Schema::dropIfExists('booking_resource_service');
        Schema::dropIfExists('booking_resources');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
