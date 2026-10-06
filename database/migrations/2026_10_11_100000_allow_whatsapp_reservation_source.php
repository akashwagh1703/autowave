<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp assistant step 2 (ADR-021): reservations made in a WhatsApp chat have the source `whatsapp`.
 * Appointments and orders have no check on their source.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->constraint("'manual','website','whatsapp'");
    }

    public function down(): void
    {
        DB::statement("UPDATE reservations SET source = 'website' WHERE source = 'whatsapp'");
        $this->constraint("'manual','website'");
    }

    private function constraint(string $sources): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_valid');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_valid CHECK (
            status IN ('pending','confirmed','seated','completed','cancelled','no_show')
            AND source IN ({$sources})
            AND party_size BETWEEN 1 AND 100
            AND ends_at > reserved_at
        )");
    }
};
