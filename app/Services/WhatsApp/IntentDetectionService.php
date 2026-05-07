<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

class IntentDetectionService
{
    /**
     * Detect intent-related flags from message text.
     *
     * @param  string  $messageText
     * @return array{hasQueryKeyword: bool, hasPeriodKeyword: bool, hasTransactionKeyword: bool, hasAmount: bool, hasStatisticsKeyword: bool, isViewTransactionRequest: bool, isExplicitTransactionQuery: bool, isBatchFormat: bool, textLower: string}
     */
    public function detectIntentFlags(string $messageText, bool $isFromOcr = false): array
    {
        $textLower = mb_strtolower(trim($messageText), 'UTF-8');

        // Query keywords
        $queryKeywords = [
            'pengeluaran', 'pemasukan', 'pendapatan', 'ringkasan', 'saldo', 'cashflow', 'cash flow',
            'total', 'berapa', 'cek', 'lihat',
            'rincian', 'detail', 'rinci',
            'rekap', 'rekapan', 'rangkuman', 'laporan', 'mutasi', 'catatan',
            'habis', 'udah habis', 'sudah habis', 'udah keluar', 'sudah keluar',
            'masuk berapa', 'keluar berapa', 'spending', 'income', 'expense',
            'uang keluar', 'duit keluar', 'biaya',
            'uang masuk', 'duit masuk', 'penghasilan', 'omset', 'omzet', 'revenue',
            'arus kas', 'keuangan',
        ];

        // Period keywords
        $periodKeywords = [
            'hari ini', 'minggu ini', 'bulan ini', 'tahun ini',
            'kemarin', 'bulan lalu', 'minggu lalu', 'tahun lalu',
            'hr ini', 'hari ni', 'bln ini', 'bln lalu', 'kmrn', 'kemaren', 'bulan kemarin',
            'semalem', 'semalam', 'thn ini', 'thn lalu', 'mgg ini', 'mgg lalu',
            'hari kemarin', 'hari kemaren',
            'today', 'this month', 'this week', 'last month', 'last week', 'yesterday',
            'terakhir', 'kebelakang', 'sebelumnya', 'yang lalu',
            'januari', 'februari', 'maret', 'april', 'mei', 'juni',            'juli', 'agustus', 'september', 'oktober', 'november', 'desember',
            'tanggal', 'tgl',
        ];

        $shortMonthKeywords = ['jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'ags', 'sep', 'okt', 'nov', 'des'];

        // Transaction keywords from config
        $transactionKeywords = array_merge(
            config('finwa_keywords.transaction_keywords', []),
            config('finwa_keywords.extra_transaction_keywords', [])
        );
        $shortAmbiguousKeywords = config('finwa_keywords.short_ambiguous_transaction_keywords', []);

        $hasAmount = (bool) preg_match('/\d+\s*(rb|ribu|k|jt|juta)?/i', $textLower);

        // 1. Check Query Keywords
        $hasQueryKeyword = false;
        if (! empty($queryKeywords)) {
            $queryPattern = '/('.implode('|', array_map(fn ($k) => preg_quote($k, '/'), $queryKeywords)).')/iu';
            $hasQueryKeyword = (bool) preg_match($queryPattern, $textLower);
        }

        // 2. Check Period Keywords
        $hasPeriodKeyword = false;
        if (! empty($periodKeywords)) {
            $periodPattern = '/('.implode('|', array_map(fn ($k) => preg_quote($k, '/'), $periodKeywords)).')/iu';
            $hasPeriodKeyword = (bool) preg_match($periodPattern, $textLower);
        }
        // Short month abbreviations need word boundary to avoid false positives
        if (! $hasPeriodKeyword && ! empty($shortMonthKeywords)) {
            $shortMonthPattern = '/\b('.implode('|', $shortMonthKeywords).')\b/iu';
            $hasPeriodKeyword = (bool) preg_match($shortMonthPattern, $textLower);
        }

        // 3. Check Transaction Keywords (separate Normal & Ambiguous)
        $hasTransactionKeyword = false;
        $ambiguousSubset = array_intersect($transactionKeywords, $shortAmbiguousKeywords);
        $normalSubset = array_diff($transactionKeywords, $shortAmbiguousKeywords);

        if (! empty($normalSubset)) {
            $normalPattern = '/('.implode('|', array_map(fn ($k) => preg_quote($k, '/'), $normalSubset)).')/iu';
            $hasTransactionKeyword = (bool) preg_match($normalPattern, $textLower);
        }
        if (! $hasTransactionKeyword && ! empty($ambiguousSubset)) {
            $ambiguousPattern = '/\b('.implode('|', array_map(fn ($k) => preg_quote($k, '/'), $ambiguousSubset)).')\b/iu';
            $hasTransactionKeyword = (bool) preg_match($ambiguousPattern, $textLower);
        }

        // 4. Check Statistics Keywords
        $statisticsKeywords = [
            'terbesar', 'tertinggi', 'terendah', 'terkecil', 'paling', 'top',
            'rata-rata', 'average', 'statistik', 'stats', 'analisis', 'analysis',
            'tren', 'trend', 'kategori terbesar', 'spending habit',
        ];
        $statPattern = '/('.implode('|', array_map(fn ($k) => preg_quote($k, '/'), $statisticsKeywords)).')/iu';
        $hasStatisticsKeyword = (bool) preg_match($statPattern, $textLower);

        // 5. Check View Transaction Request
        $isViewTransactionRequest = str_contains($textLower, 'daftar transaksi') ||
                                    str_contains($textLower, 'lihat transaksi') ||
                                    str_contains($textLower, 'cek transaksi') ||
                                    str_contains($textLower, 'list transaksi') ||
                                    str_contains($textLower, 'histori transaksi') ||
                                    str_contains($textLower, 'history transaksi') ||
                                    str_contains($textLower, 'riwayat transaksi');

        // 6. Check Explicit Transaction Query
        // "pengeluaran 100rb beli jajan" = transaction (has query keyword + transaction action verb + amount)
        // "beli apa?" = NOT transaction (no amount)
        // Logic: if has amount AND (hasTransactionKeyword OR has action verb anywhere)
        $actionVerbs = ['beli', 'beli', 'daftar', 'bayar', 'bayar', 'beliin', 'order', 'boking', 'booking', 'jajan', 'makan', 'minum', 'isi', 'topup', 'top up', 'setor', 'tarik', 'transfer', 'kirim', 'gajian'];
        $isExplicitTransactionQuery = false;
        if ($hasAmount) {
            // Check if contains any action verb (with word boundary)
            // The amount can be before or after the action verb
            $actionPattern = '/\b('.implode('|', array_map(fn ($k) => preg_quote($k, '/'), $actionVerbs)).')\b/i';
            if (preg_match($actionPattern, $textLower)) {
                $isExplicitTransactionQuery = true;
            }
        }

        // 7. Check Batch Transaction Format (only if not OCR)
        $isBatchFormat = false;
        if (! $isFromOcr) {
            // Will be determined by BatchTransactionService
            $isBatchFormat = false; // Placeholder — actual check in MessageRouter
        }

        return [
            'hasQueryKeyword' => $hasQueryKeyword,
            'hasPeriodKeyword' => $hasPeriodKeyword,
            'hasTransactionKeyword' => $hasTransactionKeyword,
            'hasAmount' => $hasAmount,
            'hasStatisticsKeyword' => $hasStatisticsKeyword,
            'isViewTransactionRequest' => $isViewTransactionRequest,
            'isExplicitTransactionQuery' => $isExplicitTransactionQuery,
            'isBatchFormat' => $isBatchFormat,
            'textLower' => $textLower,
        ];
    }

    /**
     * Check if message is a simple query (like "habis berapa?")
     */
    public function isSimpleQuery(string $messageText): bool
    {
        $simpleQueryPatterns = [
            '/^habis\s*berapa\??$/i',
            '/^udah\s*habis\s*berapa\??$/i',
            '/^sudah\s*habis\s*berapa\??$/i',
            '/^keluar\s*berapa\??$/i',
            '/^masuk\s*berapa\??$/i',
            '/^udah\s*masuk\s*berapa\??$/i',
            '/^pengeluaran\s*berapa\??$/i',
            '/^pemasukan\s*berapa\??$/i',
            '/^pendapatan\s*berapa\??$/i',
            '/^penghasilan\s*berapa\??$/i',
            '/^omzet\s*berapa\??$/i',
            '/^total\s*hari\s*ini\??$/i',
            '/^total\s*berapa\??$/i',
            '/^berapa\s*total\??$/i',
            '/^ringkasan\??$/i',
            '/^rekap\??$/i',
            '/^rekapan\??$/i',
            '/^rangkuman\??$/i',
            '/^laporan\??$/i',
            '/^mutasi\??$/i',
            '/^catatan\??$/i',
            '/^rincian\??$/i',
            '/^detail\??$/i',
            '/^sisa\s*uang\s*berapa\??$/i',
            '/^uang\s*sisa\s*berapa\??$/i',
            '/^berapa\s*sisa\??$/i',
            '/^duit\s*(sisa|masih)\s*berapa\??$/i',
        ];

        foreach ($simpleQueryPatterns as $pattern) {
            if (preg_match($pattern, trim($messageText))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if message is a balance check command
     */
    public function isBalanceCheck(string $messageText): bool
    {
        return (bool) preg_match('/^(cek\s+)?saldo\??$/i', trim($messageText));
    }

    /**
     * Check if message is a set wallet balance command
     */
    public function isSetWalletBalance(string $messageText): bool
    {
        $textLower = mb_strtolower(trim($messageText), 'UTF-8');

        return preg_match('/^(?:saldo)\b/i', $textLower) && preg_match('/\d/', $textLower);
    }

    /**
     * Check if message is an adjust wallet balance command
     */
    public function isAdjustWalletBalance(string $messageText): bool
    {
        $textLower = mb_strtolower(trim($messageText), 'UTF-8');

        return preg_match('/^(?:tambah|tambahkan|kurangi|kurang|potong)\s+saldo\b/i', $textLower) && preg_match('/\d/', $textLower);
    }

    /**
     * Check if message is an edit context command (correction)
     */
    public function isEditContext(string $messageText, bool $hasAmount): bool
    {
        if (! $hasAmount) {
            return false;
        }

        $editContextKeywords = [
            'salah', 'koreksi', 'harusnya', 'seharusnya', 'ubah jadi', 'ganti jadi',
            'yang bener', 'yang benar', 'ralat',
        ];

        $textLower = mb_strtolower(trim($messageText), 'UTF-8');

        foreach ($editContextKeywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $textLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if message is an undo/delete command
     */
    public function isUndoOrDelete(string $messageText, bool $hasAmount): bool
    {
        $textLower = mb_strtolower(trim($messageText), 'UTF-8');

        // Undo last transaction
        if (preg_match('/\b(undo|batalkan|gak\bjadi|nggak\bjadi|gak\bjadilah|nggak\bjadilah)\b/u', $textLower)) {
            return true;
        }

        // Delete transaction
        if ($hasAmount && preg_match('/\b(hapus|delete|hilangkan)\b/u', $textLower)) {
            return true;
        }

        return false;
    }

    /**
     * Check if message is a recurring bill detection request
     */
    public function isRecurringBillDetection(string $messageText): bool
    {
        $textLower = mb_strtolower(trim($messageText), 'UTF-8');

        return str_contains($textLower, 'tagihan berulang') ||
               str_contains($textLower, 'deteksi tagihan') ||
               str_contains($textLower, 'cek tagihan') ||
               str_contains($textLower, 'daftar tagihan');
    }
}
