<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Education engine, Phase 10 (ADR-020): courses, batches, enrolments (students are customers),
 * fee instalments and payments, class sessions with attendance, and demo classes for leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('fee', 12, 2)->nullable();
            $table->string('duration_label', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });

        DB::statement('CREATE UNIQUE INDEX courses_name_unique ON courses (tenant_id, lower(name)) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE courses ADD CONSTRAINT courses_fee_valid CHECK (fee IS NULL OR fee >= 0)');

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('course_id');
            $table->string('name', 120);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->jsonb('weekdays')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedBigInteger('teacher_tenant_user_id')->nullable();
            $table->string('room', 60)->nullable();
            $table->decimal('fee', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['course_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('courses');
            $table->foreign(['teacher_tenant_user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('tenant_users');
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'is_active']);
            $table->index('course_id');
            $table->index('teacher_tenant_user_id');
        });

        DB::statement('ALTER TABLE batches ADD CONSTRAINT batches_valid CHECK (
            (capacity IS NULL OR capacity > 0)
            AND (fee IS NULL OR fee >= 0)
            AND (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)
            AND (end_time IS NULL OR start_time IS NULL OR end_time > start_time)
        )');

        Schema::create('enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('status', 12)->default('active');
            $table->date('enrolled_on');
            $table->decimal('fee_total', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['customer_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('customers');
            $table->foreign(['batch_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('batches');
            $table->foreign(['lead_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('leads');
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'status', 'enrolled_on']);
            $table->index('customer_id');
            $table->index('batch_id');
            $table->index('lead_id');
            $table->index('created_by_user_id');
        });

        DB::statement("ALTER TABLE enrolments ADD CONSTRAINT enrolments_valid CHECK (
            status IN ('active','completed','dropped')
            AND fee_total >= 0 AND discount >= 0 AND discount <= fee_total
            AND amount_paid >= 0 AND amount_paid <= fee_total - discount
        )");
        DB::statement("CREATE UNIQUE INDEX enrolments_active_unique ON enrolments (tenant_id, batch_id, customer_id) WHERE status = 'active'");

        Schema::create('fee_instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('enrolment_id');
            $table->unsignedSmallInteger('sequence');
            $table->date('due_on');
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            $table->foreign(['enrolment_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('enrolments')->cascadeOnDelete();
            $table->unique(['id', 'tenant_id']);
            $table->unique(['enrolment_id', 'sequence']);
            $table->index(['tenant_id', 'due_on']);
        });

        DB::statement('ALTER TABLE fee_instalments ADD CONSTRAINT fee_instalments_valid CHECK (amount > 0 AND amount_paid >= 0 AND amount_paid <= amount)');

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('enrolment_id');
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['enrolment_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('enrolments')->cascadeOnDelete();
            $table->index(['tenant_id', 'paid_at']);
            $table->index('enrolment_id');
            $table->index('recorded_by_user_id');
        });

        DB::statement('ALTER TABLE fee_payments ADD CONSTRAINT fee_payments_valid CHECK (amount > 0)');

        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('batch_id');
            $table->date('held_on');
            $table->string('topic', 150)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['batch_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('batches')->cascadeOnDelete();
            $table->unique(['id', 'tenant_id']);
            $table->unique(['batch_id', 'held_on']);
            $table->index(['tenant_id', 'held_on']);
            $table->index('created_by_user_id');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('class_session_id');
            $table->unsignedBigInteger('enrolment_id');
            $table->string('status', 10);
            $table->timestamps();

            $table->foreign(['class_session_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('class_sessions')->cascadeOnDelete();
            $table->foreign(['enrolment_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('enrolments')->cascadeOnDelete();
            $table->unique(['class_session_id', 'enrolment_id']);
            $table->index('enrolment_id');
            $table->index('tenant_id');
        });

        DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_valid CHECK (status IN ('present','absent','late','excused'))");

        Schema::create('demo_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->timestamp('scheduled_at');
            $table->string('status', 12)->default('scheduled');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['lead_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('leads')->cascadeOnDelete();
            $table->foreign(['course_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('courses');
            $table->foreign(['batch_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('batches');
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->index('lead_id');
            $table->index('course_id');
            $table->index('batch_id');
            $table->index('created_by_user_id');
        });

        DB::statement("ALTER TABLE demo_classes ADD CONSTRAINT demo_classes_valid CHECK (status IN ('scheduled','attended','no_show','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_classes');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('fee_payments');
        Schema::dropIfExists('fee_instalments');
        Schema::dropIfExists('enrolments');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('courses');
    }
};
