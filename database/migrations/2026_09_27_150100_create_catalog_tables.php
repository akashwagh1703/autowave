<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform catalogue (not tenant-owned): business types, engines, modules (ADR-005, ADR-006).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('version', 20)->default('1.0');
            $table->string('status', 20)->default('active');
            $table->boolean('is_public')->default(true);
            $table->jsonb('configuration')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index('status');
        });

        Schema::create('engines', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->jsonb('configuration')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 20)->default('core');
            $table->string('version', 20)->default('1.0');
            $table->string('status', 20)->default('active');
            $table->jsonb('configuration')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('module_dependencies', function (Blueprint $table) {
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_module_id')->constrained('modules')->cascadeOnDelete();

            $table->primary(['module_id', 'depends_on_module_id']);
            $table->index('depends_on_module_id');
        });

        Schema::create('business_type_engines', function (Blueprint $table) {
            $table->foreignId('business_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engine_id')->constrained()->cascadeOnDelete();

            $table->primary(['business_type_id', 'engine_id']);
            $table->index('engine_id');
        });

        Schema::create('business_type_modules', function (Blueprint $table) {
            $table->foreignId('business_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->jsonb('configuration')->nullable();

            $table->primary(['business_type_id', 'module_id']);
            $table->index('module_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_type_modules');
        Schema::dropIfExists('business_type_engines');
        Schema::dropIfExists('module_dependencies');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('engines');
        Schema::dropIfExists('business_types');
    }
};
