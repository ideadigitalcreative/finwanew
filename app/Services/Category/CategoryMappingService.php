<?php

namespace App\Services\Category;

/**
 * CategoryMappingService - Handles category mapping and detection
 * 
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 * 
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class CategoryMappingService
{
    /**
     * Map FinWa-AI kategori to system category_type format
     * 
     * @param string|null $kategori The kategori from FinWa-AI (e.g., 'makan', 'transport', 'gaji')
     * @param bool $isIncome Whether this is an income transaction
     * @return string The mapped category_type (e.g., 'pengeluaran_makanan', 'pendapatan_gaji')
     * 
     * MOVED FROM: ProcessIncomingMessage::mapFinwaKategoriToCategoryType()
     * LINES: 4966-5101
     * MODIFICATION: None (structural move only)
     */
    public function mapFinwaKategoriToCategoryType(?string $kategori, bool $isIncome): string
    {
        if (empty($kategori)) {
            return $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
        }
        
        $kategoriLower = strtolower(trim($kategori));
        
        // Mapping for expense categories
        $expenseMapping = [
            'makan' => 'pengeluaran_makanan',
            'makanan' => 'pengeluaran_makanan',
            'minuman' => 'pengeluaran_makanan',
            'food' => 'pengeluaran_makanan',
            'transport' => 'pengeluaran_transport',
            'transportasi' => 'pengeluaran_transport',
            'bensin' => 'pengeluaran_transport',
            'parkir' => 'pengeluaran_transport',
            'ojek' => 'pengeluaran_transport',
            'grab' => 'pengeluaran_transport',
            'gojek' => 'pengeluaran_transport',
            'hunian' => 'pengeluaran_hunian',
            'rumah' => 'pengeluaran_hunian',
            'kos' => 'pengeluaran_hunian',
            'sewa' => 'pengeluaran_hunian',
            'utilitas' => 'pengeluaran_utilitas',
            'listrik' => 'pengeluaran_utilitas',
            'air' => 'pengeluaran_utilitas',
            'internet' => 'pengeluaran_utilitas',
            'wifi' => 'pengeluaran_utilitas',
            'kesehatan' => 'pengeluaran_kesehatan',
            'obat' => 'pengeluaran_kesehatan',
            'dokter' => 'pengeluaran_kesehatan',
            'pendidikan' => 'pengeluaran_pendidikan',
            'sekolah' => 'pengeluaran_pendidikan',
            'buku' => 'pengeluaran_pendidikan',
            'belanja' => 'pengeluaran_belanja',
            'shopping' => 'pengeluaran_belanja',
            'hiburan' => 'pengeluaran_hiburan',
            'entertainment' => 'pengeluaran_hiburan',
            'nonton' => 'pengeluaran_hiburan',
            'game' => 'pengeluaran_hiburan',
            'pulsa' => 'pengeluaran_pulsa_token',
            'token' => 'pengeluaran_pulsa_token',
            'kuota' => 'pengeluaran_pulsa_token',
            'tagihan' => 'pengeluaran_tagihan',
            'bill' => 'pengeluaran_tagihan',
            'investasi' => 'pengeluaran_investasi',
            'invest' => 'pengeluaran_investasi',
            'pinjaman' => 'pengeluaran_pinjaman',
            'cicilan' => 'pengeluaran_pinjaman',
            'kredit' => 'pengeluaran_pinjaman',
            'asuransi' => 'pengeluaran_asuransi',
            'insurance' => 'pengeluaran_asuransi',
            'pajak' => 'pengeluaran_pajak',
            'tax' => 'pengeluaran_pajak',
            'donasi' => 'pengeluaran_donasi',
            'sedekah' => 'pengeluaran_donasi',
            'zakat' => 'pengeluaran_donasi',
            'gaji' => 'pengeluaran_gaji',
            'upah' => 'pengeluaran_gaji',
            'honor' => 'pengeluaran_gaji',
            'transfer' => 'pengeluaran_lainnya',
            'kirim' => 'pengeluaran_lainnya',
            'setor' => 'pengeluaran_lainnya',
            // Kategori Gaji/Upah (pembayaran ke karyawan)
            'gaji' => 'pengeluaran_gaji',
            'upah' => 'pengeluaran_gaji',
            'honor' => 'pengeluaran_gaji',
            // Kategori Keluarga
            'keluarga' => 'pengeluaran_keluarga',
            'orang tua' => 'pengeluaran_keluarga',
            'ortu' => 'pengeluaran_keluarga',
            'ibu' => 'pengeluaran_keluarga',
            'bapak' => 'pengeluaran_keluarga',
            'ayah' => 'pengeluaran_keluarga',
            'mama' => 'pengeluaran_keluarga',
            'papa' => 'pengeluaran_keluarga',
            'istri' => 'pengeluaran_keluarga',
            'suami' => 'pengeluaran_keluarga',
            'anak' => 'pengeluaran_keluarga',
            'adik' => 'pengeluaran_keluarga',
            'kakak' => 'pengeluaran_keluarga',
            'kasih' => 'pengeluaran_keluarga',
            'lainnya' => 'pengeluaran_lainnya',
        ];
        
        // Mapping for income categories
        $incomeMapping = [
            'gaji' => 'pendapatan_gaji',
            'salary' => 'pendapatan_gaji',
            'upah' => 'pendapatan_gaji',
            'bonus' => 'pendapatan_bonus',
            'thr' => 'pendapatan_bonus',
            'komisi' => 'pendapatan_bonus',
            'hadiah' => 'pendapatan_bonus',
            'angpao' => 'pendapatan_bonus',
            'investasi' => 'pendapatan_investasi',
            'dividen' => 'pendapatan_investasi',
            'bunga' => 'pendapatan_investasi',
            'lainnya' => 'pendapatan_lainnya',
            'freelance' => 'pendapatan_lainnya',
            'proyek' => 'pendapatan_lainnya',
            'dikasih' => 'pendapatan_lainnya',
            'kiriman' => 'pendapatan_lainnya',
            'keluarga' => 'pendapatan_lainnya',
            'papi' => 'pendapatan_lainnya',
            'mama' => 'pendapatan_lainnya',
            'ortu' => 'pendapatan_lainnya',
        ];
        
        // Use appropriate mapping based on transaction type
        $mapping = $isIncome ? $incomeMapping : $expenseMapping;
        
        // Check direct match
        if (isset($mapping[$kategoriLower])) {
            return $mapping[$kategoriLower];
        }
        
        // Try partial match (kategori contains keyword)
        foreach ($mapping as $keyword => $categoryType) {
            if (str_contains($kategoriLower, $keyword) || str_contains($keyword, $kategoriLower)) {
                return $categoryType;
            }
        }
        
        // Default fallback
        return $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
    }

    /**
     * Determine category from description text
     * 
     * MOVED FROM: ProcessIncomingMessage::determineCategoryFromDescription()
     * LINES: 3145-3198
     * MODIFICATION: None (structural move only)
     */
    public function determineCategoryFromDescription(string $description): string
    {
        $descLower = strtolower($description);
        
        // Category mapping based on keywords
        $categoryMap = [
            // Makanan
            'makan' => 'pengeluaran_makanan',
            'makanan' => 'pengeluaran_makanan',
            'minum' => 'pengeluaran_makanan',
            'kopi' => 'pengeluaran_makanan',
            'sarapan' => 'pengeluaran_makanan',
            'lunch' => 'pengeluaran_makanan',
            'dinner' => 'pengeluaran_makanan',
            
            // Transport
            'grab' => 'pengeluaran_transport',
            'gojek' => 'pengeluaran_transport',
            'bensin' => 'pengeluaran_transport',
            'parkir' => 'pengeluaran_transport',
            'ojek' => 'pengeluaran_transport',
            'taxi' => 'pengeluaran_transport',
            
            // Belanja
            'beli' => 'pengeluaran_belanja',
            'belanja' => 'pengeluaran_belanja',
            'shopping' => 'pengeluaran_belanja',
            'bayar' => 'pengeluaran_belanja',
            
            // Tagihan
            'listrik' => 'pengeluaran_tagihan',
            'air' => 'pengeluaran_tagihan',
            'internet' => 'pengeluaran_tagihan',
            'wifi' => 'pengeluaran_tagihan',
            
            // Pulsa
            'pulsa' => 'pengeluaran_pulsa_token',
            'kuota' => 'pengeluaran_pulsa_token',
            'token' => 'pengeluaran_pulsa_token',
            
            // Donasi/Amal
            'sedekah' => 'pengeluaran_donasi',
            'donasi' => 'pengeluaran_donasi',
            'infaq' => 'pengeluaran_donasi',
            'infak' => 'pengeluaran_donasi',
            'zakat' => 'pengeluaran_donasi',
            'sumbangan' => 'pengeluaran_donasi',
            'amal' => 'pengeluaran_donasi',
        ];
        
        // Check for keywords
        foreach ($categoryMap as $keyword => $category) {
            if (str_contains($descLower, $keyword)) {
                return $category;
            }
        }
        
        // Default to general expense
        return 'pengeluaran_lainnya';
    }
    
    /**
     * Determine category from description text (extended version)
     * 
     * MOVED FROM: ProcessIncomingMessage::determineCategoryFromText()
     * LINES: 7130-7240
     * MODIFICATION: None (structural move only)
     */
    public function determineCategoryFromText(string $description, bool $isIncome = false): string
    {
        $textLower = strtolower($description);
        
        if ($isIncome) {
            $incomeMap = [
                'gaji' => 'pendapatan_gaji',
                'bonus' => 'pendapatan_bonus',
                'honor' => 'pendapatan_gaji',
                'upah' => 'pendapatan_gaji',
                'freelance' => 'pendapatan_lainnya',
                'proyek' => 'pendapatan_lainnya',
            ];
            
            foreach ($incomeMap as $keyword => $category) {
                if (str_contains($textLower, $keyword)) {
                    return $category;
                }
            }
            
            return 'pendapatan_lainnya';
        }
        
        // Expense categories
        $expenseMap = [
            // Makanan
            'makan' => 'pengeluaran_makanan',
            'sarapan' => 'pengeluaran_makanan',
            'lunch' => 'pengeluaran_makanan',
            'dinner' => 'pengeluaran_makanan',
            'kopi' => 'pengeluaran_makanan',
            'coffee' => 'pengeluaran_makanan',
            'jajan' => 'pengeluaran_makanan',
            'snack' => 'pengeluaran_makanan',
            'cemilan' => 'pengeluaran_makanan',
            'risol' => 'pengeluaran_makanan',
            'martabak' => 'pengeluaran_makanan',
            'bakso' => 'pengeluaran_makanan',
            'mie' => 'pengeluaran_makanan',
            'nasi' => 'pengeluaran_makanan',
            'ayam' => 'pengeluaran_makanan',
            'minuman' => 'pengeluaran_makanan',
            'air' => 'pengeluaran_makanan',
            
            // Hiburan
            'game' => 'pengeluaran_hiburan',
            'steam' => 'pengeluaran_hiburan',
            'topup game' => 'pengeluaran_hiburan',
            'voucher game' => 'pengeluaran_hiburan',
            'nonton' => 'pengeluaran_hiburan',
            'bioskop' => 'pengeluaran_hiburan',
            'cinema' => 'pengeluaran_hiburan',
            'netflix' => 'pengeluaran_hiburan',
            'spotify' => 'pengeluaran_hiburan',
            'karaoke' => 'pengeluaran_hiburan',
            
            // Transport
            'grab' => 'pengeluaran_transport',
            'gojek' => 'pengeluaran_transport',
            'ojol' => 'pengeluaran_transport',
            'ojek' => 'pengeluaran_transport',
            'taxi' => 'pengeluaran_transport',
            'taksi' => 'pengeluaran_transport',
            'bensin' => 'pengeluaran_transport',
            'parkir' => 'pengeluaran_transport',
            'transport' => 'pengeluaran_transport',
            'ongkos' => 'pengeluaran_transport',
            'pulang' => 'pengeluaran_transport',
            'pergi' => 'pengeluaran_transport',
            
            // Belanja
            'belanja' => 'pengeluaran_belanja',
            'beli' => 'pengeluaran_belanja',
            'shopee' => 'pengeluaran_belanja',
            'tokped' => 'pengeluaran_belanja',
            'kantong' => 'pengeluaran_belanja', // kantong asi, kantong plastik, dll
            'asi' => 'pengeluaran_belanja',
            'popok' => 'pengeluaran_belanja',
            'pampers' => 'pengeluaran_belanja',
            'diapers' => 'pengeluaran_belanja',
            'susu' => 'pengeluaran_belanja', // bisa susu bayi atau minuman
            
            // Laundry & Rumah
            'laundry' => 'pengeluaran_lainnya',
            'cuci' => 'pengeluaran_lainnya',
            'setrika' => 'pengeluaran_lainnya',
            
            // Tagihan
            'listrik' => 'pengeluaran_tagihan',
            'pln' => 'pengeluaran_tagihan',
            'air pdam' => 'pengeluaran_tagihan',
            'internet' => 'pengeluaran_tagihan',
            'wifi' => 'pengeluaran_tagihan',
            
            // Pulsa
            'pulsa' => 'pengeluaran_pulsa_token',
            'kuota' => 'pengeluaran_pulsa_token',
            'topup' => 'pengeluaran_pulsa_token',
            
            // Sosial/Amal - Donasi
            'sedekah' => 'pengeluaran_donasi',
            'infaq' => 'pengeluaran_donasi',
            'infak' => 'pengeluaran_donasi',
            'zakat' => 'pengeluaran_donasi',
            'donasi' => 'pengeluaran_donasi',
            'sumbangan' => 'pengeluaran_donasi',
            'amal' => 'pengeluaran_donasi',
            'kasih' => 'pengeluaran_lainnya',
            'ngasih' => 'pengeluaran_lainnya',
            'kirimin' => 'pengeluaran_lainnya',
        ];
        
        foreach ($expenseMap as $keyword => $category) {
            if (str_contains($textLower, $keyword)) {
                return $category;
            }
        }
        
        return 'pengeluaran_lainnya';
    }
}
