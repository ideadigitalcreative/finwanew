<?php

namespace App\Services\OCR;

use Illuminate\Support\Facades\Log;

/**
 * ReceiptParserService - Handles extraction of data from OCR text
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic.
 */
class ReceiptParserService
{
    /**
     * Optimize OCR text for AI processing
     * Reduce long OCR text (e.g., 57 lines) to ~15-20 important lines only
     * This prevents Ollama from using too much RAM on VPS with limited memory
     *
     * MOVED FROM: ProcessIncomingMessage::optimizeOcrTextForAI()
     * LINES: 6443-6523
     */
    public function optimizeOcrTextForAI(string $ocrText): string
    {
        $lines = explode("\n", $ocrText);
        $totalLines = count($lines);
        $importantLines = [];

        Log::info('Optimizing OCR text for AI', [
            'original_line_count' => $totalLines,
        ]);

        // 1. Always keep the first 5 lines (Header/Merchant info)
        for ($i = 0; $i < min(5, $totalLines); $i++) {
            $importantLines[$i] = trim($lines[$i]);
        }

        // 2. Always keep the last 15 lines (Usually contains Total, Tax, Payment info)
        $startTail = max(0, $totalLines - 15);
        for ($i = $startTail; $i < $totalLines; $i++) {
            $importantLines[$i] = trim($lines[$i]);
        }

        // 3. Scan for important middle lines (Items with prices)
        for ($i = 5; $i < $startTail; $i++) {
            $line = trim($lines[$i]);
            $upperLine = strtoupper($line);

            if (strlen($line) < 3) {
                continue;
            }

            // PATTERNS TO KEEP:
            // A. Date/Time info
            if (preg_match('/(TANGGAL|DATE|TIME|JAM|WAKTU|202[0-9])/', $upperLine)) {
                $importantLines[$i] = $line;

                continue;
            }

            // B. Item lines (match prices)
            if (preg_match('/(\d{1,3}[\.,]\d{3})/', $line)) {
                $importantLines[$i] = $line;

                continue;
            }

            // C. Totals and Payment keywords
            if (preg_match('/(TOTAL|JUMLAH|BAYAR|KEMBALI|CASH|TUNAI|DEBIT|CREDIT|SUBTOTAL|QRIS|OVO|GOPAY)/', $upperLine)) {
                $importantLines[$i] = $line;

                continue;
            }

            // Limit total lines to avoid overloading AI (Max 60 lines for long receipts)
            if (count($importantLines) >= 60) {
                break;
            }
        }

        // Sort by key to maintain original order
        ksort($importantLines);

        $optimizedText = implode("\n", $importantLines);

        Log::info('OCR text optimized', [
            'original_lines' => $totalLines,
            'optimized_lines' => count($importantLines),
            'reduction_percent' => round((1 - count($importantLines) / $totalLines) * 100, 1),
        ]);

        return $optimizedText;
    }

