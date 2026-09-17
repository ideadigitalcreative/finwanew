<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->boolean('rollover_enabled')->default(false)->after('alert_threshold');
            $table->decimal('rollover_amount', 15, 2)->default(0)->after('rollover_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn(['rollover_enabled', 'rollover_amount']);
        });
    }
};
