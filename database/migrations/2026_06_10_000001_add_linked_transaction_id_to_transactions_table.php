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
            $table->unsignedBigInteger('linked_transaction_id')->nullable()->after('metadata');
            $table->foreign('linked_transaction_id')
                ->references('id')
                ->on('transactions')
                ->onDelete('set null');
            $table->index('linked_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['linked_transaction_id']);
            $table->dropIndex(['linked_transaction_id']);
            $table->dropColumn('linked_transaction_id');
        });
    }
};
