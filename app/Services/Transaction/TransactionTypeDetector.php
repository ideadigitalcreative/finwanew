<?php

namespace App\Services\Transaction;

/**
 * Satu sumber kebenaran untuk deteksi tipe transaksi (income vs expense).
 *
 * Sebelumnya logika ini terduplikasi di TransactionExtractorService dan
 * CategoryInferenceService dengan urutan cek yang berbeda, menyebabkan bug:
 * - "Uang lembur pondok cabe 1,5 juta" terdeteksi expense oleh CategoryInferenceService
 *   padahal TransactionExtractorService sudah benar mendeteksi income.
 *
 * Service ini menerapkan aturan prioritas berbasis posisi (posisi income keyword
 * di awal pesan menang atas expense pattern), sebagaimana diformalkan di
 * .kiro/specs/income-detection-priority-fix/bugfix.md.
 */
class TransactionTypeDetector
{
    /**
     * Deteksi tipe transaksi dari teks.
     *
     * Urutan aturan (prioritas menurun):
     *   0. Deklarasi eksplisit user di awal ("Pengeluaran ...", "Pemasukan ...")
     *      — menang atas segala hal: AI intent, income keyword, expense pattern.
     *   1. Income keyword di AWAL pesan (position-based) → income
     *   2. Expense override pattern / kata "bayar"        → expense
     *   3. Income keyword via word boundary               → income
     *   4. Default                                        → expense
     */
    public function detect(string $text): string
    {
        $textLower = mb_strtolower(trim($text));
        $incomeKeywords = config('finwa_category_rules.income_detection_keywords', []);

        // Aturan 0 — deklarasi eksplisit user di awal pesan
        $declaredType = self::detectDeclaredType($textLower);
        if ($declaredType !== null) {
            return $declaredType;
        }

        // "terima kasih" bukan "terima" (uang diterima)
        $textLower = trim((string) preg_replace('/\b(?:terima\s*kasih|trima\s*kasih|terimakasih|makasih|thanks?)\b/u', ' ', $textLower));

        // Aturan 0b — frasa tegas yang dulu salah tebak
        $phraseType = self::detectPhraseType($textLower);
        if ($phraseType !== null) {
            return $phraseType;
        }

        // Aturan 1 — prioritas posisi (selaras TransactionExtractorService)
        // Keyword income yang muncul di awal pesan memiliki prioritas tertinggi.
        foreach ($incomeKeywords as $keyword) {
            if (! str_starts_with($textLower, $keyword)) {
                continue;
            }

            $after = strlen($keyword);
            if ($after >= strlen($textLower)
                || $textLower[$after] === ' '
                || ctype_digit($textLower[$after])
            ) {
                return 'income';
            }
        }

        // Aturan 1b — keyword income di awal pesan dengan typo (mis. "pemasukann cash 150000")
        if (preg_match('/^[\p{L}]+/u', $textLower, $m)
            && self::matchesFuzzyIncomeKeyword($m[0], $incomeKeywords)) {
            return 'income';
        }

        // Aturan 2 — expense override
        $isExpenseOverride = false;
        foreach (config('finwa_category_rules.expense_detection_patterns', []) as $pattern) {
            if (str_contains($textLower, $pattern)) {
                $isExpenseOverride = true;
                break;
            }
        }
        if (! $isExpenseOverride && preg_match('/\bbayar\b/u', $textLower)) {
            $isExpenseOverride = true;
        }
        if ($isExpenseOverride) {
            return 'expense';
        }

        // Aturan 3 — income via word boundary
        foreach ($incomeKeywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $textLower)) {
                return 'income';
            }
        }

        // Aturan 3b — typo di tengah kalimat (mis. "terima pemasukann cash 150000")
        if (self::detectFuzzyIncome($textLower)) {
            return 'income';
        }

        // Aturan 4 — default: expense
        return 'expense';
    }

    /**
     * Deteksi deklarasi tipe eksplisit user di AWAL pesan.
     *
     * Contoh: "Pengeluaran mengganti motor ... 3jt" → expense,
     *         "Pemasukan bonus 500rb" → income.
     *
     * Deklarasi depan menang atas segala sinyal lain (AI intent, income
     * keyword "transfer masuk", income override, dst.) karena user secara
     * sadar menyebut tipenya.
     *
     * @return 'income'|'expense'|null null = tidak ada deklarasi
     */
    public static function detectDeclaredType(string $textLower): ?string
    {
        // Kata depan + word boundary ("pengeluaran ", "pemasukan:", "uang masuk 100rb", "saldo keluar 500k")
        if (! preg_match('/^(pengeluaran|pemasukan|(?:uang|saldo)\s+keluar|(?:uang|saldo)\s+masuk)\b/u', $textLower, $m)) {
            return null;
        }

        $word = preg_replace('/\s+/', ' ', $m[1]);

        return in_array($word, ['pengeluaran', 'uang keluar', 'saldo keluar'], true) ? 'expense' : 'income';
    }

    /**
     * Frasa yang maknanya jelas tapi mengandung kata "jebakan":
     *   expense: "tiket masuk" (bukan uang masuk), "biaya/fee admin"
     *   income : "gajian", "transfer dari istri", "uang dari pembeli", "pesanan masuk"
     *
     * @return 'income'|'expense'|null
     */
    public static function detectPhraseType(string $textLower): ?string
    {
        $spend = '/\b(?:bayar|beli|belanja|kasih|ngasih|memberi|memberikan|traktir|transfer\s+ke|tf\s+ke|kirim\s+ke)\b/u';

        $receiving = '/\b(?:dapat|dapet|dpt|terima|nerima|menerima|dikasih|dikasi|diberi|kiriman|dari)\b/u';

        // Bunga: dari simpanan = pemasukan; dari pinjaman = pengeluaran
        if (preg_match('/\bbunga\s+(?:pinjaman|kpr|cicilan|kartu\s+kredit|kk|paylater|hutang|utang|kredit|pinjol)\b/u', $textLower)) {
            return 'expense';
        }
        if (preg_match('/\bbunga\s+(?:deposito|tabungan|bank|simpanan|rekening|giro)\b|\bdividen\b|\bimbal\s+hasil\b|\bkupon\s+(?:sbr|ori|sr|st|obligasi|sukuk)\b/u', $textLower)
            && ! preg_match($spend, $textLower)) {
            return 'income';
        }

        // Memberi sumbangan/donasi = pengeluaran, kecuali jelas menerima ("dapat sumbangan")
        if (preg_match('/\b(?:sumbangan|donasi|sedekah|infaq|infak|zakat|amal|wakaf|nyumbang|menyumbang)\b/u', $textLower)
            && ! preg_match($receiving, $textLower)) {
            return 'expense';
        }

        if (preg_match('/\b(?:tiket|karcis)\s+masuk\b|\b(?:biaya|fee|ongkos)\s+(?:admin|administrasi|layanan|transfer|tarik)\b|\badmin\s+(?:fee|bank|transfer)\b/u', $textLower)) {
            return 'expense';
        }

        // "Tarik saldo/tunai 500k dari dompet utama" = uang keluar dari dompet itu.
        // Pencairan marketplace ("tarik saldo shopee") tetap pendapatan usaha.
        if (preg_match('/^tarik\s+(?:saldo|tunai|uang|duit|dana|cash)\b/u', $textLower)
            && ! preg_match('/\b(?:shopee|tokped|tokopedia|tiktok|lazada|bukalapak|blibli|marketplace)\b/u', $textLower)) {
            return 'expense';
        }

        // "gajian 5jt" = terima gaji; "bayar gajian karyawan" tetap pengeluaran
        if (preg_match('/\bgajian\b/u', $textLower) && ! preg_match($spend, $textLower)
            && ! preg_match('/\b(?:karyawan|pegawai|pekerja|tukang|art|pembantu|staf|staff)\b/u', $textLower)) {
            return 'income';
        }

        // Uang DARI orang/pihak lain: "transfer dari istri", "uang dari pembeli", "kiriman dari ibu"
        if (preg_match('/\b(?:transfer(?:an)?|tf|trf|uang|duit|kiriman|dikirim|dikirimi|dapat|dapet|dpt|terima|nerima|masuk|setoran|bayaran|pembayaran)\b(?:\s+\S+){0,2}?\s+dari\s+\S+/u', $textLower)
            && ! preg_match($spend, $textLower)) {
            return 'income';
        }
        if (preg_match('/\b(?:dari|oleh)\s+(?:pembeli|pelanggan|customer|konsumen|klien|client|buyer|penyewa)\b/u', $textLower)
            && ! preg_match($spend, $textLower)) {
            return 'income';
        }

        // Pesanan/orderan masuk (penjual)
        if (preg_match('/\b(?:pesanan|orderan|order|penjualan|jualan)\s+(?:masuk|lunas|dibayar|cair)\b/u', $textLower)) {
            return 'income';
        }

        return null;
    }

    /**
     * Cek fuzzy income keyword di seluruh teks (toleransi typo).
     * Dipublikasikan agar jalur duplikat (AI fast path, konfirmasi prompt)
     * memakai aturan yang sama dengan detektor utama.
     */
    public static function detectFuzzyIncome(string $text): bool
    {
        $incomeKeywords = config('finwa_category_rules.income_detection_keywords', []);

        return self::matchesFuzzyIncomeKeywordInWords(mb_strtolower($text), $incomeKeywords);
    }

    /**
     * Cocokkan satu kata terhadap daftar keyword income dengan toleransi typo.
     *
     * Hanya keyword satu kata (frasa dilewati) dan panjang ≥5 huruf.
     * Toleransi: levenshtein ≤ 1 (satu salah ketik, mis. "pemasukann"
     * → "pemasukan", "pendapataan" → "pendapatan").
     *
     * ponytail: sengaja hanya 1 edit — "masukan" (jarak 2 ke "pemasukan")
     * harus tetap expense agar test preservation lolos. Naikkan ke 2 + guard
     * khusus bila perlu menangkap dua typo berurutan.
     */
    private static function matchesFuzzyIncomeKeyword(string $word, array $incomeKeywords): bool
    {
        $word = mb_strtolower($word);

        // Kata baku yang kebetulan 1 huruf dari keyword income — bukan salah ketik
        // ("tarik" ≠ "narik" ojol).
        if (in_array($word, ['tarik'], true)) {
            return false;
        }

        foreach ($incomeKeywords as $keyword) {
            // Fuzzy hanya untuk keyword satu kata; frasa tetap via match persis.
            if (str_contains($keyword, ' ')) {
                continue;
            }
            if (mb_strlen($keyword) < 5) {
                continue;
            }

            if (levenshtein($word, $keyword) <= 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pencarian fuzzy kata per kata di seluruh kalimat (untuk aturan 3b).
     * Contoh cocok: "pemasukann" → "pemasukan", "pendapataan" → "pendapatan".
     */
    private static function matchesFuzzyIncomeKeywordInWords(string $text, array $incomeKeywords): bool
    {
        $words = preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (! $words) {
            return false;
        }

        foreach ($words as $word) {
            if (self::matchesFuzzyIncomeKeyword($word, $incomeKeywords)) {
                return true;
            }
        }

        return false;
    }
}
