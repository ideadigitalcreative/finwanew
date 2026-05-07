<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            // Pendapatan
            ['type' => 'pendapatan_gaji', 'name' => 'Gaji', 'slug' => 'gaji', 'icon' => '💰', 'color' => '#10b981'],
            ['type' => 'pendapatan_bonus', 'name' => 'Bonus', 'slug' => 'bonus', 'icon' => '🎁', 'color' => '#10b981'],
            ['type' => 'pendapatan_investasi', 'name' => 'Investasi', 'slug' => 'investasi', 'icon' => '📈', 'color' => '#10b981'],
            ['type' => 'pendapatan_transfer', 'name' => 'Transfer Masuk', 'slug' => 'transfer-masuk', 'icon' => '📥', 'color' => '#10b981'],
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
            ['type' => 'pengeluaran_operasional', 'name' => 'Operasional', 'slug' => 'operasional', 'icon' => '⚙️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_transfer', 'name' => 'Transfer Keluar', 'slug' => 'transfer-keluar', 'icon' => '📤', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_lainnya', 'name' => 'Pengeluaran Lainnya', 'slug' => 'pengeluaran-lainnya', 'icon' => '📝', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_gaji', 'name' => 'Gaji Karyawan', 'slug' => 'gaji-karyawan', 'icon' => '👷', 'color' => '#ef4444'],
        ];

        // Create categories for each tenant
        $tenants = Tenant::all();

        if ($tenants->isEmpty()) {
            // If no tenants exist, create a default tenant first
            $tenant = Tenant::create([
                'name' => 'Default Tenant',
                'slug' => 'default',
                'is_active' => true,
            ]);
            $tenants = collect([$tenant]);
        }

        foreach ($tenants as $tenant) {
            foreach ($categories as $categoryData) {
                Category::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'type' => $categoryData['type'],
                        'slug' => $categoryData['slug'],
                    ],
                    [
                        'name' => $categoryData['name'],
                        'description' => $this->getCategoryDescription($categoryData['type']),
                        'icon' => $categoryData['icon'],
                        'color' => $categoryData['color'],
                        'is_system' => true,
                    ]
                );
            }
        }
    }

    private function getCategoryDescription(string $type): string
    {
        $descriptions = [
            'pendapatan_gaji' => 'Pendapatan dari gaji bulanan',
            'pendapatan_bonus' => 'Pendapatan dari bonus atau komisi',
            'pendapatan_investasi' => 'Pendapatan dari investasi',
            'pendapatan_transfer' => 'Pendapatan dari transfer antar dompet (mutasi internal)',
            'pendapatan_hutang' => 'Uang masuk dari pinjaman (hutang) — arus kas masuk',
            'pendapatan_terima_piutang' => 'Pelunasan piutang dari debitur — arus kas masuk',
            'pendapatan_lainnya' => 'Pendapatan lainnya',
            'pengeluaran_makanan' => 'Pengeluaran untuk makanan dan minuman',
            'pengeluaran_transport' => 'Pengeluaran untuk transportasi',
            'pengeluaran_hunian' => 'Pengeluaran untuk tempat tinggal',
            'pengeluaran_utilitas' => 'Pengeluaran untuk listrik, air, internet, dll',
            'pengeluaran_kesehatan' => 'Pengeluaran untuk kesehatan dan pengobatan',
            'pengeluaran_pendidikan' => 'Pengeluaran untuk pendidikan',
            'pengeluaran_belanja' => 'Pengeluaran untuk belanja kebutuhan',
            'pengeluaran_hiburan' => 'Pengeluaran untuk hiburan dan rekreasi',
            'pengeluaran_tagihan' => 'Pengeluaran untuk tagihan rutin',
            'pengeluaran_investasi' => 'Pengeluaran untuk investasi',
            'pengeluaran_pinjaman' => 'Pengeluaran untuk pembayaran pinjaman',
            'pengeluaran_bayar_hutang' => 'Pelunasan atau pembayaran hutang ke pihak lain',
            'pengeluaran_piutang' => 'Memberi pinjaman / piutang (uang keluar, hak terima kembali)',
            'pengeluaran_cicilan' => 'Pengeluaran untuk cicilan dan angsuran (motor, mobil, rumah, KPR, dll)',
            'pengeluaran_asuransi' => 'Pengeluaran untuk asuransi',
            'pengeluaran_pajak' => 'Pengeluaran untuk pajak',
            'pengeluaran_donasi' => 'Pengeluaran untuk donasi dan sumbangan',
            'pengeluaran_operasional' => 'Pengeluaran untuk biaya operasional bisnis',
            'pengeluaran_transfer' => 'Pengeluaran untuk transfer antar dompet (mutasi internal)',
            'pengeluaran_lainnya' => 'Pengeluaran lainnya',
        ];

        return $descriptions[$type] ?? '';
    }
}
