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
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_whatsapp_number_id')->nullable()->after('balance_id');
            $table->foreign('user_whatsapp_number_id')
                ->references('id')
                ->on('user_whatsapp_numbers')
                ->onDelete('set null');
            $table->index(['tenant_id', 'user_whatsapp_number_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['user_whatsapp_number_id']);
            $table->dropIndex(['tenant_id', 'user_whatsapp_number_id']);
            $table->dropColumn('user_whatsapp_number_id');
        });
    }
};
