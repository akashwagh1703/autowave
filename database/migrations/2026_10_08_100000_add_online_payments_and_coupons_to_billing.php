<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('description', 255)->nullable();
            $table->string('type', 10);
            $table->unsignedInteger('value');
            $table->jsonb('plans')->nullable();
            $table->jsonb('periods')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->boolean('once_per_business')->default(true);
            $table->boolean('first_payment_only')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('billing_payments', function (Blueprint $table) {
            $table->string('gateway_order_id', 100)->nullable()->unique();
            $table->foreignId('coupon_id')->nullable()->constrained('billing_coupons')->restrictOnDelete();
            $table->string('coupon_code', 30)->nullable();
            $table->unsignedInteger('discount')->default(0);

            $table->index(['coupon_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropIndex(['coupon_id', 'status']);
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropUnique(['gateway_order_id']);
            $table->dropColumn(['gateway_order_id', 'coupon_code', 'discount']);
        });

        Schema::dropIfExists('billing_coupons');
    }
};
