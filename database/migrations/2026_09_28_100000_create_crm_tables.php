<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM (Phase 3, ADR-013): customers, lead stages/sources, leads and the activity timeline.
 *
 * Cross-row references use composite foreign keys (id, tenant_id) so a lead can never point at
 * another tenant's stage, source, customer or team member, even through buggy code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('address')->nullable();
            $table->jsonb('tags')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'email']);
            $table->index('created_by_user_id');
        });

        // One live customer per phone number within a tenant (matching key for lead conversion).
        DB::statement('CREATE UNIQUE INDEX customers_tenant_phone_unique ON customers (tenant_id, phone_normalized) WHERE deleted_at IS NULL AND phone_normalized IS NOT NULL');

        Schema::create('lead_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 60);
            $table->string('color', 7)->default('#6366f1');
            $table->string('outcome', 10)->default('open');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'sort_order']);
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lead_stage_id');
            $table->unsignedBigInteger('lead_source_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('assigned_tenant_user_id')->nullable();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('interest')->nullable();
            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->timestamp('next_followup_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['lead_stage_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('lead_stages');
            $table->foreign(['lead_source_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('lead_sources');
            $table->foreign(['customer_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('customers');
            $table->foreign(['assigned_tenant_user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('tenant_users');

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'lead_stage_id']);
            $table->index(['tenant_id', 'assigned_tenant_user_id']);
            $table->index(['tenant_id', 'next_followup_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'phone_normalized']);
            $table->index('lead_source_id');
            $table->index('customer_id');
            $table->index('assigned_tenant_user_id');
            $table->index('created_by_user_id');
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->text('body')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign(['lead_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('leads')->cascadeOnDelete();
            $table->foreign(['customer_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('customers')->cascadeOnDelete();

            $table->index(['tenant_id', 'lead_id', 'occurred_at']);
            $table->index(['tenant_id', 'customer_id', 'occurred_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('lead_stages');
        Schema::dropIfExists('customers');
    }
};
