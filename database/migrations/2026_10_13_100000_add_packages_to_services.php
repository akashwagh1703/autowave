<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packages are bookable services (is_package) that can list included services and products.
 * Booking still uses services.id; the website Packages section shows only package rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_package')->default(false)->after('is_active');
            $table->index(['tenant_id', 'is_package']);
        });

        Schema::create('package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('package_service_id');
            $table->unsignedBigInteger('included_service_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign(['package_service_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('services')->cascadeOnDelete();
            $table->foreign(['included_service_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('services')->cascadeOnDelete();
            $table->foreign(['product_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('products')->cascadeOnDelete();

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'package_service_id']);
            $table->index('package_service_id');
            $table->index('included_service_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_package']);
            $table->dropColumn('is_package');
        });
    }
};