    /**
     * Extract total amount from OCR text
     * Priority: TOTAL BELANJA > NON TUNAI > TUNAI > TOTAL > JUMLAH
     *
     * MOVED FROM: ProcessIncomingMessage::extractTotalFromOcrText()
     * LINES: 6530-6629
     */
    public function extractTotalFromOcrText(string $text, bool $strictMode = false): ?int
    {
        // First, clean the text - remove numbers inside parentheses (usually discounts)
        $cleanedText = preg_replace('/\([^)]*\d+[^)]*\)/', '', $text);
        $lines = explode("\n", $cleanedText);
        $totalCandidates = [];

        Log::info('Deep OCR Total Extraction started', [
            'line_count' => count($lines),
            'strict_mode' => $strictMode,
        ]);

        // 1. Kumpulkan semua kandidat yang memiliki keyword pendukung (Prioritas Tinggi)
        $priorityKeywords = [
            'TOTAL BELANJA' => 10, 'GRAND TOTAL' => 10, 'NET TOTAL' => 10,
            'TOTAL' => 8, 'SUBTOTAL' => 8, 'SUB TOTAL' => 8, 'JUMLAH' => 8,
            'TAGIHAN' => 7, 'BAYAR' => 6, 'CARD' => 6, 'QRIS' => 6,
            'CASH' => 5, 'TUNAI' => 5, 'NONTUNAI' => 5, 'NON TUNAI' => 5,
        ];

        foreach ($lines as $index => $line) {
            $upperLine = strtoupper($line);
            foreach ($priorityKeywords as $kw => $priority) {
                if (str_contains($upperLine, $kw)) {
                    $foundAmount = null;

                    // Ambil bagian teks SETELAH keyword untuk mencari nominal
                    $textAfterKw = substr($line, strpos($upperLine, $kw) + strlen($kw));

                    // A. Cari di sisa baris yang SAMA (setelah keyword)
                    // Dukung tanda '=' sebagai pemisah (OCR sering salah baca '.' sebagai '=')
                    if (preg_match_all('/(\d{1,3}(?:[.,=]\d{3}){1,2}|\d{4,9})/', $textAfterKw, $matches)) {
                        $foundAmount = $this->parseAmountSmart(end($matches[0]));
                    }
                    // B. Jika tidak ada di baris yang sama, cari di baris TEPAT DI BAWAHNYA
                    elseif (isset($lines[$index + 1]) && preg_match_all('/(\d{1,3}(?:[.,=]\d{3}){1,2}|\d{4,9})/', $lines[$index + 1], $matches)) {
                        $foundAmount = $this->parseAmountSmart(end($matches[0]));
                    }

                    if ($foundAmount && $foundAmount >= 1000 && $foundAmount < 500000000) {
                        // EXCLUSION: Jika angka diawali '899' dan sangat panjang, itu barcode (skip)
                        if (str_starts_with((string) $foundAmount, '899') && $foundAmount > 1000000) {
                            continue;
                        }

                        $totalCandidates[] = [
                            'amount' => $foundAmount,
                            'line_index' => $index,
                            'priority' => $priority,
                            'source' => $kw,
                            'line_text' => trim($line),
                        ];
                    }
                }
            }
        }

        // 2. Tambahkan semua angka berformat 'Rp' sebagai kandidat
        if (preg_match_all('/Rp\s*[:\.\/]?\s*([\d\.,=]{4,15})(?!\s*%)/i', $cleanedText, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $rawVal = $match[0];
                $val = $this->parseAmountSmart($rawVal);
                $offset = $match[1];
                $lineIndex = substr_count(substr($cleanedText, 0, $offset), "\n");
                $currentLine = $lines[$lineIndex] ?? '';

                // Jika Barcode (899...) abaikan
                if (str_starts_with((string) $val, '899') && $val > 1000000) {
                    continue;
                }

                $isItemLine = preg_match('/^\s*\d+[\.\)]/', $currentLine);
                if ($val >= 10000) {
                    $totalCandidates[] = [
                        'amount' => $val,
                        'line_index' => $lineIndex,
                        'priority' => $isItemLine ? 1 : 2,
                        'source' => 'Rp Pattern',
                        'line_text' => trim($currentLine),
                    ];
                }
            }
        }

        Log::info('Candidates found for extraction', ['count' => count($totalCandidates), 'items' => $totalCandidates]);

        // 3. LOGIKA KEPUTUSAN: Pilih yang terbaik
        if (! empty($totalCandidates)) {
            // Urutkan:
            // 1. Prioritas (Keyword lebih tinggi = lebih baik)
            // 2. Baris ke (Baris lebih bawah = lebih baik, karena Total ada di bawah rincian)
            usort($totalCandidates, function ($a, $b) {
                if ($a['priority'] !== $b['priority']) {
                    return $b['priority'] <=> $a['priority'];
                }

                return $b['line_index'] <=> $a['line_index'];
            });

            // Ambil kandidat pertama setelah diurutkan
            $best = $totalCandidates[0];

            Log::info('Deep extraction SUCCESS', [
                'amount' => $best['amount'],
                'source' => $best['source'],
                'line_index' => $best['line_index'],
                'allCount' => count($totalCandidates),
            ]);

            return $best['amount'];
        }

        if ($strictMode) {
            return null;
        }

        // 4. Fallback Terakhir: Ambil angka terbesar yang formatnya valid (XX.XXX)
        if (preg_match_all('/(\d{1,3}(?:[.,]\d{3}){1,2})/', $cleanedText, $matches)) {
            $amounts = [];
            foreach ($matches[1] as $match) {
                $val = $this->parseAmountSmart($match);
                if ($val >= 10000 && $val < 100000000) {
                    $amounts[] = $val;
                }
            }
            if (! empty($amounts)) {
                return max($amounts);
            }
        }

        return null;
    }

