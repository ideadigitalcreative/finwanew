<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
                'pendapatan_usaha',
                'pendapatan_sewa',
                'pendapatan_refund',
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
                'pengeluaran_pakaian',
                'pengeluaran_perawatan_diri',
                'pengeluaran_acara',
                'pengeluaran_otomotif',
                'pengeluaran_sosial',
                'pengeluaran_hadiah',
                'pengeluaran_modal',
                'pengeluaran_operasional',
                'pengeluaran_transfer',
                'pengeluaran_lainnya',
                'debit_internal',
                'kredit_internal'
            ) NOT NULL");
        }
    }

    public function down(): void
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
    }
};
