<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI usage metering and stored AI output (Phase 9, ADR-019).
 *
 * - ai_usage: one row per provider call, for the monthly cap and the Super Admin view.
 * - ai_results: cached summaries, reply drafts and lead-detail suggestions, keyed per record so
 *   an unchanged record reuses its result and an automation step never runs twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 30);
            $table->string('provider', 30);
            $table->string('model', 100)->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // The monthly cap: this tenant's rows since the 1st.
            $table->index(['tenant_id', 'created_at']);
            // Platform totals for the Super Admin view.
            $table->index('created_at');
            $table->index('user_id');
        });

        Schema::create('ai_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 30);
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('key', 191);
            $table->string('status', 20)->default('ready');
            $table->jsonb('output');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'feature', 'key']);
            $table->index(['tenant_id', 'subject_type', 'subject_id', 'feature']);
            $table->index('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_results');
        Schema::dropIfExists('ai_usage');
    }
};
