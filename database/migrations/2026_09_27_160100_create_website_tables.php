<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section-based website configuration (ADR-012).
 * website_templates is platform catalogue; website_configs and website_sections are tenant-owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->jsonb('configuration')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('website_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('website_template_id')->constrained()->restrictOnDelete();
            $table->jsonb('theme')->nullable();
            $table->jsonb('seo')->nullable();
            $table->string('status', 20)->default('published');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('website_template_id');
        });

        Schema::create('website_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->jsonb('configuration')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('website_configs');
        Schema::dropIfExists('website_templates');
    }
};
