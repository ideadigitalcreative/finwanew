<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_whatsapp_numbers', function (Blueprint $table) {
            $table->boolean('is_lid')->default(false)->after('is_primary');
        });

        // Update existing LID entries
        // LID format: numbers that don't start with 628 (Indonesian phone format)
        DB::statement("
            UPDATE user_whatsapp_numbers 
            SET is_lid = true 
            WHERE whatsapp_number NOT LIKE '628%' 
            AND LENGTH(whatsapp_number) > 13
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_whatsapp_numbers', function (Blueprint $table) {
            $table->dropColumn('is_lid');
        });
    }
};
