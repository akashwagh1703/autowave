<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenants, memberships and per-tenant configuration (ADR-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 63)->unique();
            $table->foreignId('business_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('business_type_version', 20)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->boolean('is_internal')->default(false);
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->string('locale', 10)->default('en');
            $table->char('currency', 3)->default('INR');
            $table->timestamps();
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            // Target for composite foreign keys that must stay within one tenant (user_roles).
            $table->unique(['id', 'tenant_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->jsonb('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('tenant_engines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engine_id')->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'engine_id']);
            $table->index('engine_id');
        });

        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('version', 20);
            $table->jsonb('configuration')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module_id']);
            $table->index('module_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
        Schema::dropIfExists('tenant_engines');
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};
