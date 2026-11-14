<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan kolom telegram_id dan telegram_username ke users table.
     * Ini memudahkan mapping langsung tanpa perlu join tabel terpisah.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_username')->nullable()->after('whatsapp_number')
                ->comment('Telegram username (tanpa @)');
            $table->unsignedBigInteger('telegram_chat_id')->nullable()->after('telegram_username')
                ->comment('Telegram chat ID (unik per user)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_username', 'telegram_chat_id']);
        });
    }
};
