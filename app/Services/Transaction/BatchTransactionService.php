<?php

namespace App\Services\Transaction;

use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * BatchTransactionService - Handles batch transaction processing
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 *
 * NOTE: This service depends on sendReply, extractAmountFromText,
 * extractDescriptionFromLine, determineCategoryFromText, and createTransaction
 * methods which are provided via callbacks.
 */
class BatchTransactionService
{
    protected Message $message;

    protected $sendReplyCallback;

    protected $extractAmountCallback;

    protected $extractDescriptionCallback;

    protected $determineCategoryCallback;

    protected $createTransactionCallback;

    /**
     * Constructor
     */
    public function __construct(
        Message $message,
        callable $sendReplyCallback,
        ?callable $extractAmountCallback = null,
        ?callable $extractDescriptionCallback = null,
        ?callable $determineCategoryCallback = null,
        ?callable $createTransactionCallback = null
    ) {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
        $this->extractAmountCallback = $extractAmountCallback;
        $this->extractDescriptionCallback = $extractDescriptionCallback;
        $this->determineCategoryCallback = $determineCategoryCallback;
        $this->createTransactionCallback = $createTransactionCallback;
    }

    protected function sendReply(string $message): void
    {
        call_user_func($this->sendReplyCallback, $message);
    }

    protected function extractAmountFromText(string $text): ?int
    {
        if ($this->extractAmountCallback) {
            return call_user_func($this->extractAmountCallback, $text);
        }

        return null;
    }

    protected function extractDescriptionFromLine(string $line): string
    {
        if ($this->extractDescriptionCallback) {
            return call_user_func($this->extractDescriptionCallback, $line);
        }

        return $line;
    }

    protected function determineCategoryFromText(string $description, bool $isIncome): string
    {
        if ($this->determineCategoryCallback) {
            return call_user_func($this->determineCategoryCallback, $description, $isIncome);
        }

        return $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
    }

    protected function createTransaction(array $txData, bool $sendConfirmation = true)
    {
        if ($this->createTransactionCallback) {
            return call_user_func($this->createTransactionCallback, $txData, $sendConfirmation);
        }

        return null;
    }

