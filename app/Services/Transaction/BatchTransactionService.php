<?php

namespace App\Services\Transaction;

use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

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
        callable $extractAmountCallback = null,
        callable $extractDescriptionCallback = null,
        callable $determineCategoryCallback = null,
        callable $createTransactionCallback = null
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
            $amounts = \App\Helpers\BatchTransactionHelper::extractAllAmounts($messageText);
            if (count($amounts) >= 2) {
                return true;
            }
            return false;
        }
        
        // Action keywords that indicate a transaction line (common Indonesian transaction verbs)
        $actionKeywords = [
            // Pengeluaran
            'beli', 'bayar', 'belanja', 'jajan', 'byr',
            'makan', 'mkn', 'maem', 'ngopi', 'minum',
            'gorengan', 'snack', 'cemilan', 'jajanan', // Food items
            'sarapan', 'siang', 'malam', 'brunch', // Meal times
            'nonton', 'lihat', 'main', // Entertainment 
            'ongkos', 'ongkir', 'kirim', 'transfer', 'tf',
            'sewa', 'kontrak', 'kos', 'kost',
            'servis', 'service', 'bensin', 'parkir', 'isi', 'ngisi', // Utility
            'topup', 'top', 'voucher', // Digital
            'sedekah', 'infaq', 'infak', 'zakat', 'sumbangan', 'donasi',
            'kasih', 'ngasih', 'kirimin', 'buat',
            // Pemasukan
            'terima', 'dapat', 'dapet', 'gaji', 'bonus', 'honor',
            'dikasih', 'dikasi', 'hadiah', 'kado', 'angpao', 'angpau',
        ];
        
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
        $actionAmountPattern = '/^([a-zA-Z]+)\s+.+\s+(\d[\d\.,]*)\s*(rb|ribu|k|jt|juta)?$/i';

        
        // Check all lines for transaction patterns
        foreach ($lines as $index => $rawLine) {
            $line = trim($rawLine);
            if (empty($line)) continue;
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
                
                if (!$foundPattern) {
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
            'first_line' => mb_substr($lines[0], 0, 50)
        ]);
        
        // Consider it a batch if:
        // 1. Has header pattern AND at least 2 numbered/bulleted items, OR
        // 2. Has 3 or more numbered/bulleted items (even without header), OR
        // 3. NEW: Has 2 or more lines matching "action desc amount" format
        return ($hasHeaderPattern && $itemCount >= 2) || $itemCount >= 3 || $simpleFormatCount >= 2;
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
            'text_preview' => mb_substr($messageText, 0, 200)
        ]);
        
        $lines = preg_split('/[\r\n]+/', trim($messageText));
        
        // Handle Single Line Batch (Split horizontally)
        if (count($lines) < 2) {
             $amounts = \App\Helpers\BatchTransactionHelper::extractAllAmounts($messageText);
             if (count($amounts) >= 2) {
                 $lines = $this->splitSingleBatchLine($messageText);
                 Log::info('Single line batch split', ['count' => count($lines), 'lines' => $lines]);
             }
        }
        
        $headerLine = trim($lines[0] ?? '');
        $headerLower = strtolower($headerLine);
        
        // Action keywords to detect if first line is a transaction (not a header)
        $expenseActionKeywords = [
            'beli', 'bayar', 'belanja', 'jajan', 'byr',
            'makan', 'mkn', 'maem', 'ngopi', 'minum',
            'nonton', 'lihat', 'main',
            'ongkos', 'ongkir', 'kirim', 'sewa', 'kontrak', 'kos', 'kost',
            'servis', 'service', 'bensin', 'parkir', 'isi', 'ngisi',
            'topup', 'top', 'voucher',
            'sedekah', 'infaq', 'infak', 'zakat', 'sumbangan', 'donasi',
            'kasih', 'ngasih', 'kirimin', 'buat',
        ];
        $incomeActionKeywords = [
            'terima', 'dapat', 'dapet', 'gaji', 'bonus', 'honor', 'masuk',
            'dikasih', 'dikasi', 'hadiah', 'kado', 'angpao', 'angpau',
        ];
        $allActionKeywords = array_merge($expenseActionKeywords, $incomeActionKeywords);
        
        // Check if first line is a transaction (starts with action keyword + has amount)
        $firstLineIsTransaction = false;
        $actionAmountPattern = '/^([a-zA-Z]+)\s+.+\s+(\d+(?:[\.,]\d+)?)\s*(rb|ribu|k|jt|juta)?$/i';
        if (preg_match($actionAmountPattern, $headerLine, $matches)) {
            $firstWord = strtolower($matches[1]);
            if (in_array($firstWord, $allActionKeywords)) {
                $firstLineIsTransaction = true;
            }
        }
        
        // Parse date from header (only if first line is NOT a transaction)
        $transactionDate = $firstLineIsTransaction ? now()->toDateString() : $this->parseDateFromHeader($headerLine);
        
        // Determine default type from header (only if first line is NOT a transaction)
        $hasHeaderType = false;
        $isIncomeDefault = false;
        if (!$firstLineIsTransaction) {
            $isIncomeDefault = (bool) preg_match('/pemasukan|pendapatan|terima|gaji|bonus|uang\s+masuk|transfer\s+masuk|setoran/i', $headerLower);
            $isExpenseDefault = (bool) preg_match('/pengeluaran|belanja|biaya|bayar|uang\s+keluar|transfer\s+keluar/i', $headerLower);
            
            // Default to income if "uang masuk" type header
            if (!$isIncomeDefault && !$isExpenseDefault) {
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
            if (empty($line)) continue;
            
            // Remove bullet/number prefix
            $cleanLine = preg_replace('/^[\d]+[\.)\\-]\s*/', '', $line); // Remove "1. " or "1) " or "1- "
            $cleanLine = preg_replace('/^[\-\•\*]\s*/', '', $cleanLine); // Remove "- " or "• " or "* "
            $cleanLine = trim($cleanLine);
            
            if (empty($cleanLine)) continue;
            
            // Extract amount from the line
            $amount = $this->extractAmountFromText($cleanLine);
            
            Log::info("DEBUG Batch: Processing line", [
                'line_index' => $i,
                'original_line' => $line,
                'clean_line' => $cleanLine,
                'extracted_amount' => $amount,
            ]);
            
            if ($amount && $amount > 0) {
                // Get description (text before the amount)
                $description = $this->extractDescriptionFromLine($cleanLine);
                $descLower = strtolower($description);
                
                // Determine type per-line based on action keywords (if no header type)
                if (!$hasHeaderType || $firstLineIsTransaction) {
                    // Check first word for type determination
                    $firstWordMatch = [];
                    preg_match('/^([a-zA-Z]+)/', $cleanLine, $firstWordMatch);
                    $firstWord = strtolower($firstWordMatch[1] ?? '');
                    
                    $isIncome = in_array($firstWord, $incomeActionKeywords) || 
                                preg_match('/^(dari|terima|dapat|dapet|gaji|bonus|honor|masuk|uang\\s+masuk|dikasih|dikasi|hadiah|kado|angpao)/i', $descLower);
                } else {
                    $isIncome = $isIncomeDefault;
                }
                
                // For "Name Amount" format (e.g., "Agung 116k"), prefix with context
                if ($isIncome && preg_match('/^[A-Za-z]+$/i', trim($description))) {
                    // Single word name - likely a person's name
                    $description = "Dari " . ucfirst(strtolower(trim($description)));
                }
                
                // Determine category based on description
                $categoryType = $this->determineCategoryFromText($description, $isIncome);
                
                // For income from names, use appropriate category
                if ($isIncome && preg_match('/^dari\s+/i', $description)) {
                    $categoryType = 'pendapatan_lainnya';
                }
                
                $transactions[] = [
                    'description' => $description,
                    'amount' => $amount,
                    'category_type' => $categoryType,
                    'type' => $isIncome ? 'income' : 'expense',
                    'transaction_date' => $transactionDate,
                ];
                
                Log::info("DEBUG Batch: Transaction added to array", [
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
                "⚠️ *Tidak dapat memproses transaksi*\n\n" .
                "Format tidak dikenali. Pastikan setiap baris memiliki deskripsi dan nominal.\n\n" .
                "*Contoh format sederhana:*\n" .
                "beli makan siang 38.000\n" .
                "beli kantong asi 50.000\n" .
                "sedekah ahmad 70.000\n\n" .
                "*Contoh format dengan header:*\n" .
                "Biaya tanggal 10 Desember 2025\n" .
                "1. Makan malam 17.000\n" .
                "2. Grab pulang 13.500"
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
                'account_name' => null,
            ], false);
            
            if ($transaction) {
                $createdCount++;
                $createdTransactions[] = [
                    'description' => $txData['description'],
                    'amount' => $txData['amount'],
                    'type' => $txData['type'],
                    'category' => $transaction->category->name ?? 'Lainnya',
                ];
            }
        }
        
        // Send confirmation
        if ($createdCount > 0) {
            $hasIncome = $totalIncomeAmount > 0;
            $hasExpense = $totalExpenseAmount > 0;
            
            $reply = "✅ *{$createdCount} Transaksi Berhasil Dicatat!*\n";
            $reply .= "📅 Tanggal: " . Carbon::parse($transactionDate)->translatedFormat('d F Y') . "\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";
            
            foreach ($createdTransactions as $idx => $tx) {
                $num = $idx + 1;
                $amount = number_format($tx['amount'], 0, ',', '.');
                $typeEmoji = $tx['type'] === 'income' ? '💵' : '💸';
                $reply .= "{$num}. {$tx['description']}\n";
                $reply .= "   {$typeEmoji} Rp {$amount} • {$tx['category']}\n";
            }
            
            $reply .= "\n━━━━━━━━━━━━━━━\n";
            
            // Show total for each type
            if ($hasExpense) {
                $reply .= "📊 *Total Pengeluaran*: Rp " . number_format($totalExpenseAmount, 0, ',', '.') . "\n";
            }
            if ($hasIncome) {
                $reply .= "📊 *Total Pemasukan*: Rp " . number_format($totalIncomeAmount, 0, ',', '.') . "\n";
            }
            
            $this->sendReply($reply);
        } else {
            $this->sendReply(
                "⚠️ *Gagal mencatat transaksi*\n\n" .
                "Terjadi kesalahan saat menyimpan transaksi. Silakan coba lagi."
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
    /**
     * Split a single line containing multiple transactions into multiple lines
     */
    protected function splitSingleBatchLine(string $text): array
    {
        $lines = [];
        $offset = 0;
        
        // Pattern matches amount like "25rb", "15k", "20.000", "50000"
        $amountPattern = '/(?:\b\d+(?:[.,]\d+)?\s*(?:rb|ribu|k|jt|juta)\b)|(?:\b\d{1,3}(?:\.\d{3})+\b)|(?:\b\d{4,}\b)/i';
        
        if (preg_match_all($amountPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $amountOffset = $match[1];
                $amountLen = strlen($match[0]);
                
                $endPos = $amountOffset + $amountLen;
                
                $chunk = substr($text, $offset, $endPos - $offset);
                if (trim($chunk) !== '') {
                    $lines[] = trim($chunk);
                }
                
                $offset = $endPos;
            }
        }
        
        // If regex failed but logic called, fallback to original text (as array)
        if (empty($lines)) return [$text];
        
        return $lines;
    }
}
