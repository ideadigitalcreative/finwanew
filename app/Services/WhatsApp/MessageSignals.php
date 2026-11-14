<?php

namespace App\Services\WhatsApp;

/**
 * Sinyal deterministik dari satu pesan teks: apakah berisi kata query, periode,
 * kata transaksi, nominal uang, hutang/piutang, atau permintaan statistik.
 *
 * Dipisah dari ProcessIncomingMessage agar bisa diuji langsung dan agar semua
 * jalur memakai aturan yang sama. Daftar kata ada di config/finwa_query.php
 * (+ config/finwa_category_rules.php untuk kata transaksi).
 */
final class MessageSignals
{
    private function __construct(
        public readonly string $text,
        /** Ada kata query ("pengeluaran", "lihat", "rincian", ...) */
        public readonly bool $hasQueryKeyword,
        /** Ada periode ("bulan ini", "september", "2 bulan lalu", ...) */
        public readonly bool $hasPeriodKeyword,
        /** Ada kata transaksi (kategori/merchant/slang/kata kerja beli-bayar) */
        public readonly bool $hasTransactionKeyword,
        /** Ada angka apa saja (perilaku lama; dipakai jalur edit "ubah jadi 50") */
        public readonly bool $hasAmount,
        /** Ada nominal UANG yang meyakinkan (25rb, 50k, Rp 5000, 25.000, ≥3 digit) */
        public readonly bool $hasMoneyAmount,
        /** Kalimat diawali kata query */
        public readonly bool $startsWithQueryWord,
        /** Pesan adalah pertanyaan/laporan, bukan pencatatan */
        public readonly bool $looksLikeQuery,
        public readonly bool $hasDebtKeyword,
        public readonly bool $hasStatisticsKeyword,
    ) {}

    public static function analyze(string $textLower): self
    {
        $queryKeyword = self::containsAny($textLower, config('finwa_query.query_keywords', []));
        $period = self::hasPeriod($textLower);
        $moneyAmount = self::hasMoneyAmount($textLower);
        $startsWithQuery = self::startsWithAny($textLower, config('finwa_query.query_start_words', []));

        return new self(
            text: $textLower,
            hasQueryKeyword: $queryKeyword,
            hasPeriodKeyword: $period,
            hasTransactionKeyword: self::hasTransactionKeyword($textLower),
            hasAmount: (bool) preg_match('/\d+\s*(rb|ribu|k|jt|juta)?/i', $textLower),
            hasMoneyAmount: $moneyAmount,
            startsWithQueryWord: $startsWithQuery,
            // Query + periode, dan diawali kata query ATAU tanpa nominal uang:
            //   "pengeluaran 500rb bulan ini"        → query (dwimakna, tetap query)
            //   "beli pulsa total 50rb hari ini"     → transaksi
            //   "bayar daftar ulang bulan ini 500rb" → transaksi
            looksLikeQuery: $queryKeyword && $period && ($startsWithQuery || ! $moneyAmount),
            // Substring sengaja dipertahankan: "pelunasan" ⊃ "lunas", "dipinjemin" ⊃ "pinjem"
            hasDebtKeyword: self::containsAnySubstring($textLower, config('finwa_query.debt_keywords', [])),
            hasStatisticsKeyword: self::hasStatistics($textLower),
        );
    }

    /**
     * Pertanyaan NASIHAT/EDUKASI keuangan yang seharusnya dijawab Chat LLM,
     * bukan perintah/laporan: "cara agar bisa menabung ?", "tips hemat bulan ini",
     * "apa itu dana darurat", "nabung itu penting ga?".
     *
     * Bukan pertanyaan nasihat:
     *  - membawa nominal uang ("cara investasi 1 juta" → biarkan jalur lama)
     *  - pertanyaan tentang APLIKASI ("cara tambah dompet", "fitur finwa") → FAQ/Panduan
     *  - pertanyaan DATA ("berapa pengeluaran bulan ini?") → laporan keuangan
     */
    public function isAdviceQuestion(): bool
    {
        $t = $this->text;

        if ($this->hasMoneyAmount) {
            return false;
        }

        // Pertanyaan tentang fitur aplikasi → FAQ/Panduan, bukan LLM
        if (preg_match('/\b(?:finwa|aplikasi|app|bot|dompet|rekening|transaksi|struk|catat|pengingat|reminder|langganan|premium|paket|daftar|login|akun|fitur|export|pdf|laporan|kategori)\b/u', $t)) {
            return false;
        }

        // Pertanyaan data ("berapa pengeluaran bulan ini?") → laporan
        if (preg_match('/^\s*(?:berapa|brp|cek|lihat|total|ringkasan|rekap|rincian|daftar)\b/u', $t)) {
            return false;
        }

        $hasTopic = self::containsAnySubstring($t, config('finwa_query.finance_topics', []));

        $startsWithQuestion = (bool) preg_match(
            '/^\s*(?:(?:kak|min|bang|mas|mbak|halo|hai|pagi|siang|sore|malam)[\s,]+)?(?:cara|caranya|gimana|bagaimana|gmn|apa\s+itu|apa\s+sih|apa\s+bedanya|apa\s+beda|beda(?:nya)?|tips|kenapa|mengapa|knp|saran|sebaiknya|mending|apakah|bisakah|boleh\s*(?:ga|gak|nggak|tidak|ngga)|perlu\s*(?:ga|gak|nggak|tidak|ngga)|kalau\s+mau|kalo\s+mau|aku\s+harus|saya\s+harus|enaknya|idealnya|berapa\s+persen)\b/u',
            $t
        );

        if ($startsWithQuestion) {
            // Kata tanya + topik keuangan, atau kata tanya yang sangat khas nasihat
            return $hasTopic || (bool) preg_match('/^\s*(?:tips|saran|apa\s+itu|bedanya|apa\s+bedanya)\b/u', $t);
        }

        // "nabung itu penting ga?" — topik keuangan + diakhiri tanda tanya, tanpa periode data
        return $hasTopic && str_ends_with(rtrim($t), '?') && ! $this->hasPeriodKeyword;
    }

