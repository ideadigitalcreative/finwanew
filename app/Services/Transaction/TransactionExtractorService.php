<?php

namespace App\Services\Transaction;

use App\Helpers\BatchTransactionHelper;
use App\Services\DebtReceivable\CounterpartyExtractor;
use Illuminate\Support\Facades\Log;

/**
 * TransactionExtractorService - Handles transaction extraction from text
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class TransactionExtractorService
{
    /**
     * Extract amount from text line
     * Supports: 17.000, 17000, 17rb, 17ribu, 17k
     * NOW SUPPORTS BATCH: Detects multiple amounts and sums them
     *
     * MOVED FROM: ProcessIncomingMessage::extractAmountFromText()
     * LINES: 7106-7115
     * MODIFICATION: None (structural move only)
     */
    public function extractAmountFromText(string $text): ?int
    {
        // Use BatchTransactionHelper for advanced batch detection
        return BatchTransactionHelper::extractAmount($text);
    }

    /**
     * Extract description from line (text before the amount)
     *
     * MOVED FROM: ProcessIncomingMessage::extractDescriptionFromLine()
     * LINES: 7117-7128
     * MODIFICATION: None (structural move only)
     */
    public function extractDescriptionFromLine(string $line): string
    {
        // CASE 1: Amount-first format (e.g., "20rb maxim dari JGC ke Callia", "10rb Talang drop j&t")
        if (preg_match('/^\d[\d., ]*\s*(rb|ribu|k|jt|juta)\s+(.+)/i', $line, $matches)) {
            return trim($matches[2]);
        }
        // Also handle plain number amount-first with space-separated thousands (e.g., "75 000 makan")
        if (preg_match('/^\d{1,3}(?:\s+\d{3})+\s+(.+)/', $line, $matches)) {
            return trim($matches[1]);
        }
        // Also handle plain number amount-first (e.g., "20000 maxim dari JGC")
        if (preg_match('/^\d{3,}\s+(.+)/', $line, $matches)) {
            return trim($matches[1]);
        }

        // Remove Rp-prefixed amount patterns from end FIRST
        // e.g., "CNP 100 X 50 X 2,3 SNI Rp 13.730.000" â†’ "CNP 100 X 50 X 2,3 SNI"
        $description = preg_replace('/\s*Rp\.?\s*\d[\d.,]*\s*(rb|ribu|k|jt|juta)?\s*$/i', '', $line);

        // If Rp amount was found and removed, don't apply further stripping
        // (avoids stripping product dimensions like "1,8" or "2,3")
        if ($description !== $line) {
            return trim($description) ?: $line;
        }

        // Remove amount patterns from end (without Rp prefix)
        // Only strip amounts that are clearly monetary (have suffix, or dotted thousands, or ≥3 digits)
        $description = preg_replace('/\s*\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta)\s*$/i', '', $line);
        $description = preg_replace('/\s*\d{1,3}(?:\.\d{3})+\s*$/i', '', $description);
        $description = preg_replace('/\s*\d{1,3}(?:\s+\d{3})+\s*$/i', '', $description);
        $description = preg_replace('/\s*\d{3,}\s*$/', '', $description);

        return trim($description) ?: $line;
    }

    /**
     * Extract product name from description
     *
     * MOVED FROM: ProcessIncomingMessage::extractProductName()
     * LINES: 5103-5127
     * MODIFICATION: None (structural move only)
     */
    public function extractProductName(?string $description): ?string
    {
        if (empty($description)) {
            return null;
        }

        // Remove common prefixes
        $desc = preg_replace('/^(Pembelian|Belanja|Beli|Purchase|Item|Produk)\s+/i', '', $description);

        // Extract first part (product name) before dash or " - "
        if (preg_match('/^([^-]+?)(?:\s*-\s*|\s+x\s+|\s*Qty|QTY|\s*\d+\s*Pcs)/i', $desc, $matches)) {
            return trim($matches[1]);
        }

        // If contains "x" or "Pcs", extract part before it
        if (preg_match('/^(.+?)(?:\s+x\s+|\s*\d+\s*Pcs)/i', $desc, $matches)) {
            return trim($matches[1]);
        }

        // Return first 50 chars if no pattern matches
        return mb_substr(trim($desc), 0, 50);
    }

    /**
     * Extract account name from message text (fallback if AI doesn't extract)
     *
     * MOVED FROM: ProcessIncomingMessage::extractAccountNameFromMessage()
     * LINES: 5129-5198
     * MODIFICATION: None (structural move only)
     */
    public function extractAccountNameFromMessage(string $messageText): ?string
    {
        if (empty($messageText)) {
            return null;
        }

        $messageLower = strtolower($messageText);

        // Patterns to extract account name
        // Patterns to extract account name
        // REFACTOR: Removed loose "ke [word]" and "dari [word]" patterns to prevent
        // misclassifying locations (e.g., "ke Kapota", "dari Kantor") as accounts.
        $patterns = [
            '/pakai\s+saldo\s+(?:bank\s+)?([a-z0-9]+)/i',  // "pakai saldo BCA"
            '/pakai\s+([a-z0-9]+)/i',  // "pakai Gopay"
            '/pake\s+([a-z0-9]+)/i',   // "pake Gopay"

            // STRICTER pattern for "ke" and "dari" - requires explicit type indicator
            '/(?:dari|ke)\s+(?:bank|dompet|rekening|wallet|saldo|akun)\s+([a-z0-9]+)/i',

            // "uang masuk BCA 300rb" / "masuk ke Dana 100rb" â€” bukan "Masuk gaji 5jt"
            '/(?:uang\s+masuk|masuk\s+(?:ke|di))\s+([a-z0-9]+)\s+[\d\.,]/i',

            '/via\s+(?:bank\s+)?([a-z0-9]+)/i',  // "via BCA"
            '/saldo\s+(?:awal\s+|akhir\s+)?(?:bank\s+)?([a-z0-9]+)/i',  // "saldo awal bank BCA" or "saldo gopay"
            '/dengan\s+saldo\s+(?:bank\s+)?([a-z0-9]+)/i',  // "dengan saldo BCA"
            '/menggunakan\s+(?:bank\s+)?([a-z0-9]+)/i',  // "menggunakan BCA"
        ];

        $knownAccounts = ['bca', 'mandiri', 'bni', 'bri', 'cash', 'tunai', 'gopay', 'ovo', 'dana', 'linkaja', 'seabank', 'jago', 'neo', 'bsi', 'jenius', 'rekening'];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $potentialAccount = trim($matches[1]);

                // Validate it's a known bank/wallet name or reasonable length
                // Skip common prepositions/words that might be matched if pattern is loose
                $nonAccountWords = ['akun', 'rekening', 'dompet', 'wallet', 'bank', 'awal', 'akhir', 'sisa', 'total', 'semua', 'berapa', 'ini', 'itu', 'nya', 'saya', 'aku', 'gue', 'gw', 'gaji', 'bonus', 'thr', 'lembur', 'honor', 'upah', 'penjualan', 'omset'];
                if (in_array(strtolower($potentialAccount), $nonAccountWords)) {
                    continue; // Skip this match, try next pattern
                }

                if (in_array(strtolower($potentialAccount), $knownAccounts) || (strlen($potentialAccount) >= 2 && strlen($potentialAccount) <= 15)) {
                    // Skip if it's a number
                    if (! is_numeric($potentialAccount)) {
                        Log::info('Account name extracted from message using regex (Laravel fallback)', [
                            'message_text' => mb_substr($messageText, 0, 100),
                            'account_name' => $potentialAccount,
                            'pattern' => $pattern,
                        ]);

                        return $potentialAccount;
                    }
                }
            }
        }

        // Fallback: check for known bank/wallet names directly
        // This handles "ke BCA", "dari Gopay" correctly because we check against specific known names
        foreach ($knownAccounts as $acc) {
            // Check if known account name appears after account keywords
            if (preg_match('/(?:pakai\s+saldo|dari|via|ke|saldo)\s+(?:bank\s+)?\b'.$acc.'\b/i', $messageText)) {
                Log::info('Account name extracted from message using known list keyword (Laravel fallback)', [
                    'message_text' => mb_substr($messageText, 0, 100),
                    'account_name' => $acc,
                ]);

                return $acc;
            }
        }

        // Last resort: check if known bank name appears with transaction keywords anywhere
        foreach ($knownAccounts as $acc) {
            if (stripos($messageText, $acc) !== false &&
                preg_match('/\b(beli|bayar|gaji|bonus|transfer|pakai|dari|via)\b/i', $messageText)) {
                Log::info('Account name extracted from message using fallback pattern (Laravel fallback)', [
                    'message_text' => mb_substr($messageText, 0, 100),
                    'account_name' => $acc,
                ]);

                return $acc;
            }
        }

        return null;
    }

    /**
     * Extract date from text (kemarin, minggu lalu, tgl 15, etc)
     *
     * MOVED FROM: ProcessIncomingMessage::extractDateFromText()
     * LINES: 7663-7719
     * MODIFICATION: None (structural move only)
     */
    public function extractDateFromText(string $text): ?string
    {
        $textLower = strtolower($text);

        // Today keywords (explicitly mentioning today)
        if (str_contains($textLower, 'tadi') || str_contains($textLower, 'barusan') ||
            str_contains($textLower, 'baru aja') || str_contains($textLower, 'baru saja')) {
            return now()->toDateString(); // Today
        }

        // Relative dates
        if (str_contains($textLower, 'kemarin') || str_contains($textLower, 'yesterday') ||
            str_contains($textLower, 'kmrn') || str_contains($textLower, 'kmrin')) {
            return now()->subDay()->toDateString();
        }

        // Last night = kemarin malam = usually yesterday
        if (str_contains($textLower, 'semalem') || str_contains($textLower, 'semalam') ||
            str_contains($textLower, 'tadi malam') || str_contains($textLower, 'kemarin malam')) {
            // If it's currently morning (before 6 AM), "semalem" might mean last night (today)
            // Otherwise, it means yesterday night
            if (now()->hour < 6) {
                return now()->toDateString();
            }

            return now()->subDay()->toDateString();
        }

        if (str_contains($textLower, 'lusa') || str_contains($textLower, 'kemarin dulu')) {
            return now()->subDays(2)->toDateString();
        }

        if (preg_match('/(\d+)\s*hari\s*(lalu|yang\s*lalu)/i', $textLower, $matches)) {
            $days = (int) $matches[1];

            return now()->subDays($days)->toDateString();
        }

        if (str_contains($textLower, 'minggu lalu') || str_contains($textLower, 'pekan lalu')) {
            return now()->subWeek()->toDateString();
        }

        if (str_contains($textLower, 'bulan lalu')) {
            return now()->subMonth()->toDateString();
        }

        // Specific date patterns
        // "tgl 15", "tanggal 15"
        if (preg_match('/(?:tgl|tanggal)\s*(\d{1,2})(?!\d)/i', $textLower, $matches)) {
            $day = (int) $matches[1];
            $date = now()->setDay($day);
            // If the day is in the future, assume last month
            if ($date->isFuture()) {
                $date = $date->subMonth();
            }

            return $date->toDateString();
        }

        // "15/12" or "15-12" format
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?/', $text, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = isset($matches[3]) ? (int) $matches[3] : now()->year;
            if ($year < 100) {
                $year += 2000;
            }

            try {
                return \Carbon\Carbon::createFromDate($year, $month, $day)->toDateString();
            } catch (\Exception $e) {
                // Invalid date
            }
        }

        return null; // Use current date as fallback
    }

    /**
     * Extract transaction from message text locally (without AI)
     * This handles simple messages like "Makan Pagi Hara Chicken 60rb"
     *
     * @param  string  $messageText  The message text
     * @return array|null Transaction data or null if extraction failed
     *
     * MOVED FROM: ProcessIncomingMessage::extractTransactionLocally()
     * LINES: 7242-7661
     * MODIFICATION: None (structural move only)
     */
    public function extractTransactionLocally(string $messageText): ?array
    {
        $textLower = strtolower($messageText);

        // Extract amount using extractAmountFromText (supports batch transactions)
        $amount = $this->extractAmountFromText($messageText);

        // If no amount found, cannot process
        if (! $amount || $amount <= 0) {
            return null;
        }

        // PRIORITY: empat aliran hutang/piutang (frasa jelas) â€” sebelum inferensi income/expense umum
        $hutangPiutangQuick = $this->detectHutangPiutangLocalExtraction($textLower);
        if ($hutangPiutangQuick !== null) {
            $transactionDate = $this->extractDateFromText($messageText) ?? now()->toDateString();
            $debtMeta = self::debtMetadataFromText($messageText);

            return [
                'type' => $hutangPiutangQuick['type'],
                'amount' => $amount,
                'category_type' => $hutangPiutangQuick['category_type'],
                'description' => $messageText,
                'transaction_date' => $transactionDate,
                'confidence_score' => 0.88,
                'source' => 'local_extraction',
                'account_name' => $this->extractAccountNameFromMessage($messageText),
                'merchant' => null,
                'metadata' => $debtMeta,
            ];
        }

        // Determine transaction type (income vs expense)
        // FIRST: Check for expense override patterns (these take priority)
        $expenseOverridePatterns = config('finwa_category_rules.expense_detection_patterns', []);
        $isExpenseOverride = false;
        foreach ($expenseOverridePatterns as $pattern) {
            if (str_contains($textLower, $pattern)) {
                $isExpenseOverride = true;
                break;
            }
        }
        if (! $isExpenseOverride && preg_match('/\bbayar\b/u', $textLower)) {
            $isExpenseOverride = true;
        }

        // THEN: Check income keywords (if not overridden)
        $incomeKeywords = config('finwa_category_rules.income_detection_keywords', []);

        $isIncome = false;
        if (! $isExpenseOverride) {
            foreach ($incomeKeywords as $keyword) {
                if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $textLower)) {
                    $isIncome = true;
                    break;
                }
            }
        }

        // Determine category from keywords
        // FIRST: Check for specific category overrides
        if ($isExpenseOverride && (preg_match('/\bgaji\b/u', $textLower) || preg_match('/\bupah\b/u', $textLower) || preg_match('/\bhonor\b/u', $textLower))) {
            // "ambil gaji" = paying employee salary, use gaji category
            $categoryType = 'pengeluaran_gaji';
        } else {
            $categoryType = $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
        }

        // Expense categories mapping â€” merged from config (single source of truth)
        $expenseCategoryMap = array_merge(
            config('finwa_category_rules.expense_keywords', []),
            config('finwa_category_rules.local_expense_extras', [])
        );

        // Income categories mapping â€” merged from config
        $incomeCategoryMap = array_merge(
            config('finwa_category_rules.income_keywords', []),
            config('finwa_category_rules.local_income_extras', [])
        );

        if ($isIncome) {
            foreach ($incomeCategoryMap as $keyword => $category) {
                if (str_contains($textLower, strtolower($keyword))) {
                    $categoryType = $category;
                    break;
                }
            }
        } else {
            foreach ($expenseCategoryMap as $keyword => $category) {
                if (str_contains($textLower, $keyword)) {
                    $categoryType = $category;
                    break;
                }
            }
        }

        // Apply AI income overrides from config (single source of truth)
        // Corrects wrong mappings like 'dikasih' → 'pendapatan_transfer' → should be 'pendapatan_lainnya'
        // NOTE: expense overrides (ai_category_overrides) use short-form names that need
        // mapFinwaKategoriToCategoryType resolution, so we DON'T apply them here.
        // Expense keywords are already correctly mapped via expenseCategoryMap.
        $aiIncomeOverrides = config('finwa_category_rules.ai_income_overrides', []);
        foreach ($aiIncomeOverrides as $keyword => $overrideKategori) {
            if (str_contains($textLower, $keyword)) {
                $categoryType = $overrideKategori;
                $isIncome = true;
                break;
            }
        }

        // Extract date from text (kemarin, minggu lalu, tgl 15, etc)
        $transactionDate = $this->extractDateFromText($messageText) ?? now()->toDateString();
        $debtTypes = ['pendapatan_hutang', 'pengeluaran_bayar_hutang', 'pengeluaran_piutang', 'pendapatan_terima_piutang'];
        $debtMeta = in_array($categoryType, $debtTypes, true) ? self::debtMetadataFromText($messageText) : [];

        // Build transaction data
        $row = [
            'type' => $isIncome ? 'income' : 'expense',
            'amount' => $amount,
            'category_type' => $categoryType,
            'description' => $messageText, // Use original message as description
            'transaction_date' => $transactionDate,
            'confidence_score' => 0.85,
            'source' => 'local_extraction',
            'account_name' => $this->extractAccountNameFromMessage($messageText),
            'merchant' => null,
        ];
        if ($debtMeta !== []) {
            $row['metadata'] = $debtMeta;
        }

        return $row;

    }

    /**
     * @return array<string, string>
     */
    private static function debtMetadataFromText(string $messageText): array
    {
        $cp = CounterpartyExtractor::extract($messageText);
        if ($cp === null || $cp === '') {
            return [];
        }

        return [
            'counterparty' => $cp,
            'counterparty_normalized' => mb_strtolower(preg_replace('/\s+/', ' ', $cp)),
        ];
    }

    /**
     * Deteksi empat aliran hutang/piutang dari teks (ekstraksi lokal).
     * Urutan: bayar hutang â†’ terima piutang â†’ keluar piutang â†’ terima hutang.
     *
     * @return array{type: 'income'|'expense', category_type: string}|null
     */
    private function detectHutangPiutangLocalExtraction(string $t): ?array
    {
        $rules = [
            [
                'type' => 'expense',
                'category_type' => 'pengeluaran_bayar_hutang',
                'phrases' => ['bayar hutang', 'bayar utang', 'pelunasan hutang', 'pelunasan utang', 'lunas hutang', 'balikin pinjaman'],
            ],
            [
                'type' => 'income',
                'category_type' => 'pendapatan_terima_piutang',
                'phrases' => ['piutang lunas', 'pelunasan piutang', 'terima piutang', 'terima pelunasan'],
            ],
            [
                'type' => 'expense',
                'category_type' => 'pengeluaran_piutang',
                'phrases' => ['kasih pinjam', 'kasih pinjaman', 'pinjamkan ke', 'pijemin ke', 'piutang ke', 'pinjam ke'],
            ],
            [
                'type' => 'income',
                'category_type' => 'pendapatan_hutang',
                'phrases' => ['dapat pinjaman', 'terima pinjaman', 'pinjaman dari', 'pinjam dari', 'hutang dari', 'dipinjemin'],
            ],
        ];

        foreach ($rules as $rule) {
            foreach ($rule['phrases'] as $p) {
                if (str_contains($t, $p)) {
                    return [
                        'type' => $rule['type'],
                        'category_type' => $rule['category_type'],
                    ];
                }
            }
        }

        return null;
    }
}