    /**
     * Check if the message contains batch transactions in list format
     * Formats supported:
     * - "Biaya tanggal 10 Desember 2025\n1. Makan malam 17.000\n2. Grab 13.500"
     * - "Pengeluaran hari ini:\n- Makan 25rb\n- Transport 15rb"
     * - "Belanja:\n• Item 1 10.000\n• Item 2 20.000"
     * - "Uang masuk atas nama\nAgung 116k\nAngga 525k"
     * - NEW: "beli makan siang 38.000\nbeli kantong asi 50.000\nsedekah ahmad 70.000" (simple action format)
     *
     * MOVED FROM: ProcessIncomingMessage::isBatchTransactionFormat()
     * LINES: 6696-6833
     * MODIFICATION: None (structural move only)
     */
    public function isBatchTransactionFormat(string $messageText): bool
    {
        // Must have multiple lines
        $lines = preg_split('/[\r\n]+/', trim($messageText));
        if (count($lines) < 2) {
            // Check for single line batch (multiple amounts in one line)
            // Satu transaksi dengan 2 angka: kembalian / harusnya / potong / "3jt 400rb"
            if (\App\Support\AmountParser::composite($messageText) !== null) {
                return false;
            }
            $amounts = \App\Helpers\BatchTransactionHelper::extractAllAmounts($messageText);
            if (count($amounts) < 2) {
                return false;
            }

            // Setiap potongan sesudah yang pertama harus punya keterangan sendiri
            // ("bensin 20rb parkir 5rb" ✓, "makan 25rb, 5rb" ✗)
            foreach (array_slice($this->splitSingleBatchLine(\App\Support\AmountParser::normalize($messageText)), 1) as $chunk) {
                $words = trim((string) preg_replace('/\d+(?:[.,]\d+)*\s*(?:rb|ribu|rebu|k|jt|juta)?\b|\brp\.?|[^\p{L}\s]/iu', ' ', $chunk));
                $words = trim((string) preg_replace('/^(?:dan|sama|plus|terus|lalu|juga|kemudian|trus|sm)\b/iu', '', $words));
                if (mb_strlen($words) < 3) {
                    return false;
                }
            }

            return true;
        }

        // ── FAST DETECT: "Pemasukan AMOUNT\nPengeluaran AMOUNT" (2-line in/out format)
        // e.g., "Pemasukan 21.851\nPengeluaran 6.500"
        $fastIncomeExpensePattern = '/^(?:pemasukan|pendapatan)\s+[\d., ]+/i';
        $fastExpenseIncomePattern = '/^(?:pengeluaran|belanja|bayar)\s+[\d., ]+/i';
        $hasFastIncome = false;
        $hasFastExpense = false;
        foreach ($lines as $l) {
            $lt = self::stripDates(trim($l));
            if (preg_match($fastIncomeExpensePattern, $lt)) {
                $hasFastIncome = true;
            }
            if (preg_match($fastExpenseIncomePattern, $lt)) {
                $hasFastExpense = true;
            }
        }
        // If we have BOTH income line AND expense line → definitely batch
        if ($hasFastIncome && $hasFastExpense) {
            return true;
        }
        // If ALL lines match either income or expense pattern (at least 2 lines) → batch
        $allFastMatch = 0;
        foreach ($lines as $l) {
            $lt = self::stripDates(trim($l));
            if (empty($lt)) {
                continue;
            }
            if (preg_match($fastIncomeExpensePattern, $lt) || preg_match($fastExpenseIncomePattern, $lt)) {
                $allFastMatch++;
            }
        }
        if ($allFastMatch >= 2 && $allFastMatch === count(array_filter($lines, fn ($l) => ! empty(trim($l))))) {
            return true;
        }

        // Action keywords — read from config (single source of truth)
        $expenseKeywords = config('finwa_category_rules.batch_expense_action_keywords', []);
        $incomeKeywords = config('finwa_category_rules.batch_income_action_keywords', []);
        $actionKeywords = array_merge($expenseKeywords, $incomeKeywords);

        // Check for header patterns (date or category indicator)
        $firstLine = strtolower(trim($lines[0]));
        $headerPatterns = [
            '/biaya\s+(tanggal|tgl)/', // "Biaya tanggal..."
            '/pengeluaran\s+(tanggal|tgl|hari|bulan)/', // "Pengeluaran tanggal..."
            '/pemasukan\s+(tanggal|tgl|hari|bulan)/', // "Pemasukan tanggal..."
            '/belanja\s*(tanggal|tgl)?:/', // "Belanja:" (with colon - header style)
            '/list\s*(transaksi|biaya|pengeluaran|pemasukan)?/', // "List transaksi..."
            '/transaksi\s+(tanggal|tgl|hari)/', // "Transaksi tanggal..."
            '/\d{1,2}\s*(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)/', // Contains date
            // "Uang masuk/keluar atas nama" patterns
            '/uang\s+(masuk|keluar)/', // "Uang masuk..." or "Uang keluar..."
            '/transfer\s+(masuk|dari|ke)/', // "Transfer masuk dari..."
            '/terima\s+(dari|uang)/', // "Terima dari..."
            '/setoran\s+(dari)?/', // "Setoran dari..."
            '/pembayaran\s+(dari)?/', // "Pembayaran dari..."
            '/iuran\s+(dari)?/', // "Iuran dari..."
        ];

        $hasHeaderPattern = false;
        foreach ($headerPatterns as $pattern) {
            if (preg_match($pattern, $firstLine)) {
                $hasHeaderPattern = true;
                break;
            }
        }

        // Count items with amounts - now check ALL lines (including first line for simple format)
        $itemCount = 0;
        $simpleFormatCount = 0; // Count lines matching simple "action desc amount" format

        // Patterns for numbered/bulleted items
        $numberedPatterns = [
            '/^\d+[\.)\\-]\s*.+\s+\d/', // "1. Item 17.000" or "1) Item 17rb" or "1- Item 17000"
            '/^[\-\•\*]\s*.+\s+\d/', // "- Item 17.000" or "• Item 17rb"
        ];

        // Patterns for simple "Name Amount" or "Action Desc Amount" format
        $simplePatterns = [
            '/^[A-Za-z][A-Za-z\s]*\s+\d+\s*(rb|ribu|k|jt|juta)?$/i', // "Agung 116k" or "Budi 500rb"
            '/^[A-Za-z][A-Za-z\s]*\s+\d+[\.,]?\d*$/i', // "Agung 116000" or "Budi 500.000"
        ];

        // Pattern for action + description + amount (e.g., "beli makan siang 38.000")
        // Matches: "beli X 38.000", "bayar Y 50rb", "sedekah Z 70.000"
        // Amount formats: 38.000, 38000, 38rb, 38ribu, 38k, 38jt, 1.500.000
        $actionAmountPattern = '/^([a-zA-Z]+)\s+.+\s+(\d[\d\., ]*)\s*(rb|ribu|k|jt|juta)?$/i';

        // Pattern for amount-first format (e.g., "20rb maxim dari JGC ke Callia", "10rb Talang drop j&t")
        // Amount formats: 20rb, 10rb, 50k, 1.5jt, 15000, 15.000
        $amountFirstPattern = '/^\d[\d\., ]*\s*(rb|ribu|k|jt|juta)?\s+.+/i';

        // Pattern for receipt/struk format: "PRODUCT NAME (keterangan) Rp 24.000"
        // Simple check: line ends with "Rp" followed by a number
        $receiptLinePattern = '/\bRp\.?\s*[\d\.,]+\s*$/i';

        // Check all lines for transaction patterns
        foreach ($lines as $index => $rawLine) {
            $line = trim($rawLine);
            if (empty($line)) {
                continue;
            }
            $lineLower = strtolower($line);

            // PRIORITY 1: Check action + description + amount pattern (ALL lines including first)
            // e.g., "beli makan siang 38.000", "sedekah ahmad 70.000"
            // This must be checked FIRST because it's more specific (requires action keyword)
            if (preg_match($actionAmountPattern, $line, $matches)) {
                $firstWord = strtolower($matches[1]);
                // Verify first word is an action keyword
                if (in_array($firstWord, $actionKeywords)) {
                    $simpleFormatCount++;

                    continue; // This line is counted, move to next
                }
            }

            // PRIORITY 1b: Check amount-first format (e.g., "20rb maxim dari JGC ke Callia")
            if (preg_match($amountFirstPattern, $line)) {
                $simpleFormatCount++;

                continue;
            }

            // PRIORITY 1c: Check receipt/struk format (e.g., "DETTOL BW ORIGINAL 370M (Sabun mandi) Rp 24.000")
            if (preg_match($receiptLinePattern, $line)) {
                $simpleFormatCount++;

                continue;
            }

            // PRIORITY 2: Check numbered/bulleted patterns (skip first line as it could be header)
            if ($index > 0) {
                $foundPattern = false;

                foreach ($numberedPatterns as $pattern) {
                    if (preg_match($pattern, $line)) {
                        $itemCount++;
                        $foundPattern = true;
                        break;
                    }
                }

                if (! $foundPattern) {
                    // PRIORITY 3: Check simple name+amount patterns (e.g., "Agung 116k")
                    foreach ($simplePatterns as $pattern) {
                        if (preg_match($pattern, $line)) {
                            $itemCount++;
                            break;
                        }
                    }
                }
            }
        }

        Log::debug('Batch transaction format check', [
            'line_count' => count($lines),
            'has_header' => $hasHeaderPattern,
            'numbered_item_count' => $itemCount,
            'simple_format_count' => $simpleFormatCount,
            'first_line' => mb_substr($lines[0], 0, 50),
        ]);

        // Consider it a batch if:
        // 1. Has header pattern AND at least 2 numbered/bulleted items, OR
        // 2. Has 3 or more numbered/bulleted items (even without header), OR
        // 3. NEW: Has 2 or more lines matching "action desc amount" format
        // 4. COMBINED: total transaction-like lines (itemCount + simpleFormatCount) >= 2
        $totalTransactionLines = $itemCount + $simpleFormatCount;

        return ($hasHeaderPattern && $totalTransactionLines >= 2) || $totalTransactionLines >= 2 || $simpleFormatCount >= 2;
    }

