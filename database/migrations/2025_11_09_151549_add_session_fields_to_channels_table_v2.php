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
        // Check if columns already exist before adding
        if (! Schema::hasColumn('channels', 'session_id')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->string('session_id')->nullable()->after('channel_account');
            });
        }

        if (! Schema::hasColumn('channels', 'session_status')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->string('session_status')->nullable()->after('session_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('channels', 'session_status')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->dropColumn('session_status');
            });
        }

        if (Schema::hasColumn('channels', 'session_id')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->dropColumn('session_id');
            });
        }
    }
};
