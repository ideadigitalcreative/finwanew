<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'pengeluaran_cicilan' to the categories type ENUM.
     * Separates cicilan/angsuran from pengeluaran_pinjaman.
     *
     * pengeluaran_cicilan: cicilan motor, cicilan rumah, angsuran, kredit, KPR
     */
    public function up(): void
    {
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

        // Add cicilan category to all existing tenants
        $tenants = DB::table('tenants')->pluck('id');
        foreach ($tenants as $tenantId) {
            DB::table('categories')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'type' => 'pengeluaran_cicilan',
                'name' => 'Cicilan',
                'slug' => 'cicilan',
                'description' => 'Pengeluaran untuk cicilan dan angsuran (motor, mobil, rumah, KPR, dll)',
                'icon' => '🏦',
                'color' => '#dc2626',
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
        // Move cicilan transactions back to pinjaman before removing enum
        DB::table('categories')
            ->where('type', 'pengeluaran_cicilan')
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
