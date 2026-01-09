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
            $table->foreignId('balance_id')->nullable()->after('message_id')->constrained('balances')->onDelete('set null');
            $table->index(['tenant_id', 'balance_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['balance_id']);
            $table->dropIndex(['tenant_id', 'balance_id']);
            $table->dropColumn('balance_id');
        });
    }
};

