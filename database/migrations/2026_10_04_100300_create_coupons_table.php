<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupon codes (offers module), Phase 10 (ADR-020). A coupon fills an order's existing `discount`;
 * the order keeps the code as typed so history survives the coupon being edited or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('description', 150)->nullable();
            $table->string('type', 8);
            $table->decimal('value', 12, 2);
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->boolean('online')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'is_active']);
        });

        DB::statement('CREATE UNIQUE INDEX coupons_code_unique ON coupons (tenant_id, lower(code)) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_valid CHECK (
            type IN ('percent','fixed')
            AND value > 0 AND (type <> 'percent' OR value <= 100)
            AND (min_subtotal IS NULL OR min_subtotal >= 0)
            AND (max_discount IS NULL OR max_discount > 0)
            AND (usage_limit IS NULL OR usage_limit > 0)
            AND (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
        )");

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_id')->nullable()->after('discount');
            $table->string('coupon_code', 30)->nullable()->after('coupon_id');
            $table->foreign(['coupon_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('coupons');
            $table->index('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['coupon_id', 'tenant_id']);
            $table->dropColumn(['coupon_id', 'coupon_code']);
        });

        Schema::dropIfExists('coupons');
    }
};
