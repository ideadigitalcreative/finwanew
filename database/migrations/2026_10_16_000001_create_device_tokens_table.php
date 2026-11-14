<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // Token FCM bisa ±160-250 karakter; unik global (1 perangkat = 1 token)
                $table->string('token', 512)->unique();
                $table->string('platform', 20)->default('android');
                $table->string('device_name')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->index('user_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'push_enabled')) {
                $table->boolean('push_enabled')->default(true);
            }
            if (! Schema::hasColumn('users', 'push_hide_amount')) {
                // Sembunyikan nominal di notifikasi (privasi layar kunci)
                $table->boolean('push_hide_amount')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');

        Schema::table('users', function (Blueprint $table) {
            foreach (['push_enabled', 'push_hide_amount'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
