<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty stamp cards (loyalty module). One account per enrolled customer; stamps are a ledger
 * so earns and redemptions stay auditable. Earn sources are unique so the same order never
 * stamps twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->string('public_token', 26);
            $table->unsignedInteger('stamps_balance')->default(0);
            $table->unsignedInteger('lifetime_stamps')->default(0);
            $table->unsignedInteger('rewards_redeemed')->default(0);
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'customer_id']);
            $table->unique(['tenant_id', 'public_token']);
            $table->foreign(['customer_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('customers')->cascadeOnDelete();
        });

        Schema::create('loyalty_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('loyalty_account_id');
            $table->integer('delta');
            $table->string('source', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->index(['loyalty_account_id', 'created_at']);
            $table->index('created_by_user_id');
            $table->foreign(['loyalty_account_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('loyalty_accounts')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE loyalty_stamps ADD CONSTRAINT loyalty_stamps_source_check CHECK (source IN ('order','appointment','manual','join','redeem'))");
        DB::statement('CREATE UNIQUE INDEX loyalty_stamps_source_unique ON loyalty_stamps (tenant_id, source, source_id) WHERE source_id IS NOT NULL');
        DB::statement('ALTER TABLE loyalty_accounts ADD CONSTRAINT loyalty_accounts_balance_check CHECK (stamps_balance >= 0 AND lifetime_stamps >= 0 AND rewards_redeemed >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_stamps');
        Schema::dropIfExists('loyalty_accounts');
    }
};