    /**
     * Extract store name from OCR text (simple heuristic)
     *
     * MOVED FROM: ProcessIncomingMessage::extractStoreNameFromOcrText()
     * LINES: 6634-6669
     */
    public function extractStoreNameFromOcrText(string $text): ?string
    {
        $lines = explode("\n", $text);

        // Check first 5 lines for common store names
        for ($i = 0; $i < min(5, count($lines)); $i++) {
            $line = strtoupper(trim($lines[$i]));

            if (str_contains($line, 'INDOMARET')) {
                return 'Indomaret';
            }
            if (str_contains($line, 'ALFAMART')) {
                return 'Alfamart';
            }
            if (str_contains($line, 'ALFAMIDI')) {
                return 'Alfamidi';
            }
            if (str_contains($line, 'HYPERMART')) {
                return 'Hypermart';
            }
            if (str_contains($line, 'SUPERINDO')) {
                return 'Superindo';
            }
            if (str_contains($line, 'STARBUCKS')) {
                return 'Starbucks';
            }
            if (str_contains($line, 'MCDONALD')) {
                return 'McDonalds';
            }
            if (str_contains($line, 'KFC')) {
                return 'KFC';
            }
            if (str_contains($line, 'BURGER KING')) {
                return 'Burger King';
            }
            if (str_contains($line, 'HOKBEN')) {
                return 'HokBen';
            }
            if (str_contains($line, 'GOCAR')) {
                return 'GoCar';
            }
            if (str_contains($line, 'GRAB')) {
                return 'Grab';
            }
            if (str_contains($line, 'GOJEK')) {
                return 'Gojek';
            }
            if (str_contains($line, 'TOKOPEDIA')) {
                return 'Tokopedia';
            }
            if (str_contains($line, 'SHOPEE')) {
                return 'Shopee';
            }
        }

        // If no known store found, return the first non-empty line that looks like a title
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strlen($line) <= 3) {
                continue;
            }

            // Skip lines that are ONLY numbers/symbols (dates, phone numbers, codes)
            if (preg_match('/^[\d\s\-\/\.:,+()]+$/', $line)) {
                continue;
            }

            // Skip lines that look like addresses (Jl., Jln., Jalan)
            // Including OCR misreads like J1, JI, L1, I1, J!, etc.
            if (preg_match('/^([JI1LS!]{1,2}[ln1]?\.?|Jalan|Jl)\s/i', $line)) {
                continue;
            }

            // Skip lines starting with digits that look like postal codes or sequence numbers (like 90243)
            // But only if they don't have enough alphabetic characters
            if (preg_match('/^\d{5,}/', $line) && strlen(preg_replace('/[^a-zA-Z]/', '', $line)) < 3) {
                continue;
            }

            // Skip lines that look like phone numbers (Telp, Tel, HP)
            if (preg_match('/^(Telp|Tel|HP|Fax|WA)[\s:.]/i', $line)) {
                continue;
            }

