<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Food engine, Phase 10 (ADR-020). The menu is the commerce catalogue; orders are commerce orders.
 *
 * - products.food_type / is_available: veg, non-veg or egg, and "sold out today".
 * - dining_tables and reservations; a table cannot hold two overlapping live reservations
 *   (reservations_no_overlap, the same int8range trick as appointments — no btree_gist on PG 11).
 * - orders.dining_table_id and fulfilment `dine_in`; a dine-in order may have no customer (walk-in).
 * - order_items.kitchen_status / notes / added_at for the kitchen screen and items added later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('food_type', 8)->nullable()->after('description');
            $table->boolean('is_available')->default(true)->after('is_active');
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_food_type_valid CHECK (food_type IS NULL OR food_type IN ('veg','non_veg','egg'))");

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('seats');
            $table->string('area', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });

        DB::statement('CREATE UNIQUE INDEX dining_tables_name_unique ON dining_tables (tenant_id, lower(name)) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE dining_tables ADD CONSTRAINT dining_tables_seats_valid CHECK (seats BETWEEN 1 AND 100)');

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('dining_table_id')->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->timestamp('reserved_at');
            $table->timestamp('ends_at');
            $table->string('status', 10)->default('pending');
            $table->string('source', 10)->default('manual');
            $table->text('notes')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['customer_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('customers');
            $table->foreign(['dining_table_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('dining_tables');
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'reserved_at']);
            $table->index(['tenant_id', 'status']);
            $table->index('customer_id');
            $table->index('dining_table_id');
            $table->index('created_by_user_id');
        });

        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_valid CHECK (
            status IN ('pending','confirmed','seated','completed','cancelled','no_show')
            AND source IN ('manual','website')
            AND party_size BETWEEN 1 AND 100
            AND ends_at > reserved_at
        )");

        DB::statement(<<<'SQL'
            ALTER TABLE reservations ADD CONSTRAINT reservations_no_overlap EXCLUDE USING gist (
                int8range(dining_table_id, dining_table_id, '[]') WITH &&,
                tsrange(reserved_at, ends_at, '[)') WITH &&
            ) WHERE (dining_table_id IS NOT NULL AND status IN ('pending', 'confirmed', 'seated'))
        SQL);

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('dining_table_id')->nullable()->after('customer_id');
            $table->foreign(['dining_table_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('dining_tables');
            $table->index('dining_table_id');
        });

        DB::statement('ALTER TABLE orders ALTER COLUMN customer_id DROP NOT NULL');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_customer_required CHECK (customer_id IS NOT NULL OR fulfilment = 'dine_in')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_table_dine_in CHECK (dining_table_id IS NULL OR fulfilment = 'dine_in')");

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('notes', 200)->nullable();
            $table->string('kitchen_status', 8)->nullable();
            $table->timestamp('added_at')->nullable();
        });

        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_kitchen_valid CHECK (kitchen_status IS NULL OR kitchen_status IN ('queued','ready'))");
        DB::statement("CREATE INDEX order_items_kitchen_queued ON order_items (order_id) WHERE kitchen_status = 'queued'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS order_items_kitchen_queued');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_kitchen_valid');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['notes', 'kitchen_status', 'added_at']);
        });

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_table_dine_in');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_customer_required');
        DB::statement('DELETE FROM orders WHERE customer_id IS NULL');
        DB::statement('ALTER TABLE orders ALTER COLUMN customer_id SET NOT NULL');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['dining_table_id', 'tenant_id']);
            $table->dropColumn('dining_table_id');
        });

        Schema::dropIfExists('reservations');
        Schema::dropIfExists('dining_tables');

        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_food_type_valid');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['food_type', 'is_available']);
        });
    }
};