    /**
     * Handle batch transactions from list format
     *
     * MOVED FROM: ProcessIncomingMessage::handleBatchTransactions()
     * LINES: 6835-7052
     * MODIFICATION: None (structural move only)
     */
    public function handleBatchTransactions(string $messageText): void
    {
        Log::info('Processing batch transactions', [
            'message_id' => $this->message->id,
            'text_preview' => mb_substr($messageText, 0, 200),
        ]);

        $lines = preg_split('/[\r\n]+/', trim($messageText));

        // DETECT WALLET INSTRUCTION: Check if any line contains wallet/account instruction
        // Patterns: "kurangi dari saldo BRI", "dari dompet X", "pakai saldo Y", "dari rekening Z"
        $walletName = null;
        $walletInstructionPatterns = [
            '/(?:kurangi|kurang|ambil|keluar)\\s+(?:dari\\s+)?(?:saldo|dompet|rekening|wallet|akun)\\s+([a-zA-Z0-9\\s]+)/i',
            '/(?:dari|pakai|pake|via|lewat|gunakan)\\s+(?:saldo|dompet|rekening|wallet|akun)?\\s*([a-zA-Z0-9]+)/i',
        ];

        foreach ($lines as $line) {
            $lineLower = strtolower(trim($line));

            // Skip lines that look like transactions (have amounts)
            if (preg_match('/\\d+\\s*(rb|ribu|k|jt|juta|\\.|,)/i', $line)) {
                continue;
            }

            foreach ($walletInstructionPatterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $walletName = trim($matches[1]);
                    Log::info('Wallet instruction detected in batch', [
                        'wallet_name' => $walletName,
                        'instruction_line' => $line,
                    ]);
                    break 2; // Exit both loops
                }
            }
        }

