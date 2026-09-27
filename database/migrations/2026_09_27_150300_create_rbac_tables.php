<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic RBAC. Roles with tenant_id = null are platform templates copied into new tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('group', 50)->index();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('grants_all')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->unique(['id', 'tenant_id']);
        });

        // NULLs are distinct in unique indexes (and NULLS NOT DISTINCT needs PG15), so template
        // slugs get their own partial index.
        DB::statement('CREATE UNIQUE INDEX roles_template_slug_unique ON roles (slug) WHERE tenant_id IS NULL');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_user_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            // Composite keys guarantee the membership and the role belong to the same tenant,
            // and that template roles (tenant_id null) can never be assigned directly.
            $table->foreign(['tenant_user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('tenant_users')->cascadeOnDelete();
            $table->foreign(['role_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('roles')->cascadeOnDelete();

            $table->unique(['tenant_user_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
