<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the global unique constraint on slug (not needed, we have composite unique)
        // PostgreSQL: drop constraint, not index
        try {
            DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_slug_unique');
        } catch (\Exception $e) {
            // Try alternative method
            try {
                Schema::table('roles', function (Blueprint $table) {
                    $table->dropUnique(['slug']);
                });
            } catch (\Exception $e2) {
                // Ignore if already dropped or doesn't exist
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add the unique constraint if needed
        Schema::table('roles', function (Blueprint $table) {
            $table->unique('slug');
        });
    }
};
