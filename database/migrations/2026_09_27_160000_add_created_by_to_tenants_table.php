<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who created the tenant (onboarding). Used for the per-user business limit and audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