        // Handle Single Line Batch (Split horizontally)
        // "Pemasukan tanggal 28 September 360rb, 5 Oktober 500rb, 12 Oktober 440rb"
        $isSingleLineSplit = false;
        $singleLineIsIncome = null; // jenis dari kalimat pembuka, diwariskan ke potongan berikutnya
        if (count($lines) < 2) {
            $amounts = \App\Helpers\BatchTransactionHelper::extractAllAmounts($messageText);
            if (count($amounts) >= 2) {
                $lines = $this->splitSingleBatchLine($messageText);
                $isSingleLineSplit = count($lines) >= 2;
                if (preg_match('/\b(pemasukan|pendapatan|terima|diterima|dapat|dapet|gaji|bonus|honor|uang\s+masuk|transfer\s+masuk)\b/iu', $messageText)) {
                    $singleLineIsIncome = true;
                } elseif (preg_match('/\b(pengeluaran|belanja|bayar|beli|biaya|uang\s+keluar)\b/iu', $messageText)) {
                    $singleLineIsIncome = false;
                }
                Log::info('Single line batch split', ['count' => count($lines)]);
            }
        }

        $headerLine = trim($lines[0] ?? '');
        $headerLower = strtolower($headerLine);

        // Action keywords — read from config (single source of truth)
        $expenseActionKeywords = config('finwa_category_rules.batch_expense_action_keywords', []);
        $incomeActionKeywords = config('finwa_category_rules.batch_income_action_keywords', []);
        $allActionKeywords = array_merge($expenseActionKeywords, $incomeActionKeywords);

        // Check if first line is a transaction in ONE of these formats:
        // 1. "action desc amount" (e.g., "beli makan siang 38.000")
        // 2. SIMPLE "Pemasukan/Pengeluaran amount" (e.g., "Pemasukan 21.851")
        // 3. Amount-first (e.g., "20rb maxim dari JGC ke Callia")
        $firstLineIsTransaction = false;
        $actionAmountPattern = '/^([a-zA-Z]+)\s+.+\s+(\d+(?:[\., ]\d+)?)\s*(rb|ribu|k|jt|juta)?$/i';
        $simpleTypeAmountPattern = '/^(pemasukan|pengeluaran|pendapatan|belanja|bayar)\s+[\d., ]+/i';
        $amountFirstPatternFirstLine = '/^\d[\d., ]*\s*(rb|ribu|k|jt|juta)?\s+.+/i';
        $simpleNameAmountPattern = '/^[A-Za-z][A-Za-z\s]*\s+\d+\s*(rb|ribu|k|jt|juta)?$/i';
        $simpleNamePlainAmountPattern = '/^[A-Za-z][A-Za-z\s]*\s+\d[\d\.,]*$/i';
        // Receipt/struk format: "PRODUCT NAME (keterangan) Rp 24.000" or "FITTI PANTS XL 40 Rp 133.000"
        // Simple check: line contains "Rp" followed by a number (amount at end)
        $receiptLinePatternFirstLine = '/\bRp\.?\s*[\d\.,]+\s*$/i';

        // Tanggal di judul BUKAN nominal: "Pengeluaran 22 Oktober 2026" dulu terbaca
        // transaksi ("22"/"2026" dianggap nominal) → tanggal judul diabaikan.
        $headerForMatch = self::stripDates($headerLine);

        if (preg_match($simpleTypeAmountPattern, $headerForMatch)) {
            // "Pemasukan 21.851" or "Pengeluaran 6.500" as first line → it IS a transaction
            $firstLineIsTransaction = true;
        } elseif (preg_match($receiptLinePatternFirstLine, $headerForMatch)) {
            // Receipt/struk format: "DETTOL BW ORIGINAL 370M (Sabun mandi) Rp 24.000"
            // IMPORTANT: Must be checked BEFORE actionAmountPattern because that pattern is too
            // broad and matches lines like "FITTI PANTS XL 40 Rp 133.000" where "FITTI" is not
            // an action keyword, causing the elseif chain to skip this receipt check entirely.
            $firstLineIsTransaction = true;
        } elseif (preg_match($actionAmountPattern, $headerForMatch, $matches)
            && in_array(strtolower($matches[1]), $allActionKeywords)) {
            $firstLineIsTransaction = true;
        } elseif (preg_match($amountFirstPatternFirstLine, $headerForMatch)) {
            // Amount-first format: "20rb maxim dari JGC ke Callia"
            $firstLineIsTransaction = true;
        } elseif (preg_match($simpleNameAmountPattern, $headerForMatch) || preg_match($simpleNamePlainAmountPattern, $headerForMatch)) {
            // Simple "Description Amount" format: "Sayur 45 k" or "Sayur 45000"
            $firstLineIsTransaction = true;
        } elseif ($isSingleLineSplit || self::lineHasTrailingAmount($headerForMatch)) {
            // Baris pertama yang DIAKHIRI nominal adalah transaksi, bukan judul:
            // "amplop untuk 2x undangan 300rb" (dulu dibuang karena "amplop" bukan
            // kata kerja & "2x" mengandung angka). Potongan kalimat satu baris
            // juga selalu transaksi (setiap potongan berakhir di nominal).
            $firstLineIsTransaction = true;
        }

