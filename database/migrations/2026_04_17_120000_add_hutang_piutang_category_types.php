<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Empat tipe kategori hutang/piutang (lihat docs/features/ANALISA_HUTANG_PIUTANG.md).
     *
     * MySQL: ENUM harus diperluas secara eksplisit.
     * SQLite: kolom type tidak dibatasi ENUM — cukup sisipkan baris kategori.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE categories MODIFY COLUMN type ENUM(
                'pendapatan_gaji',
                'pendapatan_bonus',
                'pendapatan_investasi',
                'pendapatan_transfer',
                'pendapatan_hutang',
                'pendapatan_terima_piutang',
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
                'pengeluaran_bayar_hutang',
                'pengeluaran_piutang',
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
        }

        $defs = [
            [
                'type' => 'pendapatan_hutang',
                'name' => 'Terima Hutang (Pinjaman Masuk)',
                'slug' => 'terima-hutang',
                'description' => 'Uang masuk dari pinjaman; kewajiban bayar (hutang) bertambah secara bisnis — tercatat sebagai arus kas masuk.',
                'icon' => '📥',
                'color' => '#d97706',
            ],
            [
                'type' => 'pengeluaran_bayar_hutang',
                'name' => 'Bayar Hutang',
                'slug' => 'bayar-hutang',
                'description' => 'Pelunasan atau cicilan hutang ke pihak lain; arus kas keluar.',
                'icon' => '💸',
                'color' => '#b45309',
            ],
            [
                'type' => 'pengeluaran_piutang',
                'name' => 'Piutang (Pinjaman Keluar)',
                'slug' => 'piutang-keluar',
                'description' => 'Memberi pinjaman / piutang usaha; uang keluar, hak terima kembali bertambah.',
                'icon' => '🤝',
                'color' => '#2563eb',
            ],
            [
                'type' => 'pendapatan_terima_piutang',
                'name' => 'Terima Pelunasan Piutang',
                'slug' => 'terima-piutang',
                'description' => 'Pelunasan piutang dari debitur; arus kas masuk.',
                'icon' => '✅',
                'color' => '#1d4ed8',
            ],
        ];

        $tenantIds = DB::table('tenants')->pluck('id');
        foreach ($tenantIds as $tenantId) {
            foreach ($defs as $row) {
                DB::table('categories')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'type' => $row['type'],
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'description' => $row['description'],
                    'icon' => $row['icon'],
                    'color' => $row['color'],
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('categories')
            ->whereIn('type', [
                'pendapatan_hutang',
                'pengeluaran_bayar_hutang',
                'pengeluaran_piutang',
                'pendapatan_terima_piutang',
            ])
            ->delete();

        if (DB::getDriverName() === 'mysql') {
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
        }
    }
};
