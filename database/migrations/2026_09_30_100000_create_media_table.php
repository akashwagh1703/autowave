<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website engine, Phase 6 (ADR-016).
 *
 * - media: tenant-owned uploaded images (logo, hero, gallery; product images later), stored under
 *   tenant/{tenant_id}/... on the configured disk. `collection` groups them; the file itself is the
 *   only copy of the content.
 * - website_sections: one section per type per tenant, so the editor and renderer can address a
 *   section by its type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('collection', 30);
            $table->string('disk', 30);
            $table->string('path', 255)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 60);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('alt', 150)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'collection', 'sort_order']);
            $table->index('uploaded_by_user_id');
        });

        Schema::table('website_sections', function (Blueprint $table) {
            $table->unique(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('website_sections', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'type']);
        });

        Schema::dropIfExists('media');
    }
};
