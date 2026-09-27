<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hostnames mapped to tenants (ADR-007). A domain belongs to exactly one tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253)->unique();
            $table->string('type', 20);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->string('ssl_status', 20)->default('pending');
            $table->timestamps();

            $table->index('tenant_id');
        });

        DB::statement('CREATE UNIQUE INDEX domains_one_primary_per_tenant ON domains (tenant_id) WHERE is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
