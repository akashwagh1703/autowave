<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('price_monthly');
            $table->unsignedInteger('price_yearly');
            $table->jsonb('limits');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('period', 10)->nullable();
            $table->boolean('is_trial')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('next_plan_id')->nullable()->constrained('plans');
            $table->string('next_period', 10)->nullable();
            $table->timestamp('plan_changes_at')->nullable();
            $table->jsonb('reminders')->nullable();
            $table->timestamps();

            $table->index('ends_at');
        });

        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('period', 10);
            $table->string('kind', 10);
            $table->unsignedInteger('credit')->default(0);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('tax_amount')->default(0);
            $table->unsignedInteger('total');
            $table->jsonb('tax')->nullable();
            $table->string('method', 20);
            $table->string('status', 10);
            $table->string('reference', 64)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('proof_disk', 30)->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->string('buyer_gstin', 15)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('covers_from')->nullable();
            $table->timestamp('covers_until')->nullable();
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_payment_id', 100)->nullable()->unique();
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('status');
        });

        // One payment waiting for approval per business, and a UTR / reference claimed only once.
        DB::statement("CREATE UNIQUE INDEX billing_payments_one_pending ON billing_payments (tenant_id) WHERE status = 'pending'");
        DB::statement("CREATE UNIQUE INDEX billing_payments_reference_unique ON billing_payments (reference) WHERE reference IS NOT NULL AND status IN ('pending', 'approved')");

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('billing_payment_id')->unique();
            $table->string('number', 40)->unique();
            $table->string('financial_year', 7);
            $table->unsignedInteger('sequence');
            $table->string('type', 20);
            $table->timestamp('issued_at');
            $table->jsonb('seller');
            $table->jsonb('buyer');
            $table->jsonb('lines');
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('tax_amount');
            $table->unsignedInteger('total');
            $table->jsonb('tax')->nullable();
            $table->timestamps();

            $table->unique(['financial_year', 'sequence']);
            $table->index('tenant_id');
            $table->foreign(['billing_payment_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('billing_payments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
