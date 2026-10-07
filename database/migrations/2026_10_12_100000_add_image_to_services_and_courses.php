<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One optional photo per service and per course, like products.image_media_id: shown as a card in the
 * WhatsApp assistant. References media.id alone (not a composite key with tenant_id) so it can become
 * NULL when the image is deleted; the application only links images of the same tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['services', 'courses'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('image_media_id')->nullable()->constrained('media')->nullOnDelete();
                $table->index('image_media_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['services', 'courses'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('image_media_id');
            });
        }
    }
};
