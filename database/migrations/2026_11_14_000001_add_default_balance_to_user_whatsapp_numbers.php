<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dompet bawaan per nomor WhatsApp (anggota): transaksi yang tidak menyebut dompet
 * dicatat di dompet ini, bukan di dompet utama akun. Null = ikut dompet utama.
 * Hanya menambah kolom (tidak mengubah data).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_whatsapp_numbers', 'default_balance_id')) {
            return;
        }

        Schema::table('user_whatsapp_numbers', function (Blueprint $table) {
            $table->foreignId('default_balance_id')->nullable()->constrained('balances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_whatsapp_numbers', 'default_balance_id')) {
            return;
        }

        Schema::table('user_whatsapp_numbers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_balance_id');
        });
    }
};