        // Parse date from header (only if first line is NOT a transaction)
        $transactionDate = $firstLineIsTransaction ? now()->toDateString() : $this->parseDateFromHeader($headerLine);

        // Determine default type from header (only if first line is NOT a transaction)
        $hasHeaderType = false;
        $isIncomeDefault = false;
        if (! $firstLineIsTransaction) {
            $isIncomeDefault = (bool) preg_match('/pemasukan|pendapatan|terima|gaji|bonus|uang\s+masuk|transfer\s+masuk|setoran/i', $headerLower);
            $isExpenseDefault = (bool) preg_match('/pengeluaran|belanja|biaya|bayar|uang\s+keluar|transfer\s+keluar/i', $headerLower);

            // Default to income if "uang masuk" type header
            if (! $isIncomeDefault && ! $isExpenseDefault) {
                $isIncomeDefault = (bool) preg_match('/masuk|terima|dari/i', $headerLower);
            }
            $hasHeaderType = $isIncomeDefault || $isExpenseDefault;
        }

        // Determine start index for processing lines
        $startIndex = $firstLineIsTransaction ? 0 : 1;

        // Parse each item line
        $transactions = [];
        $totalIncomeAmount = 0;
        $totalExpenseAmount = 0;

        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }

            // Remove bullet/number prefix
            $cleanLine = preg_replace('/^[\d]+[\.)\\-]\s*/', '', $line); // Remove "1. " or "1) " or "1- "
            $cleanLine = preg_replace('/^[\-\•\*]\s*/', '', $cleanLine); // Remove "- " or "• " or "* "
            $cleanLine = trim($cleanLine);

            // Potongan kalimat satu baris diawali penghubung: ", 5 Oktober 500rb"
            $cleanLine = trim((string) preg_replace('/^[\s,;:.\-–—&]+|^(?:dan|sama|serta|lalu|terus)\s+/iu', '', $cleanLine));

            // Tanggal per item ("28 September", "5 Oktober", "tgl 12") → tanggal
            // transaksi itu sendiri; dihapus dari teks SEBELUM baca nominal supaya
            // angka tanggal tidak terbaca sebagai nominal / masuk ke keterangan.
            $lineDate = $this->extractInlineDate($cleanLine);

            if (empty($cleanLine)) {
                continue;
            }

            // Extract amount from the line
            $amount = $this->extractAmountFromText($cleanLine);

            Log::debug('Batch: Processing line', [
                'line_index' => $i,
                'original_line' => $line,
                'clean_line' => $cleanLine,
                'extracted_amount' => $amount,
            ]);

            if ($amount && $amount > 0) {
                // Get description (text before the amount)
                $description = self::cleanBatchDescription($this->extractDescriptionFromLine($cleanLine));
                $descLower = strtolower($description);

                // Determine type per-line based on keywords in THIS line
                if (! $hasHeaderType || $firstLineIsTransaction) {
                    // Check first word for type determination
                    $firstWordMatch = [];
                    // Try to match first alphabetic word (handles both "beli makan 30rb" and "20rb maxim dari...")
                    if (preg_match('/^([a-zA-Z]+)/', $cleanLine, $firstWordMatch)) {
                        $firstWord = strtolower($firstWordMatch[1]);
                    } elseif (preg_match('/\d[\d.,]*\s*(rb|ribu|k|jt|juta)?\s+([a-zA-Z]+)/i', $cleanLine, $firstWordMatch)) {
                        // Amount-first: extract first word after amount
                        $firstWord = strtolower($firstWordMatch[2]);
                    } else {
                        $firstWord = '';
                    }

                    // Explicit expense keywords take priority
                    $isExplicitExpense = in_array($firstWord, ['pengeluaran', 'belanja', 'bayar', 'beli'])
                        || preg_match('/^(pengeluaran|belanja|bayar|beli)/i', $descLower);

                    $isExplicitIncome = in_array($firstWord, $incomeActionKeywords) ||
                        preg_match('/^(pemasukan|pendapatan|dari|terima|dapat|dapet|gaji|bonus|honor|masuk|uang\\s+masuk|dikasih|dikasi|hadiah|kado|angpao)/i', $descLower);

                    if ($isSingleLineSplit && ! $isExplicitExpense && ! $isExplicitIncome && $singleLineIsIncome !== null) {
                        // ", 5 Oktober 500rb" tidak punya kata jenis → ikut kalimat pembuka
                        $isIncome = $singleLineIsIncome;
                    } else {
                        $isIncome = ! $isExplicitExpense && $isExplicitIncome;
                    }
                } else {
                    $isIncome = $isIncomeDefault;
                }

                // Normalize description for "Pemasukan/Pengeluaran [amount]" format:
                // After extractDescriptionFromLine strips the amount, only the prefix word remains.
                // E.g.: "Pemasukan 21.851" → description = "Pemasukan"
                //        "Pengeluaran 6.500" → description = "Pengeluaran"
                $genericPrefixWords = ['pemasukan', 'pendapatan', 'pengeluaran', 'belanja', 'bayar'];
                if (trim($description) === '' || in_array(strtolower(trim($description)), $genericPrefixWords)) {
                    $description = $isIncome ? 'Pemasukan' : 'Pengeluaran';
                }

                // For "Name Amount" format (e.g., "Agung 116k"), prefix with context
                // Skip if description is already a type label (Pemasukan/Pengeluaran)
                if ($isIncome && preg_match('/^[A-Za-z]+$/i', trim($description))
                    && ! in_array(strtolower(trim($description)), $genericPrefixWords)) {
                    // Single word name - likely a person's name
                    $description = 'Dari '.ucfirst(strtolower(trim($description)));
                }

                // Determine category based on description
                // Include header context for better category resolution
                $contextText = $description;
                if (! $firstLineIsTransaction && ! empty($headerLine) && $i > 0) {
                    $headerContext = strtolower(trim($headerLine));
                    // Only prepend header if it's a real header (not a transaction line)
                    if (! empty($headerContext) && ! in_array($headerContext, $genericPrefixWords)) {
                        $contextText = $headerContext.' '.$description;
                    }
                }
                $categoryType = $this->determineCategoryFromText($contextText, $isIncome);

                $transactions[] = [
                    'description' => $description,
                    'amount' => $amount,
                    'category_type' => $categoryType,
                    'type' => $isIncome ? 'income' : 'expense',
                    'transaction_date' => $lineDate ?? $transactionDate,
                ];

                Log::debug('Batch: Transaction added to array', [
                    'transaction_count' => count($transactions),
                    'description' => $description,
                    'amount' => $amount,
                    'type' => $isIncome ? 'income' : 'expense',
                ]);

                if ($isIncome) {
                    $totalIncomeAmount += $amount;
                } else {
                    $totalExpenseAmount += $amount;
                }
            }
        }

        // Create transactions
        if (empty($transactions)) {
            $this->sendReply(
                "⚠️ *Tidak dapat memproses transaksi*\n\n".
                "Format tidak dikenali. Pastikan setiap baris memiliki deskripsi dan nominal.\n\n".
                "*Contoh format sederhana:*\n".
                "beli makan siang 38.000\n".
                "beli kantong asi 50.000\n".
                "sedekah ahmad 70.000\n\n".
                "*Contoh format dengan header:*\n".
                "Biaya tanggal 10 Desember 2025\n".
                "1. Makan malam 17.000\n".
                '2. Grab pulang 13.500'
            );

            return;
        }

        // Create all transactions
        $createdCount = 0;
        $createdTransactions = [];

        foreach ($transactions as $txData) {
            $transaction = $this->createTransaction([
                'type' => $txData['type'],
                'amount' => $txData['amount'],
                'category_type' => $txData['category_type'],
                'transaction_date' => $txData['transaction_date'],
                'description' => $txData['description'],
                'source' => 'batch_input',
                'confidence_score' => 0.95,
                'account_name' => $walletName, // Use detected wallet name from instruction
            ], false);

            if ($transaction) {
                $createdCount++;
                $createdTransactions[] = [
                    'description' => $txData['description'],
                    'amount' => $txData['amount'],
                    'type' => $txData['type'],
                    'category' => $transaction->category->name ?? 'Lainnya',
                    'date' => $txData['transaction_date'],
                ];
            }
        }

        // Send confirmation
        if ($createdCount > 0) {
            $hasIncome = $totalIncomeAmount > 0;
            $hasExpense = $totalExpenseAmount > 0;
            $distinctDates = array_values(array_unique(array_column($createdTransactions, 'date')));
            $sameDate = count($distinctDates) === 1;

            $reply = "✅ *{$createdCount} Transaksi Berhasil Dicatat!*\n";
            if ($sameDate) {
                $reply .= '📅 Tanggal: '.Carbon::parse($distinctDates[0])->translatedFormat('d F Y')."\n";
            }
            $reply .= "━━━━━━━━━━━━━━━\n\n";

            foreach ($createdTransactions as $idx => $tx) {
                $num = $idx + 1;
                $amount = number_format($tx['amount'], 0, ',', '.');
                $typeEmoji = $tx['type'] === 'income' ? '💵' : '💸';
                $reply .= "{$num}. {$tx['description']}\n";
                $reply .= "   {$typeEmoji} Rp {$amount} • {$tx['category']}\n";
                if (! $sameDate) {
                    $reply .= '   📅 '.Carbon::parse($tx['date'])->translatedFormat('d F Y')."\n";
                }
            }

            $reply .= "\n━━━━━━━━━━━━━━━\n";

            // Show total for each type
            if ($hasExpense) {
                $reply .= '📊 *Total Pengeluaran*: Rp '.number_format($totalExpenseAmount, 0, ',', '.')."\n";
            }
            if ($hasIncome) {
                $reply .= '📊 *Total Pemasukan*: Rp '.number_format($totalIncomeAmount, 0, ',', '.')."\n";
            }

            // Show wallet info if specified
            if ($walletName) {
                $reply .= '👛 *Dikurangi dari*: '.ucfirst($walletName)."\n";
            }

            $this->sendReply($reply);
        } else {
            $this->sendReply(
                "⚠️ *Gagal mencatat transaksi*\n\n".
                'Terjadi kesalahan saat menyimpan transaksi. Silakan coba lagi.'
            );
        }
    }

    /**
     * Parse date from header line
     * Supports: "tanggal 10 Desember 2025", "tgl 10/12/2025", "10 Des 2025"
     *
     * MOVED FROM: ProcessIncomingMessage::parseDateFromHeader()
     * LINES: 7054-7104
     * MODIFICATION: None (structural move only)
     */
    public function parseDateFromHeader(string $headerLine): string
    {
        $headerLower = strtolower($headerLine);

        // Month name mapping
        $months = [
            'januari' => '01', 'jan' => '01',
            'februari' => '02', 'feb' => '02',
            'maret' => '03', 'mar' => '03',
            'april' => '04', 'apr' => '04',
            'mei' => '05', 'may' => '05',
            'juni' => '06', 'jun' => '06',
            'juli' => '07', 'jul' => '07',
            'agustus' => '08', 'agu' => '08', 'aug' => '08',
            'september' => '09', 'sep' => '09', 'sept' => '09',
            'oktober' => '10', 'okt' => '10', 'oct' => '10',
            'november' => '11', 'nov' => '11',
            'desember' => '12', 'des' => '12', 'dec' => '12',
        ];

        // Pattern: "10 Desember 2025" or "10 Des 2025"
        if (preg_match('/(\d{1,2})\s+(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember|jan|feb|mar|apr|may|jun|jul|aug|agu|sep|sept|okt|oct|nov|des|dec)\s*(\d{4})?/i', $headerLower, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = $months[strtolower($matches[2])] ?? '01';
            $year = $matches[3] ?? date('Y');

            return "{$year}-{$month}-{$day}";
        }

        // Pattern: "10/12/2025" or "10-12-2025"
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $headerLine, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];

            return "{$year}-{$month}-{$day}";
        }

        // Pattern: "hari ini", "kemarin"
        if (str_contains($headerLower, 'hari ini')) {
            return now()->toDateString();
        }
        if (str_contains($headerLower, 'kemarin')) {
            return now()->subDay()->toDateString();
        }

        // Default to today
        return now()->toDateString();
    }

    private const MONTH_PATTERN = 'januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember|jan|feb|mar|apr|jun|jul|agu|agt|ags|aug|sep|sept|okt|oct|nov|des|dec';

    private const MONTH_MAP = [
        'januari' => 1, 'jan' => 1, 'februari' => 2, 'feb' => 2, 'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'mei' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7,
        'agustus' => 8, 'agu' => 8, 'agt' => 8, 'ags' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9, 'oktober' => 10, 'okt' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11, 'desember' => 12, 'des' => 12, 'dec' => 12,
    ];

    /**
     * Hapus tanggal ("22 Oktober 2026", "5 Okt", "22/09/2026") dari baris supaya
     * angka tanggal tidak terbaca nominal saat menilai judul vs transaksi.
     */
    public static function stripDates(string $line): string
    {
        $line = (string) preg_replace('/\b\d{1,2}\s*(?:'.self::MONTH_PATTERN.')\b(?:\s+(?:19|20)\d{2}\b)?/iu', ' ', $line);
        $line = (string) preg_replace('/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-](?:19|20)?\d{2})?\b/u', ' ', $line);

        return trim((string) preg_replace('/\s{2,}/', ' ', $line));
    }

    /**
     * Baris diakhiri nominal uang ("… 300rb", "… 25.000", "… 15000")?
     * Tahun setelah nama bulan ("Desember 2025") BUKAN nominal → judul tetap judul.
     */
    public static function lineHasTrailingAmount(string $line): bool
    {
        $t = mb_strtolower(trim($line));
        $t = (string) preg_replace('/\b(?:'.self::MONTH_PATTERN.')\s+(?:19|20)\d{2}\b/u', '', $t);

        return (bool) preg_match(
            '/(?:\d+(?:[.,]\d+)?\s*(?:rb|ribu|rebu|k|jt|juta)|\d{1,3}(?:\.\d{3})+|\d{4,})\s*[.,;!:-]*\s*$/iu',
            $t
        );
    }

    /**
     * Ambil & hapus tanggal di dalam satu item: "28 September", "tanggal 5 Oktober 2026",
     * "tgl 12", "tgl 5/10". Tanpa tahun → tahun ini; bila jadi tanggal di masa depan
     * (> besok) → tahun lalu ("28 Desember" yang dikirim Januari).
     *
     * @return string|null Y-m-d, atau null bila tidak ada tanggal
     */
    protected function extractInlineDate(string &$line): ?string
    {
        $day = $month = $year = null;

        if (preg_match('/(?:\b(?:tanggal|tgl)\.?\s*)?\b(\d{1,2})\s+('.self::MONTH_PATTERN.')\b(?:\s+((?:19|20)\d{2}))?/iu', $line, $m)) {
            [$day, $month, $year] = [(int) $m[1], self::MONTH_MAP[mb_strtolower($m[2])] ?? null, isset($m[3]) ? (int) $m[3] : null];
        } elseif (preg_match('/\b(?:tanggal|tgl)\.?\s*(\d{1,2})[\/\-](\d{1,2})(?:[\/\-]((?:19|20)?\d{2}))?\b/iu', $line, $m)) {
            [$day, $month] = [(int) $m[1], (int) $m[2]];
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
            if ($year !== null && $year < 100) {
                $year += 2000;
            }
        } elseif (preg_match('/\b(?:tanggal|tgl)\.?\s*(\d{1,2})\b(?!\s*(?:rb|ribu|k|jt|juta|[.,]\d))/iu', $line, $m)) {
            [$day, $month] = [(int) $m[1], (int) now()->month];
        }

        if (! $day || ! $month || $day > 31 || $month > 12 || ! checkdate($month, $day, $year ?? (int) now()->year)) {
            return null;
        }

        $date = Carbon::create($year ?? (int) now()->year, $month, $day)->startOfDay();
        if ($year === null && $date->gt(now()->addDay())) {
            $date->subYear();
        }

        $line = trim((string) preg_replace('/\s{2,}/', ' ', str_replace($m[0], ' ', $line)));

        return $date->toDateString();
    }

    /**
     * Bersihkan keterangan item batch dari kata pengantar & pengisi:
     * "tolong di catat juga dong Pemasukan sebesar" → "Pemasukan".
     */
    public static function cleanBatchDescription(string $description): string
    {
        $d = trim($description);
        $d = (string) preg_replace('/^[\s,;:.\-–—&]+/u', '', $d);
        $d = (string) preg_replace('/^(?:(?:tolong|mohon|minta|coba)\s+)?(?:di\s*)?catat(?:kan|in)?\s+(?:(?:juga|ya|dong|donk|nih|kak|min|sekalian)\s+)*/iu', '', $d);
        $d = (string) preg_replace('/\s+(?:sebesar|senilai|sejumlah|seharga|nominal|total|rp\.?)\s*$/iu', '', $d);
        $d = (string) preg_replace('/^(?:sebesar|senilai|sejumlah)\s+/iu', '', $d);
        $d = (string) preg_replace('/\s+(?:dan|sama|serta|,)\s*$/iu', '', $d);

        return trim($d, " \t,;:.-");
    }

    /**
     * Split a single line containing multiple transactions into multiple lines
     */
    protected function splitSingleBatchLine(string $text): array
    {
        $lines = [];
        $offset = 0;

        // Pattern matches amount like "25rb", "15k", "20.000", "50000"
        $amountPattern = '/(?:\b\d+(?:[.,]\d+)?\s*(?:rb|ribu|k|jt|juta)\b)|(?:\b\d{1,3}(?:\.\d{3})+\b)|(?:\b\d{1,3}(?:\s+\d{3})+\b)|(?:\b\d{4,}\b)/i';

        if (preg_match_all($amountPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                // Tahun setelah nama bulan ("28 September 2026") bukan nominal → jangan dipotong di sini
                if (preg_match('/^(?:19|20)\d{2}$/', $match[0])
                    && preg_match('/(?:'.self::MONTH_PATTERN.')\s*$/iu', substr($text, 0, $match[1]))) {
                    continue;
                }
                $amountOffset = $match[1];
                $amountLen = strlen($match[0]);

                $endPos = $amountOffset + $amountLen;

                $chunk = substr($text, $offset, $endPos - $offset);
                if (trim($chunk) !== '') {
                    $chunk = trim($chunk);
                    // Buang kata penghubung yang tertinggal dari split sebelumnya
                    // ("Beli rokok 24000 | sama jajan 6000" → "jajan 6000")
                    $chunk = preg_replace('/^(?:sama|dan|&|plus|terus|lalu|juga|kemudian)\s+/iu', '', $chunk);
                    if ($chunk !== '') {
                        $lines[] = $chunk;
                    }
                }

                $offset = $endPos;
            }
        }

        // If regex failed but logic called, fallback to original text (as array)
        if (empty($lines)) {
            return [$text];
        }

        return $lines;
    }
}
