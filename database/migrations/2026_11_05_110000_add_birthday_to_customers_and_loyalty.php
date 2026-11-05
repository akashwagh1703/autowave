<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Customer birthdays for greetings and optional loyalty free rewards. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('address');
            $table->date('birthday_greeted_on')->nullable()->after('date_of_birth');
        });

        DB::statement('ALTER TABLE loyalty_stamps DROP CONSTRAINT IF EXISTS loyalty_stamps_source_check');
        DB::statement("ALTER TABLE loyalty_stamps ADD CONSTRAINT loyalty_stamps_source_check CHECK (source IN ('order','appointment','manual','join','redeem','birthday'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loyalty_stamps DROP CONSTRAINT IF EXISTS loyalty_stamps_source_check');
        DB::statement("ALTER TABLE loyalty_stamps ADD CONSTRAINT loyalty_stamps_source_check CHECK (source IN ('order','appointment','manual','join','redeem'))");

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'birthday_greeted_on']);
        });
    }
};
