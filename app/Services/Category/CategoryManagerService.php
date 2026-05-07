<?php

namespace App\Services\Category;

use App\Models\Category;

/**
 * CategoryManagerService - Handles category creation and management
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class CategoryManagerService
{
    /**
     * Create default categories for tenant
     *
     * MOVED FROM: ProcessIncomingMessage::createCategoriesForTenant()
     * LINES: 4891-4964
     * MODIFICATION: None (structural move only)
     */
    public function createCategoriesForTenant(int $tenantId): void
    {
        $categories = [
            // Pendapatan
            ['type' => 'pendapatan_gaji', 'name' => 'Gaji', 'slug' => 'gaji', 'icon' => '💰', 'color' => '#10b981'],
            ['type' => 'pendapatan_bonus', 'name' => 'Bonus', 'slug' => 'bonus', 'icon' => '🎁', 'color' => '#10b981'],
            ['type' => 'pendapatan_investasi', 'name' => 'Investasi', 'slug' => 'investasi', 'icon' => '📈', 'color' => '#10b981'],
            ['type' => 'pendapatan_transfer', 'name' => 'Transfer Masuk', 'slug' => 'transfer-masuk', 'icon' => '📥', 'color' => '#10b981'],
            ['type' => 'pendapatan_usaha', 'name' => 'Pendapatan Usaha', 'slug' => 'pendapatan-usaha', 'icon' => '🏪', 'color' => '#10b981'],
            ['type' => 'pendapatan_sewa', 'name' => 'Pendapatan Sewa', 'slug' => 'pendapatan-sewa', 'icon' => '🏘️', 'color' => '#10b981'],
            ['type' => 'pendapatan_refund', 'name' => 'Refund & Cashback', 'slug' => 'refund-cashback', 'icon' => '💸', 'color' => '#10b981'],
            ['type' => 'pendapatan_hutang', 'name' => 'Terima Hutang (Pinjaman Masuk)', 'slug' => 'terima-hutang', 'icon' => '📥', 'color' => '#d97706'],
            ['type' => 'pendapatan_terima_piutang', 'name' => 'Terima Pelunasan Piutang', 'slug' => 'terima-piutang', 'icon' => '✅', 'color' => '#1d4ed8'],
            ['type' => 'pendapatan_lainnya', 'name' => 'Pendapatan Lainnya', 'slug' => 'pendapatan-lainnya', 'icon' => '💵', 'color' => '#10b981'],

            // Pengeluaran
            ['type' => 'pengeluaran_makanan', 'name' => 'Makanan & Minuman', 'slug' => 'makanan-minuman', 'icon' => '🍽️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_transport', 'name' => 'Transport', 'slug' => 'transport', 'icon' => '🚗', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_hunian', 'name' => 'Hunian', 'slug' => 'hunian', 'icon' => '🏠', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_utilitas', 'name' => 'Utilitas', 'slug' => 'utilitas', 'icon' => '⚡', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_kesehatan', 'name' => 'Kesehatan', 'slug' => 'kesehatan', 'icon' => '🏥', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pendidikan', 'name' => 'Pendidikan', 'slug' => 'pendidikan', 'icon' => '📚', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_belanja', 'name' => 'Belanja', 'slug' => 'belanja', 'icon' => '🛒', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_hiburan', 'name' => 'Hiburan', 'slug' => 'hiburan', 'icon' => '🎬', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pulsa_token', 'name' => 'Pulsa & Token', 'slug' => 'pulsa-token', 'icon' => '📱', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_tagihan', 'name' => 'Tagihan', 'slug' => 'tagihan', 'icon' => '📄', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_investasi', 'name' => 'Investasi', 'slug' => 'investasi-pengeluaran', 'icon' => '💼', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pinjaman', 'name' => 'Pinjaman', 'slug' => 'pinjaman', 'icon' => '💳', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_bayar_hutang', 'name' => 'Bayar Hutang', 'slug' => 'bayar-hutang', 'icon' => '💸', 'color' => '#b45309'],
            ['type' => 'pengeluaran_piutang', 'name' => 'Piutang (Pinjaman Keluar)', 'slug' => 'piutang-keluar', 'icon' => '🤝', 'color' => '#2563eb'],
            ['type' => 'pengeluaran_cicilan', 'name' => 'Cicilan', 'slug' => 'cicilan', 'icon' => '🏦', 'color' => '#dc2626'],
            ['type' => 'pengeluaran_asuransi', 'name' => 'Asuransi', 'slug' => 'asuransi', 'icon' => '🛡️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pajak', 'name' => 'Pajak', 'slug' => 'pajak', 'icon' => '📊', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_donasi', 'name' => 'Donasi', 'slug' => 'donasi', 'icon' => '❤️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_gaji', 'name' => 'Gaji Karyawan', 'slug' => 'gaji-karyawan', 'icon' => '👷', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_keluarga', 'name' => 'Keluarga', 'slug' => 'keluarga', 'icon' => '👨‍👩‍👧‍👦', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_langganan', 'name' => 'Langganan', 'slug' => 'langganan', 'icon' => '🔄', 'color' => '#8b5cf6'],
            ['type' => 'pengeluaran_pakaian', 'name' => 'Pakaian & Fashion', 'slug' => 'pakaian-fashion', 'icon' => '👕', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_perawatan_diri', 'name' => 'Perawatan Diri', 'slug' => 'perawatan-diri', 'icon' => '💇', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_acara', 'name' => 'Acara & Hajatan', 'slug' => 'acara-hajatan', 'icon' => '🎊', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_otomotif', 'name' => 'Otomotif', 'slug' => 'otomotif', 'icon' => '🔧', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_sosial', 'name' => 'Sosial & Kondangan', 'slug' => 'sosial-kondangan', 'icon' => '🤝', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_hadiah', 'name' => 'Hadiah & Bingkisan', 'slug' => 'hadiah-bingkisan', 'icon' => '🎁', 'color' => '#ef4444'],
            // UMKM Categories
            ['type' => 'pengeluaran_modal', 'name' => 'Modal & Stok', 'slug' => 'modal-stok', 'icon' => '📦', 'color' => '#f59e0b'],
            ['type' => 'pengeluaran_operasional', 'name' => 'Operasional', 'slug' => 'operasional', 'icon' => '⚙️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_transfer', 'name' => 'Transfer Keluar', 'slug' => 'transfer-keluar', 'icon' => '📤', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_lainnya', 'name' => 'Pengeluaran Lainnya', 'slug' => 'pengeluaran-lainnya', 'icon' => '📝', 'color' => '#ef4444'],

            // Internal Transfer (New Logic)
            ['type' => 'debit_internal', 'name' => 'Debit Antar Dompet', 'slug' => 'debit-antar-dompet', 'icon' => '📤', 'color' => '#64748b'],
            ['type' => 'kredit_internal', 'name' => 'Kredit Antar Dompet', 'slug' => 'kredit-antar-dompet', 'icon' => '📥', 'color' => '#64748b'],
        ];

        $descriptions = [
            'pendapatan_gaji' => 'Pendapatan dari gaji bulanan',
            'pendapatan_bonus' => 'Pendapatan dari bonus atau komisi',
            'pendapatan_investasi' => 'Pendapatan dari investasi',
            'pendapatan_transfer' => 'Pendapatan dari transfer antar dompet (mutasi internal)',
            'pendapatan_usaha' => 'Pendapatan dari usaha, penjualan, dan aktivitas bisnis',
            'pendapatan_sewa' => 'Pendapatan dari sewa properti, kos, dan rental',
            'pendapatan_refund' => 'Pendapatan dari refund, retur barang, dan cashback',
            'pendapatan_hutang' => 'Uang masuk dari pinjaman; kewajiban bayar (hutang) bertambah secara bisnis — tercatat sebagai arus kas masuk.',
            'pendapatan_terima_piutang' => 'Pelunasan piutang dari debitur; arus kas masuk.',
            'pendapatan_lainnya' => 'Pendapatan lainnya',
            'pengeluaran_makanan' => 'Pengeluaran untuk makanan dan minuman',
            'pengeluaran_transport' => 'Pengeluaran untuk transportasi',
            'pengeluaran_hunian' => 'Pengeluaran untuk tempat tinggal',
            'pengeluaran_utilitas' => 'Pengeluaran untuk listrik, air, internet, dll',
            'pengeluaran_kesehatan' => 'Pengeluaran untuk kesehatan dan pengobatan',
            'pengeluaran_perawatan_diri' => 'Pengeluaran untuk perawatan diri (salon, skincare, potong rambut)',
            'pengeluaran_pendidikan' => 'Pengeluaran untuk pendidikan',
            'pengeluaran_belanja' => 'Pengeluaran untuk belanja kebutuhan',
            'pengeluaran_pakaian' => 'Pengeluaran untuk pakaian, sepatu, dan fashion',
            'pengeluaran_acara' => 'Pengeluaran untuk acara, hajatan, catering, dan event',
            'pengeluaran_otomotif' => 'Pengeluaran untuk servis dan perawatan kendaraan',
            'pengeluaran_sosial' => 'Pengeluaran untuk sosial, kondangan, arisan, dan reuni',
            'pengeluaran_hadiah' => 'Pengeluaran untuk hadiah, kado, dan bingkisan',
            'pengeluaran_hiburan' => 'Pengeluaran untuk hiburan dan rekreasi',
            'pengeluaran_pulsa_token' => 'Pengeluaran untuk pulsa, token listrik, voucher, dan sejenisnya',
            'pengeluaran_tagihan' => 'Pengeluaran untuk tagihan rutin',
            'pengeluaran_investasi' => 'Pengeluaran untuk investasi',
            'pengeluaran_pinjaman' => 'Pengeluaran untuk pembayaran pinjaman',
            'pengeluaran_bayar_hutang' => 'Pelunasan atau cicilan hutang ke pihak lain; arus kas keluar.',
            'pengeluaran_piutang' => 'Memberi pinjaman / piutang usaha; uang keluar, hak terima kembali bertambah.',
            'pengeluaran_cicilan' => 'Pengeluaran untuk cicilan dan angsuran (motor, mobil, rumah, KPR, dll)',
            'pengeluaran_asuransi' => 'Pengeluaran untuk asuransi',
            'pengeluaran_pajak' => 'Pengeluaran untuk pajak',
            'pengeluaran_donasi' => 'Pengeluaran untuk donasi dan sumbangan',
            'pengeluaran_keluarga' => 'Pengeluaran untuk keluarga (orang tua, istri, anak, adik, kakak)',
            'pengeluaran_langganan' => 'Pengeluaran untuk langganan (Netflix, Spotify, YouTube Premium, dll)',
            'pengeluaran_modal' => 'Pengeluaran untuk modal usaha, pembelian stok, bahan baku, dan kulakan',
            'pengeluaran_operasional' => 'Pengeluaran operasional bisnis seperti packaging, ekspedisi, dan biaya operasional',
            'pengeluaran_transfer' => 'Pengeluaran untuk transfer antar dompet (mutasi internal)',
            'pengeluaran_lainnya' => 'Pengeluaran lainnya',
            'debit_internal' => 'Pemindahan saldo keluar (internal)',
            'kredit_internal' => 'Pemindahan saldo masuk (internal)',
        ];

        foreach ($categories as $categoryData) {
            Category::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'type' => $categoryData['type'],
                    'slug' => $categoryData['slug'],
                ],
                [
                    'name' => $categoryData['name'],
                    'description' => $descriptions[$categoryData['type']] ?? '',
                    'icon' => $categoryData['icon'],
                    'color' => $categoryData['color'],
                    'is_system' => true,
                ]
            );
        }
    }
}
