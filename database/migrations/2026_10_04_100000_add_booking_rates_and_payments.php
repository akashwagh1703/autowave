<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking prices and payments, Phase 10 (ADR-020).
 *
 * - booking_resources.hourly_rate / rates: the price of a booking without a service (a turf slot).
 *   `rates` is an ordered list of {label, weekdays, from, to, hourly_rate}; the first rule covering a
 *   minute wins, otherwise hourly_rate applies.
 * - appointment_payments: amounts received for an appointment (an advance, or the full price).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_resources', function (Blueprint $table) {
            $table->decimal('hourly_rate', 12, 2)->nullable()->after('color');
            $table->jsonb('rates')->nullable()->after('hourly_rate');
        });

        DB::statement('ALTER TABLE booking_resources ADD CONSTRAINT booking_resources_rate_valid CHECK (hourly_rate IS NULL OR hourly_rate >= 0)');

        Schema::table('appointments', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->default(0)->after('price');
        });

        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_paid_valid CHECK (amount_paid >= 0 AND (price IS NULL OR amount_paid <= price))');

        Schema::create('appointment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('appointment_id');
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['appointment_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('appointments')->cascadeOnDelete();

            $table->index(['tenant_id', 'paid_at']);
            $table->index('appointment_id');
            $table->index('recorded_by_user_id');
        });

        DB::statement('ALTER TABLE appointment_payments ADD CONSTRAINT appointment_payments_valid CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_payments');
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_paid_valid');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });

        DB::statement('ALTER TABLE booking_resources DROP CONSTRAINT IF EXISTS booking_resources_rate_valid');

        Schema::table('booking_resources', function (Blueprint $table) {
            $table->dropColumn(['hourly_rate', 'rates']);
        });
    }
};
