<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update transactions table type enum
        // Note: Using raw DB statement because Blueprint doesn't support modifying enum easily across all drivers
        DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('income', 'expense', 'debit_internal', 'kredit_internal') NOT NULL");

        // 2. Update categories table type enum
        // We need to list all existing types and add the new ones
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
            'pengeluaran_lainnya',
            'debit_internal',
            'kredit_internal'
        ) NOT NULL");

        // 3. Add the internal transfer categories to all existing tenants
        $tenants = DB::table('tenants')->pluck('id');
        foreach ($tenants as $tenantId) {
            // Debit Antar Dompet (Mutation OUT)
            DB::table('categories')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'type' => 'debit_internal',
                'name' => 'Debit Antar Dompet',
                'slug' => 'debit-antar-dompet',
                'description' => 'Pemindahan saldo keluar (internal)',
                'icon' => '📤',
                'color' => '#64748b',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Kredit Antar Dompet (Mutation IN)
            DB::table('categories')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'type' => 'kredit_internal',
                'name' => 'Kredit Antar Dompet',
                'slug' => 'kredit-antar-dompet',
                'description' => 'Pemindahan saldo masuk (internal)',
                'icon' => '📥',
                'color' => '#64748b',
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
        // 1. Remove categories first
        DB::table('categories')
            ->whereIn('type', ['debit_internal', 'kredit_internal'])
            ->delete();

        // 2. Revert categories enum
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

        // 3. Revert transactions enum
        // Warning: This might fail if there are transactions with the new types!
        DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('income', 'expense') NOT NULL");
    }
};