    /**
     * Cek kata kunci dengan memperhatikan batas kata untuk kata pendek.
     *
     * str_contains() murni membuat kata kunci pendek cocok di dalam kata lain:
     * 'ff' ⊂ "coffee", 'rs' ⊂ "kursus", 'lab' ⊂ "label", 'app' ⊂ "happy",
     * 'fore' ⊂ "before", 'top' ⊂ "laptop", 'ml' ⊂ "html", 'emas' ⊂ "pemasukan".
     *  - ≤ 3 huruf : harus kata utuh (batas kiri & kanan)
     *  - 4 huruf   : harus di awal kata (boleh berimbuhan akhir: "kopinya")
     *  - ≥ 5 huruf / frasa : substring (mis. "makanan" ⊃ "makan")
     */
    public static function hasKeyword(string $text, string $keyword): bool
    {
        $len = mb_strlen($keyword);
        if ($len >= 5 || str_contains($keyword, ' ')) {
            return str_contains($text, $keyword);
        }

        $right = $len <= 3 ? '(?![\p{L}\p{N}])' : '';

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').$right.'/u', $text) === 1;
    }

    /**
     * Nominal uang: "25rb", "50k", "1,5jt", "Rp 5000", "25.000", "15000".
     * Bukan nominal: "rapat jam 3", "tanggal 5", tahun "2026".
     */
    public static function hasMoneyAmount(string $textLower): bool
    {
        return (bool) preg_match(
            '/(?:\brp\.?\s*\d)|(?:\b\d+(?:[.,]\d+)?\s*(?:rb|ribu|rebu|k|jt|juta)\b)|(?:\b\d{1,3}(?:\.\d{3})+\b)|(?:\b\d{3,}\b)/iu',
            (string) preg_replace('/\b(?:19|20)\d{2}\b/', '', \App\Support\AmountParser::wordsToDigits($textLower))
        );
    }

    public static function hasPeriod(string $textLower): bool
    {
        foreach (config('finwa_query.period_keywords', []) as $period) {
            if (str_contains($textLower, $period)) {
                return true;
            }
        }

        // Nama bulan sebagai kata utuh ("transaksi september", "rekap okt")
        $months = array_keys(config('finwa_query.month_names', []));
        if ($months !== [] && preg_match(
            '/(?<![\p{L}\p{N}])(?:'.implode('|', array_map(fn ($m) => preg_quote($m, '/'), $months)).')(?![\p{L}\p{N}])/u',
            $textLower
        )) {
            return true;
        }

        // "2 bulan lalu", "7 hari terakhir"
        return (bool) preg_match('/\b\d+\s*(?:hari|minggu|bulan|tahun)\s*(?:yang\s+)?(?:lalu|terakhir)\b/u', $textLower);
    }

    public static function hasTransactionKeyword(string $textLower): bool
    {
        $keywords = array_values(array_unique(array_merge(
            array_keys(config('finwa_category_rules.expense_keywords', [])),
            array_keys(config('finwa_category_rules.income_keywords', [])),
            array_keys(config('finwa_category_rules.local_expense_extras', [])),
            config('finwa_query.transaction_slang_keywords', []),
        )));

        if (self::containsAny($textLower, $keywords)) {
            return true;
        }

        // Kata kerja transaksi berdiri sendiri ("bayar finwa 50rb") walau objeknya
        // tidak ada di daftar — TransactionService menanganinya sebagai expense.
        return (bool) preg_match(
            '/\b(?:bayar|beli|belanja|bayarin|nabung|setor|tarik)\b|\b(?:uang|biaya|ongkos)\s+\w+|\bsaldo\s+(?:keluar|masuk)\b/u',
            $textLower
        );
    }

    private static function hasStatistics(string $textLower): bool
    {
        foreach (config('finwa_query.statistics_keywords', []) as $keyword) {
            // "top up" adalah isi saldo/pulsa, bukan permintaan "top kategori"
            if ($keyword === 'top' && preg_match('/\btop\s*-?\s*up\b/u', $textLower)) {
                continue;
            }
            if (self::hasKeyword($textLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private static function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (self::hasKeyword($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private static function containsAnySubstring(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private static function startsWithAny(string $text, array $words): bool
    {
        if ($words === []) {
            return false;
        }

        return (bool) preg_match(
            '/^\s*(?:'.implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words)).')\b/u',
            $text
        );
    }
}
