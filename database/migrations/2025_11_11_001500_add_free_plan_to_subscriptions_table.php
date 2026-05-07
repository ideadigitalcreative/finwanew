<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE `subscriptions` MODIFY `plan` ENUM('free', 'starter', 'growth', 'pro', 'enterprise') NOT NULL DEFAULT 'starter'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE `subscriptions` MODIFY `plan` ENUM('starter', 'growth', 'pro', 'enterprise') NOT NULL DEFAULT 'starter'");
    }
};
