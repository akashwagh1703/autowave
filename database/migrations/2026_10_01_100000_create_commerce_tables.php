<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commerce engine, Phase 7 (ADR-017): product catalogue with categories and stock, a stock
 * ledger, orders with their items and recorded payments.
 *
 * Cross-row references use composite foreign keys (id, tenant_id), as in the CRM and booking
 * tables. products.image_media_id is the exception: it must become NULL when the image is removed,
 * and PostgreSQL 11 cannot null only one column of a composite key, so it references media.id
 * alone; the application only links images of the same tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_category_id')->nullable();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('sku', 60)->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->foreignId('image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('track_stock')->default(false);
            $table->integer('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['product_category_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('product_categories');

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'product_category_id']);
            $table->index(['tenant_id', 'name']);
            $table->index('product_category_id');
            $table->index('image_media_id');
            $table->index('created_by_user_id');
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_valid CHECK (price >= 0 AND (compare_at_price IS NULL OR compare_at_price >= 0) AND stock_quantity >= 0)');
        // SKUs are optional but unique per tenant among live products (case-insensitive).
        DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (tenant_id, lower(sku)) WHERE deleted_at IS NULL AND sku IS NOT NULL');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('customer_id');
            $table->string('status', 12)->default('pending');
            $table->string('source', 30)->default('manual');
            $table->string('fulfilment', 20);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('payment_status', 10)->default('unpaid');
            $table->string('delivery_address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['customer_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('customers');

            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index('customer_id');
            $table->index('created_by_user_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_valid CHECK (
                status IN ('pending', 'confirmed', 'ready', 'completed', 'cancelled')
                AND payment_status IN ('unpaid', 'partial', 'paid')
                AND subtotal >= 0 AND discount >= 0 AND discount <= subtotal AND delivery_fee >= 0
                AND total = subtotal - discount + delivery_fee
                AND amount_paid >= 0 AND amount_paid <= total
            )
            SQL);

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name', 120);
            $table->string('sku', 60)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->boolean('stock_deducted')->default(false);

            $table->foreign(['order_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['product_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('products');

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->index('order_id');
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_valid CHECK (quantity > 0 AND unit_price >= 0 AND line_total = unit_price * quantity)');

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['order_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('orders')->cascadeOnDelete();

            $table->index(['tenant_id', 'paid_at']);
            $table->index('order_id');
            $table->index('recorded_by_user_id');
        });

        DB::statement('ALTER TABLE order_payments ADD CONSTRAINT order_payments_valid CHECK (amount > 0)');

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity_change');
            $table->integer('balance_after');
            $table->string('reason', 20);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->foreign(['product_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('products')->cascadeOnDelete();
            $table->foreign(['order_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('orders');

            $table->index(['tenant_id', 'product_id', 'created_at']);
            $table->index('product_id');
            $table->index('order_id');
            $table->index('created_by_user_id');
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_valid CHECK (quantity_change <> 0 AND balance_after >= 0)');

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->after('appointment_id');

            $table->foreign(['order_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'order_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['order_id', 'tenant_id']);
            $table->dropIndex(['tenant_id', 'order_id', 'occurred_at']);
            $table->dropColumn('order_id');
        });

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
