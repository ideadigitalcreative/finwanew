<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'pengeluaran_keluarga', 'pengeluaran_gaji', and 'pengeluaran_langganan'
     * to the categories type ENUM.
     *
     * These are needed for:
     * - pengeluaran_keluarga: "kasih orang tua", "kasih pacar", "kirimin mama"
     * - pengeluaran_gaji: "gaji karyawan", "bayar upah tukang"
     * - pengeluaran_langganan: "langganan netflix", "subs spotify"
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // SQLite tidak membatasi ENUM
        }

        // Idempoten: bila ketiga nilai sudah ada, jangan ALTER (mencegah
        // "data truncated" akibat daftar ENUM migrasi ini lebih pendek
        // dari ENUM di DB yang sudah diperluas migrasi lain).
        $current = collect(DB::select("SHOW COLUMNS FROM categories WHERE Field = 'type'"))->first()->Type ?? '';
        $targets = ['pengeluaran_keluarga', 'pengeluaran_gaji', 'pengeluaran_langganan'];
        $missing = array_filter($targets, fn ($t) => ! str_contains($current, "'{$t}'"));
        if ($missing === []) {
            return;
        }

        // Alter the ENUM column to add missing category types
        DB::statement("ALTER TABLE categories MODIFY COLUMN type ENUM(
            'pendapatan_gaji',
            'pendapatan_bonus',
            'pendapatan_investasi',
            'pendapatan_lainnya',
            'pengeluaran_makanan',
            'pengeluaran_transport',
            'pengeluaran_hunian',
            'pengeluaran_utilitas',
            'pengeluaran_kesehatan',
            'pengeluaran_pendidikan',
            'pengeluaran_belanja',
            'pengeluaran_hiburan',
            'pengeluaran_pulsa_token',
            'pengeluaran_tagihan',
            'pengeluaran_investasi',
            'pengeluaran_pinjaman',
            'pengeluaran_asuransi',
            'pengeluaran_pajak',
            'pengeluaran_donasi',
            'pengeluaran_gaji',
            'pengeluaran_keluarga',
            'pengeluaran_langganan',
            'pengeluaran_lainnya'
        ) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // SQLite tidak membatasi ENUM
        }

        // Revert to previous state (without new types)
        DB::statement("ALTER TABLE categories MODIFY COLUMN type ENUM(
            'pendapatan_gaji',
            'pendapatan_bonus',
            'pendapatan_investasi',
            'pendapatan_lainnya',
            'pengeluaran_makanan',
            'pengeluaran_transport',
            'pengeluaran_hunian',
            'pengeluaran_utilitas',
            'pengeluaran_kesehatan',
            'pengeluaran_pendidikan',
            'pengeluaran_belanja',
            'pengeluaran_hiburan',
            'pengeluaran_pulsa_token',
            'pengeluaran_tagihan',
            'pengeluaran_investasi',
            'pengeluaran_pinjaman',
            'pengeluaran_asuransi',
            'pengeluaran_pajak',
            'pengeluaran_donasi',
            'pengeluaran_lainnya'
        ) NOT NULL");
    }
};
