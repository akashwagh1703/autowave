<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automation engine + outbound messages (Phase 5, ADR-015).
 *
 * - automations / automation_nodes: the editable definition (trigger + ordered steps).
 * - automation_runs: one execution for one subject; keeps a snapshot of the steps it runs.
 * - automation_jobs: one row per step execution, due at run_at; the scheduler dispatches due rows.
 * - automation_logs: what happened, step by step.
 * - outbound_messages: every message queued through MessagingService, idempotent per key.
 *
 * Cross-row references use composite foreign keys (id, tenant_id), as in the CRM tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('trigger', 60);
            $table->boolean('is_active')->default(false);
            $table->boolean('once_per_subject')->default(false);
            $table->string('template_key', 60)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'tenant_id']);
            // Includes deleted rows, so a default automation the owner deleted is never re-created.
            $table->unique(['tenant_id', 'template_key']);
            $table->index(['tenant_id', 'trigger', 'is_active']);
            $table->index('created_by_user_id');
        });

        Schema::create('automation_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('automation_id');
            $table->unsignedSmallInteger('position');
            $table->string('type', 20);
            $table->string('action', 40)->nullable();
            $table->jsonb('config');
            $table->timestamps();

            $table->foreign(['automation_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('automations')->cascadeOnDelete();

            $table->unique(['automation_id', 'position']);
            $table->index('tenant_id');
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('automation_id');
            $table->string('trigger', 60);
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('dedupe_key', 191);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('status', 20);
            $table->jsonb('steps');
            $table->jsonb('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(['automation_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('automations')->cascadeOnDelete();

            $table->unique(['id', 'tenant_id']);
            // Duplicate trigger events never start a second run.
            $table->unique(['tenant_id', 'automation_id', 'dedupe_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'automation_id', 'created_at']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });

        Schema::create('automation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('automation_run_id');
            $table->unsignedSmallInteger('step_index');
            $table->timestamp('run_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('anchor', 30)->nullable();
            $table->integer('offset_minutes')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign(['automation_run_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('automation_runs')->cascadeOnDelete();

            // Each step of a run executes at most once.
            $table->unique(['automation_run_id', 'step_index']);
            $table->index(['tenant_id', 'automation_run_id']);
            $table->index(['status', 'queued_at']);
            $table->index(['status', 'started_at']);
        });

        // The scheduler's every-minute query: due pending steps across all tenants.
        DB::statement("CREATE INDEX automation_jobs_due_index ON automation_jobs (run_at) WHERE status = 'pending'");

        Schema::create('automation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('automation_run_id');
            $table->unsignedSmallInteger('step_index')->nullable();
            $table->string('level', 10);
            $table->string('event', 40);
            $table->string('message', 500);
            $table->jsonb('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['automation_run_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('automation_runs')->cascadeOnDelete();

            $table->index(['automation_run_id', 'id']);
            $table->index(['tenant_id', 'level', 'created_at']);
        });

        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('provider', 30);
            $table->boolean('simulated')->default(false);
            $table->string('recipient', 191);
            $table->string('recipient_name', 150)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->string('status', 20);
            $table->string('idempotency_key', 191);
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('tenant_user_id')->nullable();
            $table->unsignedBigInteger('automation_run_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id', 191)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign(['lead_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('leads')->cascadeOnDelete();
            $table->foreign(['customer_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('customers')->cascadeOnDelete();
            $table->foreign(['tenant_user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('tenant_users')->cascadeOnDelete();
            $table->foreign(['automation_run_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('automation_runs')->cascadeOnDelete();

            // A retried action never sends the same message twice.
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['status', 'queued_at']);
            $table->index('lead_id');
            $table->index('customer_id');
            $table->index('tenant_user_id');
            $table->index('automation_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('automation_logs');
        Schema::dropIfExists('automation_jobs');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_nodes');
        Schema::dropIfExists('automations');
    }
};
