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
        // e.g., "CNP 100 X 50 X 2,3 SNI Rp 13.730.000" → "CNP 100 X 50 X 2,3 SNI"
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
        // REFACTOR: Removed loose "ke [word]" and "dari [word]" patterns to prevent
        // misclassifying locations (e.g., "ke Kapota", "dari Kantor") as accounts.
        // SUPPORT multi-word account names like "BSI POPY", "BCA Dwiki"
        // Word 2 uses [a-z]+ (no digits) to avoid matching amounts like "100rb"
        $patterns = [
            // HIGH PRIORITY: "pakai/pake dompet/wallet [name]" - extract wallet name after dompet/wallet keyword
            // e.g., "beli makan 15rb pakai dompet seabank" -> captures "seabank"
            '/(?:pakai|pake|pakek?|menggunakan)\s+(?:dompet|wallet|akun|rekening)\s+([a-z0-9]+(?:\s+[a-z]+)?)/i',

            '/pakai\s+saldo\s+(?:bank\s+)?([a-z0-9]+(?:\s+[a-z]+)?)/i',
            '/pakai\s+([a-z0-9]+(?:\s+[a-z]+)?)/i',
            '/pake\s+([a-z0-9]+(?:\s+[a-z]+)?)/i',

            '/(?:dari|ke)\s+(?:bank|dompet|rekening|wallet|saldo|akun)\s+([a-z0-9]+(?:\s+[a-z]+)?)/i',

            '/(?:uang\s+masuk|masuk\s+(?:ke|di))\s+([a-z0-9]+(?:\s+[a-z]+)?)\s+[\d\.,]/i',

            // "Masuk dana 2jt", "terima BCA 500rb", "keluar gopay 25rb" — nama bank/e-wallet
            // langsung diikuti nominal (bukan "dana darurat 1jt" / "dana bos 2jt")
            '/^(?:uang\s+)?(?:masuk|keluar|terima|dapat|dapet|dpt)\s+(?:ke\s+|di\s+|dari\s+)?(bca|bri|brimo|bni|mandiri|bsi|btn|cimb|permata|danamon|jago|seabank|blu|jenius|neo|ocbc|gopay|ovo|dana|shopeepay|linkaja|flazz)\s+(?=(?:rp\.?\s*)?\d)/i',

            '/via\s+(?:bank\s+)?([a-z0-9]+(?:\s+[a-z]+)?)/i',
            '/saldo\s+(?:awal\s+|akhir\s+)?(?:bank\s+)?([a-z0-9]+(?:\s+[a-z]+)?)/i',
            '/dengan\s+saldo\s+(?:bank\s+)?([a-z0-9]+(?:\s+[a-z]+)?)/i',
            '/menggunakan\s+(?:bank\s+)?([a-z0-9]+(?:\s+[a-z]+)?)/i',

            // LOWER PRIORITY: Amount followed by account name at END of message
            // e.g., "Beli rujak 40 ribu BSI POPY" -> captures "BSI POPY"
            // Skips prepositions like "di", "ke", "dari" before account name
            '/(?:\d+(?:[.,]\d+)?\s*(?:rb|ribu|rebu|k|jt|juta))\s+(?:di\s+|ke\s+|dari\s+)?([a-z0-9]+(?:\s+[a-z]+)?)\s*$/i',
        ];

        $knownAccounts = ['bca', 'mandiri', 'bni', 'bri', 'cash', 'tunai', 'gopay', 'ovo', 'dana', 'linkaja', 'seabank', 'jago', 'neo', 'bsi', 'jenius', 'rekening'];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $potentialAccount = trim($matches[1]);

                // Validate it's a known bank/wallet name or reasonable length
                // Skip common prepositions/words that might be matched if pattern is loose
                $nonAccountWords = ['akun', 'rekening', 'dompet', 'wallet', 'bank', 'awal', 'akhir', 'sisa', 'total', 'semua', 'berapa', 'ini', 'itu', 'nya', 'saya', 'aku', 'gue', 'gw', 'gaji', 'bonus', 'thr', 'lembur', 'honor', 'upah', 'penjualan', 'omset', 'di', 'ke', 'dari', 'untuk', 'pakai', 'pake', 'pakek', 'menggunakan', 'lewat'];
                
                // For multi-word account names, check if first word is in nonAccountWords
                $firstWord = strtolower(explode(' ', $potentialAccount)[0]);
                if (in_array($firstWord, $nonAccountWords)) {
                    continue; // Skip this match, try next pattern
                }

                // Accept if: known account, or starts with known bank + additional identifier (e.g., "BSI POPY")
                $isKnownAccount = in_array(strtolower($potentialAccount), $knownAccounts);
                $startsWithKnownBank = false;
                foreach ($knownAccounts as $knownBank) {
                    if (stripos($potentialAccount, $knownBank) === 0) {
                        $startsWithKnownBank = true;
                        break;
                    }
                }
                
                // Extended length for multi-word accounts (e.g., "BSI POPY" = 8 chars)
                $hasReasonableLength = mb_strlen($potentialAccount) >= 2 && mb_strlen($potentialAccount) <= 25;
                
                if (($isKnownAccount || $startsWithKnownBank || $hasReasonableLength) && ! is_numeric($potentialAccount)) {
                    Log::info('Account name extracted from message using regex (Laravel fallback)', [
                        'message_text' => mb_substr($messageText, 0, 100),
                        'account_name' => $potentialAccount,
                        'pattern' => $pattern,
                    ]);

                    return $potentialAccount;
                }
            }
        }

        // Fallback: check for known bank/wallet names directly
        // This handles "ke BCA", "dari Gopay" correctly because we check against specific known names
        foreach ($knownAccounts as $acc) {
            // Check if known account name appears after account keywords
            if (preg_match('/(?:pakai\s+(?:saldo|dompet|wallet)|pake\s+(?:saldo|dompet|wallet)|dari|via|ke|saldo)\s+(?:bank\s+)?\b'.$acc.'\b/i', $messageText)) {
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

        // Tanggal + nama bulan: "5 oktober", "tgl 5 okt 2026" (sebelum "tgl 5" agar bulan ikut terbaca)
        $monthMap = [
            'januari' => 1, 'jan' => 1, 'februari' => 2, 'feb' => 2, 'maret' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4, 'mei' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7,
            'agustus' => 8, 'agu' => 8, 'agt' => 8, 'ags' => 8, 'aug' => 8, 'september' => 9, 'sept' => 9, 'sep' => 9,
            'oktober' => 10, 'okt' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'desember' => 12, 'des' => 12, 'dec' => 12,
        ];
        if (preg_match('/(?<![\d.,])(\d{1,2})\s+('.implode('|', array_keys($monthMap)).')\b(?:\s+((?:19|20)\d{2}))?/u', $textLower, $m)) {
            $day = (int) $m[1];
            $month = $monthMap[$m[2]];
            $year = isset($m[3]) ? (int) $m[3] : now()->year;
            if ($day >= 1 && checkdate($month, $day, $year)) {
                $date = \Carbon\Carbon::create($year, $month, $day);
                // Tanpa tahun & jatuh di masa depan (> 7 hari) → tahun lalu ("28 desember" dikirim Januari)
                if (! isset($m[3]) && $date->isAfter(now()->addDays(7))) {
                    $date = $date->subYear();
                }

                return $date->toDateString();
            }
        }

        // Malam: "tadi malam" dikirim pagi/siang = semalam (kemarin); dikirim malam = hari ini
        if (preg_match('/\btadi\s+malam\b/u', $textLower)) {
            return now()->hour >= 18 ? now()->toDateString() : now()->subDay()->toDateString();
        }
        if (preg_match('/\b(?:semalem|semalam|kemarin\s+malam|kmrn\s+malam)\b/u', $textLower)) {
            return now()->hour < 6 ? now()->toDateString() : now()->subDay()->toDateString();
        }

        // "kemarin lusa" / "kemarin dulu" = 2 hari lalu (sebelum "kemarin")
        if (preg_match('/\b(?:kemarin|kmrn|kmrin)\s+(?:lusa|dulu)\b/u', $textLower)) {
            return now()->subDays(2)->toDateString();
        }

        // Today keywords (explicitly mentioning today)
        if (preg_match('/\b(?:tadi|barusan|baru\s+aja|baru\s+saja|hari\s+ini|hr\s+ini)\b/u', $textLower)) {
            return now()->toDateString();
        }

        if (preg_match('/\b(?:kemarin|yesterday|kmrn|kmrin|kemaren)\b/u', $textLower)) {
            return now()->subDay()->toDateString();
        }

        // "lusa" = 2 hari lagi; "besok" = 1 hari lagi
        if (preg_match('/\blusa\b/u', $textLower)) {
            return now()->addDays(2)->toDateString();
        }

        // "3 hari lalu", "2 minggu yang lalu", "1 bulan lalu"
        if (preg_match('/(\d+)\s*(hari|minggu|pekan|bulan)\s*(?:yang\s*|yg\s*)?lalu/u', $textLower, $m)) {
            $n = (int) $m[1];

            $date = match ($m[2]) {
                'hari' => now()->subDays($n),
                'minggu', 'pekan' => now()->subWeeks($n),
                'bulan' => now()->subMonthsNoOverflow($n),
            };

            return $date->toDateString();
        }

        if (preg_match('/\b(?:minggu|pekan)\s+(?:lalu|kemarin)\b/u', $textLower)) {
            return now()->subWeek()->toDateString();
        }

        if (preg_match('/\bbulan\s+(?:lalu|kemarin)\b/u', $textLower)) {
            return now()->subMonthNoOverflow()->toDateString();
        }

        // Specific date patterns
        // "tgl 15", "tanggal 15"
        if (preg_match('/(?:tgl|tanggal)\s*(\d{1,2})(?!\d)/i', $textLower, $matches)) {
            $day = (int) $matches[1];
            $now = now();
            // Validate day is within the valid range for the current month
            if ($day < 1 || $day > $now->daysInMonth) {
                return null;
            }
            $date = $now->setDay($day);
            // If the day is in the future, assume last month
            if ($date->isFuture()) {
                $date = $date->subMonth();
            }

            return $date->toDateString();
        }

        // "tgl 15/12", "tanggal 15/12/2025", or "date 15-12" format
        // Requires a date keyword prefix to avoid false positives from dates embedded
        // in descriptions (e.g., flight dates like "KNO-PEN 22/09/2026", order numbers)
        if (preg_match('/(?:tgl|tanggal|date)\s*[,:]*\s*(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?/i', $textLower, $matches)) {
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

        return null; // Return null; caller applies current date as fallback
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
        // Normalisasi slang sebelum deteksi untuk menangani bahasa tidak baku
        $messageText = \App\Services\KeywordNormalizer::normalize($messageText);
        $textLower = strtolower($messageText);

        // Extract amount using extractAmountFromText (supports batch transactions)
        $amount = $this->extractAmountFromText($messageText);

        // If no amount found, cannot process
        if (! $amount || $amount <= 0) {
            return null;
        }

        // PRIORITY: empat aliran hutang/piutang (frasa jelas) — sebelum inferensi income/expense umum
        $hutangPiutangQuick = $this->detectHutangPiutangLocalExtraction($textLower, $messageText);
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

        // AMBIGUOUS PATTERN CHECK: Detect patterns yang bisa bermakna ganda (income/expense)
        // Jika terdeteksi, kembalikan type='ambiguous' untuk meminta konfirmasi user
        $ambiguousMatch = $this->detectAmbiguousPattern($textLower);
        if ($ambiguousMatch !== null) {
            $transactionDate = $this->extractDateFromText($messageText) ?? now()->toDateString();

            return [
                'type' => 'ambiguous',
                'amount' => $amount,
                'description' => $messageText,
                'transaction_date' => $transactionDate,
                'pattern' => $ambiguousMatch['pattern'],
                'income_category_type' => $ambiguousMatch['income_category_type'],
                'expense_category_type' => $ambiguousMatch['expense_category_type'],
                'confidence_score' => 0.50,
                'source' => 'local_extraction',
                'account_name' => $this->extractAccountNameFromMessage($messageText),
            ];
        }

        // Determine transaction type (income vs expense)
        // Delegasikan ke TransactionTypeDetector — satu sumber kebenaran
        $typeDetector = app(TransactionTypeDetector::class);
        $isIncome = ($typeDetector->detect($messageText) === 'income');

        // Category is determined by CategoryInferenceService, set default here
        $categoryType = $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';

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
     * Detect if message matches an ambiguous transaction pattern from config.
     *
     * @param string $textLower Lowercase message text
     * @return array|null null if no match, otherwise matched pattern info
     */
    protected function detectAmbiguousPattern(string $textLower): ?array
    {
        $patterns = config('finwa_category_rules.ambiguous_transaction_patterns', []);

        foreach ($patterns as $entry) {
            // Skip invalid entries missing required keys
            if (! isset($entry['pattern'], $entry['income_category_type'], $entry['expense_category_type'])) {
                continue;
            }

            if (str_contains($textLower, $entry['pattern'])) {
                return [
                    'pattern' => $entry['pattern'],
                    'income_category_type' => $entry['income_category_type'],
                    'expense_category_type' => $entry['expense_category_type'],
                ];
            }
        }

        return null;
    }

    /**
     * Deteksi intent hutang/piutang dari teks (fallback lokal).
     * Dipakai ProcessIncomingMessage saat FinWa-AI salah klasifikasi,
     * mis. "Piutang Noki 20jt" → catat_pengeluaran.
     *
     * @return string|null finwa intent (catat_hutang|bayar_hutang|catat_piutang|terima_piutang)
     */
    /**
     * Arah pinjaman dari SUBJEK kalimat — sumber kesalahan paling sering:
     *   "budi pinjam 100rb"  → orang lain meminjam dari kita  → catat_piutang
     *   "pinjam budi 100rb"  → tidak jelas siapa meminjam ke siapa → 'ambiguous' (tanya user)
     *
     * @return 'catat_piutang'|'ambiguous'|null
     */
    public static function debtDirectionFromSubject(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        $notName = 'saya|aku|gue|gw|sy|ak|ku|kami|kita|mau|ingin|pengen|lagi|tadi|sudah|udah|baru|bisa|boleh|jadi|buat|untuk|minta|coba|ga|gak|nggak|tidak|ada|yang|yg|mo|harus|perlu|sempat|abis|habis';
        if (preg_match('/(?:^|[\s,])(?!(?:'.$notName.')\b)([a-z]{2,})\s+(?:pinjam|minjem|pinjem|ngutang|kasbon|ngebon)\b(?!\s+(?:ke|dari)\b)/u', $t)) {
            return 'catat_piutang';
        }
        // "pinjam motor/hp/tempat" = meminjam BARANG, bukan utang uang → jangan tanya arah
        $objects = 'motor|mobil|hp|handphone|laptop|kamera|buku|alat|tempat|gedung|baju|kostum|sepeda|tenda|ruang|ruangan|aula|lapangan|proyektor|sound|speaker|kursi|meja|truk|pickup|kendaraan|barang|charger|powerbank|gitar|helm|jas|kebaya|mic|lcd';
        if (preg_match('/^(?:pinjam|minjem|pinjem)\s+(?!(?:ke|dari|uang|duit|dana|di|dulu|sama|online|pinjol|bank|koperasi|'.$objects.')\b)[a-z]{2,}/u', $t)) {
            return 'ambiguous';
        }

        return null;
    }

    public function detectDebtIntent(string $messageText): ?string
    {
        $t = mb_strtolower($messageText);

        // "X bayar hutang" (pihak lain bayar ke kita) = terima piutang, bukan bayar hutang.
        if (CounterpartyExtractor::extractDebtPayer($messageText) !== null) {
            return 'terima_piutang';
        }

        // "budi pinjam 100rb" → piutang (arah dari subjek). Ambigu ditangani penanya di PIM.
        if (self::debtDirectionFromSubject($messageText) === 'catat_piutang') {
            return 'catat_piutang';
        }

        // 1. bayar hutang (uang keluar, lunasi/angsur hutang)
        foreach (['bayar hutang', 'bayar utang', 'pelunasan hutang', 'pelunasan utang',
            'lunas hutang', 'lunas utang', 'balikin pinjaman', 'balikin hutang',
            'angsuran hutang', 'angsur hutang'] as $p) {
            if (str_contains($t, $p)) {
                return 'bayar_hutang';
            }
        }

        // 2. terima piutang (uang masuk, piutang lunas)
        foreach (['terima piutang', 'pelunasan piutang', 'piutang lunas', 'piutang dibayar',
            'dibayar piutang', 'terima pelunasan'] as $p) {
            if (str_contains($t, $p)) {
                return 'terima_piutang';
            }
        }

        // 3. catat piutang (uang keluar, kasih pinjam) — cek lend lebih dulu agar
        //    "kasih pinjam uang ..." tidak salah masuk ke catat_hutang.
        foreach (['kasih pinjam', 'kasih pinjaman', 'pinjamkan', 'pijemin', 'minjemin',
            'piutang ke', 'piutang'] as $p) {
            if (str_contains($t, $p)) {
                return 'catat_piutang';
            }
        }

        // 4. catat hutang (uang masuk, pinjam) — termasuk "pinjam uang"/"pinjam ke".
        foreach (['dapat pinjaman', 'terima pinjaman', 'pinjaman dari', 'pinjam dari',
            'pinjam uang dari', 'pinjam uang ke', 'pinjam uang', 'pinjam ke', 'hutang dari',
            'utang dari', 'dipinjemin', 'minjem dari', 'hutang', 'utang'] as $p) {
            if (str_contains($t, $p)) {
                return 'catat_hutang';
            }
        }

        return null;
    }

    /**
     * Koreksi arah "X bayar hutang": pihak lain yang bayar ke kita = terima piutang.
     * Return 'terima_piutang' bila terdeteksi, null bila bukan pola itu.
     */
    public function detectDebtPayDirection(string $messageText): ?string
    {
        return CounterpartyExtractor::extractDebtPayer($messageText) !== null ? 'terima_piutang' : null;
    }

    /**
     * Deteksi empat aliran hutang/piutang dari teks (ekstraksi lokal).
     * Urutan: bayar hutang → terima piutang → keluar piutang → terima hutang.
     *
     * @return array{type: 'income'|'expense', category_type: string}|null
     */
    private function detectHutangPiutangLocalExtraction(string $t, ?string $originalText = null): ?array
    {
        // "X bayar hutang" (pihak lain bayar ke kita) = terima piutang.
        if ($originalText !== null && CounterpartyExtractor::extractDebtPayer($originalText) !== null) {
            return [
                'type' => 'income',
                'category_type' => 'pendapatan_terima_piutang',
            ];
        }

        // "budi pinjam 100rb" = kita meminjamkan ke Budi
        if (self::debtDirectionFromSubject($originalText ?? $t) === 'catat_piutang') {
            return [
                'type' => 'expense',
                'category_type' => 'pengeluaran_piutang',
            ];
        }

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
                'phrases' => ['kasih pinjam', 'kasih pinjaman', 'pinjamkan', 'pijemin ke', 'piutang ke'],
            ],
            [
                'type' => 'income',
                'category_type' => 'pendapatan_hutang',
                'phrases' => ['dapat pinjaman', 'terima pinjaman', 'pinjaman dari', 'pinjam dari', 'pinjam uang dari', 'pinjam uang ke', 'pinjam uang', 'pinjam ke', 'hutang dari', 'dipinjemin'],
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

        // "dapat piutang dari Rina 800rb" = piutang dilunasi (uang masuk), bukan piutang baru
        if (preg_match('/\b(?:dapat|dapet|nerima)\s+piutang\b/u', $t)) {
            return ['type' => 'income', 'category_type' => 'pendapatan_terima_piutang'];
        }

        // Kata "hutang/utang/piutang" berdiri sendiri: "hutang SpayLater 2jt",
        // "punya hutang ke budi 2jt", "piutang lama noki 5jt" → pencatatan baru.
        // Pelunasan/cicilan/bunga dikecualikan (bukan hutang baru). Apakah saldo
        // ikut berubah diputuskan DebtCashMode saat transaksi dibuat.
        if (preg_match('/\b(hutang|utang|piutang)\b/u', $t, $m)
            && ! preg_match('/\b(?:bayar\w*|membayar|cicil\w*|angsur\w*|lunas\w*|melunasi|pelunasan|bunga|denda|dibayar)\b/u', $t)) {
            return $m[1] === 'piutang'
                ? ['type' => 'expense', 'category_type' => 'pengeluaran_piutang']
                : ['type' => 'income', 'category_type' => 'pendapatan_hutang'];
        }

        return null;
    }
}
