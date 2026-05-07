<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'pengeluaran_transfer' and 'pendapatan_transfer' to the categories type ENUM.
     * These are used for internal funds transfer between wallets.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE categories MODIFY COLUMN type ENUM(
            'pendapatan_gaji',
            'pendapatan_bonus',
            'pendapatan_investasi',
            'pendapatan_transfer',
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
            'pengeluaran_cicilan',
            'pengeluaran_asuransi',
            'pengeluaran_pajak',
            'pengeluaran_donasi',
            'pengeluaran_gaji',
            'pengeluaran_keluarga',
            'pengeluaran_langganan',
            'pengeluaran_modal',
            'pengeluaran_operasional',
            'pengeluaran_transfer',
            'pengeluaran_lainnya'
        ) NOT NULL");

        // Add transfer categories to all existing tenants
        $tenants = DB::table('tenants')->pluck('id');
        foreach ($tenants as $tenantId) {
            // Pendapatan Transfer
            DB::table('categories')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'type' => 'pendapatan_transfer',
                'name' => 'Transfer Masuk',
                'slug' => 'transfer-masuk',
                'description' => 'Pendapatan dari transfer antar dompet (mutasi internal)',
                'icon' => '📥',
                'color' => '#10b981',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Pengeluaran Transfer
            DB::table('categories')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'type' => 'pengeluaran_transfer',
                'name' => 'Transfer Keluar',
                'slug' => 'transfer-keluar',
                'description' => 'Pengeluaran untuk transfer antar dompet (mutasi internal)',
                'icon' => '📤',
                'color' => '#ef4444',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Delete transfer categories
        DB::table('categories')
            ->whereIn('type', ['pendapatan_transfer', 'pengeluaran_transfer'])
            ->delete();

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
            'pengeluaran_cicilan',
            'pengeluaran_asuransi',
            'pengeluaran_pajak',
            'pengeluaran_donasi',
            'pengeluaran_gaji',
            'pengeluaran_keluarga',
            'pengeluaran_langganan',
            'pengeluaran_modal',
            'pengeluaran_operasional',
            'pengeluaran_lainnya'
        ) NOT NULL");
    }
};
