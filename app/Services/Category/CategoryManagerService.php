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
            ['type' => 'pengeluaran_asuransi', 'name' => 'Asuransi', 'slug' => 'asuransi', 'icon' => '🛡️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pajak', 'name' => 'Pajak', 'slug' => 'pajak', 'icon' => '📊', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_donasi', 'name' => 'Donasi', 'slug' => 'donasi', 'icon' => '❤️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_gaji', 'name' => 'Gaji Karyawan', 'slug' => 'gaji-karyawan', 'icon' => '👷', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_keluarga', 'name' => 'Keluarga', 'slug' => 'keluarga', 'icon' => '👨‍👩‍👧‍👦', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_lainnya', 'name' => 'Pengeluaran Lainnya', 'slug' => 'pengeluaran-lainnya', 'icon' => '📝', 'color' => '#ef4444'],
        ];

        $descriptions = [
            'pendapatan_gaji' => 'Pendapatan dari gaji bulanan',
            'pendapatan_bonus' => 'Pendapatan dari bonus atau komisi',
            'pendapatan_investasi' => 'Pendapatan dari investasi',
            'pendapatan_lainnya' => 'Pendapatan lainnya',
            'pengeluaran_makanan' => 'Pengeluaran untuk makanan dan minuman',
            'pengeluaran_transport' => 'Pengeluaran untuk transportasi',
            'pengeluaran_hunian' => 'Pengeluaran untuk tempat tinggal',
            'pengeluaran_utilitas' => 'Pengeluaran untuk listrik, air, internet, dll',
            'pengeluaran_kesehatan' => 'Pengeluaran untuk kesehatan dan pengobatan',
            'pengeluaran_pendidikan' => 'Pengeluaran untuk pendidikan',
            'pengeluaran_belanja' => 'Pengeluaran untuk belanja kebutuhan',
            'pengeluaran_hiburan' => 'Pengeluaran untuk hiburan dan rekreasi',
            'pengeluaran_pulsa_token' => 'Pengeluaran untuk pulsa, token listrik, voucher, dan sejenisnya',
            'pengeluaran_tagihan' => 'Pengeluaran untuk tagihan rutin',
            'pengeluaran_investasi' => 'Pengeluaran untuk investasi',
            'pengeluaran_pinjaman' => 'Pengeluaran untuk pembayaran pinjaman',
            'pengeluaran_asuransi' => 'Pengeluaran untuk asuransi',
            'pengeluaran_pajak' => 'Pengeluaran untuk pajak',
            'pengeluaran_donasi' => 'Pengeluaran untuk donasi dan sumbangan',
            'pengeluaran_keluarga' => 'Pengeluaran untuk keluarga (orang tua, istri, anak, adik, kakak)',
            'pengeluaran_lainnya' => 'Pengeluaran lainnya',
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