            // For lines starting with digits followed by text (e.g. "884 FROZENFOOD")
            // Keep the full name including the number (it's part of the store identity)
            $hasText = preg_match('/[a-zA-Z]{3,}/', $line);
            if ($hasText) {
                // Normalize: add space between digits and letters (OCR often merges them)
                // e.g. "884FROZENFOOD" -> "884 FROZENFOOD"
                $line = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $line);

                return ucwords(strtolower($line));
            }

            // Normal text line
            if (! preg_match('/^\d/', $line)) {
                return ucwords(strtolower($line));
            }
        }

        return null;
    }

    /**
     * Parse date from receipt text
     *
     * MOVED FROM: ProcessIncomingMessage::parseReceiptDate()
     * LINES: 6674-6694
     */
    public function parseReceiptDate(?string $dateRaw): string
    {
        if (! $dateRaw) {
            return date('Y-m-d');
        }

        try {
            // PRIORITY 1: Already in YYYY-MM-DD format (from Gemini Vision / AI)
            // Must be checked FIRST to avoid DD-MM-YY regex mangling it
            if (preg_match('/^(\d{4})[\.\/\-](\d{2})[\.\/\-](\d{2})$/', trim($dateRaw), $matches)) {
                $year = (int) $matches[1];
                $month = (int) $matches[2];
                $day = (int) $matches[3];

                // Sanity check: year should be recent (within 2 years)
                $currentYear = (int) date('Y');
                if ($year >= $currentYear - 2 && $year <= $currentYear + 1 && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                    return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
                }
            }

            // PRIORITY 2: DD.MM.YY or DD/MM/YY or DD-MM-YY format (from OCR text)
            // 18.11.25 -> 2025-11-18
            if (preg_match('/^(\d{2})[\.\/\-](\d{2})[\.\/\-](\d{2})$/', trim($dateRaw), $matches)) {
                $year = "20{$matches[3]}";
                $currentYear = (int) date('Y');
                // Sanity check year
                if ((int) $year >= $currentYear - 2 && (int) $year <= $currentYear + 1) {
                    return "{$year}-{$matches[2]}-{$matches[1]}";
                }
            }

            // PRIORITY 3: DD.MM.YYYY or DD/MM/YYYY or DD-MM-YYYY format
            // 18.11.2025 -> 2025-11-18
            if (preg_match('/^(\d{2})[\.\/\-](\d{2})[\.\/\-](\d{4})$/', trim($dateRaw), $matches)) {
                return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
            }

            return date('Y-m-d');
        } catch (\Exception $e) {
            return date('Y-m-d');
        }
    }

    /**
     * Check if a line is a payment method or non-product (e.g. QRIS, OVO, column header AMOUNT).
     * Used to avoid treating "QRIS Yokee 111215" or "AMOUNT" as product items in receipt parsing.
     */
    public function isPaymentMethodOrNonProductLine(string $text): bool
    {
        $text = strtoupper(trim($text));
        $keywords = [
            'QRIS', 'OVO', 'GOPAY', 'DANA', 'SHOPEEPAY', 'LINKAJA', 'TUNAI', 'CASH', 'KEMBALI', 'CHANGE',
            'SUBTOTAL', 'AMOUNT', 'TOTAL', 'ITEM', 'DEBIT', 'CREDIT', 'CARD', 'NOMER', 'NUMBER', 'NO.', 'TRANSAKSI',
            'PAJAK', 'TAX', 'PPN', 'SC.', 'PB1', 'SUB TOTAL', 'JUMLAH', 'BAYAR', 'METODE', 'PEMBAYARAN',
            'CUSTOMER', 'SAVE', 'KONSUMEN', 'POINT', 'POIN', 'MEMBER', 'DISC', 'DISKON', 'PROMO', 'VOUCHER',
            'TERIMA KASIH', 'THANK YOU', 'STRUK', 'RECEIPT', 'COPY', 'ASLI', 'DUPLIKAT', 'KASIR', 'CASHIER',
            'TELP', 'PHONE', 'WA ', 'WHATSAPP', 'SITUS', 'WEB', 'WWW.', 'HTTP', 'HTTPS', 'KEMBALIAN',
            'TELAH DIBAYAR', 'SUDAH DIBAYAR', 'LUNAS', 'PAID', 'BELANJA', 'ANDA SAVING', 'SAVING', 'HEMAT',
            'JL.', 'JALAN', 'RAYA', 'KM.', 'KOTA', 'PROVINSI', 'KEC.', 'KEL.', 'DESA', 'RT.', 'RW.',
            'KARTU', 'TRANSFER', 'BANK', 'BCA', 'BNI', 'BRI', 'MANDIRI', 'BUKTI', 'REF', 'SN.', 'AUTH',
        ];

        foreach ($keywords as $kw) {
            if ($text === $kw || (strlen($text) > 4 && str_contains($text, $kw))) {
                return true;
            }
        }

        // Patterns for address/phone (strict)
        if (preg_match('/\d{6,}/', $text)) {
            return true;
        } // Long numbers (phones/IDs)
        if (preg_match('/^\(?0\d{2,3}[\)\s\-]\d{5,}/', $text)) {
            return true;
        } // Landline phones

        return false;
    }

    /**
     * Parse amount string smartly handling IDR/US formats
     * 10.000 -> 10000
     * 10.000,00 -> 10000
     * 10,000.00 -> 10000
     */
    private function parseAmountSmart(string $rawAmt): int
    {
        // Remove currency symbols and spaces, allow '=' as it's often a misread for '.'
        $clean = preg_replace('/[^\d\.,=]/', '', $rawAmt);
        $clean = str_replace('=', '.', $clean); // Treat = as . (thousand/decimal separator)

        // Check for both separators
        $hasDot = strpos($clean, '.') !== false;
        $hasComma = strpos($clean, ',') !== false;

        if ($hasDot && $hasComma) {
            // Determine which is decimal separator (the right-most one)
            $lastDot = strrpos($clean, '.');
            $lastComma = strrpos($clean, ',');

            if ($lastComma > $lastDot) {
                // Format: 1.000,00 (IDR standard)
                $clean = str_replace('.', '', $clean); // Remove thousands
                $clean = str_replace(',', '.', $clean); // Convert decimal
            } else {
                // Format: 1,000.00 (US standard)
                $clean = str_replace(',', '', $clean);
            }
        } elseif ($hasComma) {
            // Only comma. Ambiguous: 100,00 (decimal) or 100,000 (thousands - or OCR error reading dot as comma)?
            // Heuristic: If followed by exactly 3 digits at the end, treat as THOUSANDS.
            // Example: 137,900 -> 137900
            // Example: 50,00 -> 50.00 (decimal)
            if (preg_match('/,\d{3}$/', $clean)) {
                $clean = str_replace(',', '', $clean);
            } else {
                $clean = str_replace(',', '.', $clean);
            }
        } elseif ($hasDot) {
            // Check if last segment after final dot has exactly 2 digits (decimal)
            // vs 3 digits (thousands separator in IDR)
            $lastDotPos = strrpos($clean, '.');
            $afterLastDot = substr($clean, $lastDotPos + 1);

            if (strlen($afterLastDot) === 2) {
                // Format: 68000.00 or 68.000.00 — last part is decimal
                // Remove decimal part first, then remove remaining dots (thousands)
                $clean = substr($clean, 0, $lastDotPos);
                $clean = str_replace('.', '', $clean);
            } else {
                // Only dot as thousands: 10.000 -> 10000, 137.900 -> 137900
                $clean = str_replace('.', '', $clean);
            }
        }

        return (int) floatval($clean);
    }
}
