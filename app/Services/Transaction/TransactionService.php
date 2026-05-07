<?php

namespace App\Services\Transaction;

use App\Models\Balance;
use App\Models\Category;
use App\Models\Message;
use App\Models\Transaction;
use App\Services\AchievementService;
use App\Services\AIProcessorService;
use App\Services\BalanceService;
use App\Services\Category\CategoryCorrectionService;
use App\Services\ConversationContextService;
use App\Services\DebtReceivable\CounterpartyExtractor;
use Illuminate\Support\Facades\Log;

/**
 * TransactionService - Handles core transaction operations
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class TransactionService
{
    protected Message $message;
    protected CategoryInferenceService $categoryInference;


    protected $sendReplyCallback;

    protected $extractTransactionLocallyCallback;

    protected $extractAccountNameFromMessageCallback;

    protected $mapFinwaKategoriToCategoryTypeCallback;

    protected $createCategoriesForTenantCallback;

    protected $checkBudgetAlertCallback;

    protected $sendTransactionConfirmationCallback;

    protected $parseDateFromHeaderCallback;

    /**
     * Constructor with dependency injection for cross-service methods
     */
    public function __construct(
        Message $message,
        CategoryInferenceService $categoryInference,
        callable $sendReplyCallback,
        callable $extractTransactionLocallyCallback,
        callable $extractAccountNameFromMessageCallback,
        callable $mapFinwaKategoriToCategoryTypeCallback,
        callable $createCategoriesForTenantCallback,
        callable $checkBudgetAlertCallback,
        callable $sendTransactionConfirmationCallback,
        callable $parseDateFromHeaderCallback
    ) {
        $this->message = $message;
        $this->categoryInference = $categoryInference;
        $this->sendReplyCallback = $sendReplyCallback;
        $this->extractTransactionLocallyCallback = $extractTransactionLocallyCallback;
        $this->extractAccountNameFromMessageCallback = $extractAccountNameFromMessageCallback;
        $this->mapFinwaKategoriToCategoryTypeCallback = $mapFinwaKategoriToCategoryTypeCallback;
        $this->createCategoriesForTenantCallback = $createCategoriesForTenantCallback;
        $this->checkBudgetAlertCallback = $checkBudgetAlertCallback;
        $this->sendTransactionConfirmationCallback = $sendTransactionConfirmationCallback;
        $this->parseDateFromHeaderCallback = $parseDateFromHeaderCallback;
    }

    // Helper methods to call callbacks
    protected function sendReply(string $message): void
    {
        call_user_func($this->sendReplyCallback, $message);
    }

    protected function extractTransactionLocally(string $messageText): ?array
    {
        return call_user_func($this->extractTransactionLocallyCallback, $messageText);
    }

    protected function extractAccountNameFromMessage(string $messageText): ?string
    {
        return call_user_func($this->extractAccountNameFromMessageCallback, $messageText);
    }

    protected function mapFinwaKategoriToCategoryType(?string $kategori, bool $isIncome): string
    {
        return call_user_func($this->mapFinwaKategoriToCategoryTypeCallback, $kategori, $isIncome);
    }

    protected function createCategoriesForTenant(int $tenantId): void
    {
        call_user_func($this->createCategoriesForTenantCallback, $tenantId);
    }

    protected function checkBudgetAlert(Transaction $transaction)
    {
        return call_user_func($this->checkBudgetAlertCallback, $transaction);
    }

    protected function sendTransactionConfirmation($transactions, bool $needsReview = false): void
    {
        call_user_func($this->sendTransactionConfirmationCallback, $transactions, $needsReview);
    }

    protected function parseDateFromHeader(string $headerLine): string
    {
        return call_user_func($this->parseDateFromHeaderCallback, $headerLine);
    }

    /**
     * Get the actual sender ID (participant if in group)
     */
    protected function getAttributionSenderId(): string
    {
        $metadata = is_array($this->message->metadata) ? $this->message->metadata : json_decode($this->message->metadata ?? '{}', true);
        if (($metadata['is_group'] ?? false) && ! empty($metadata['author'])) {
            return $metadata['author'];
        }

        return $this->message->sender_id;
    }

    /**
     * Extract amount from text (supports: 15rb, 50.000, 1jt, Rp 100000)
     */
    protected function extractAmountFromText(string $text): float
    {
        $text = strtolower(trim($text));

        // Remove Rp prefix
        $text = preg_replace('/^rp\s*/i', '', $text);

        // Pattern: 15rb, 50ribu, 25k
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(rb|ribu|k)$/i', $text, $matches)) {
            $num = str_replace(',', '.', $matches[1]);

            return floatval($num) * 1000;
        }

        // Pattern: 1jt, 2.5juta, 1m
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(jt|juta|m)$/i', $text, $matches)) {
            $num = str_replace(',', '.', $matches[1]);

            return floatval($num) * 1000000;
        }

        // Pattern: 50.000 or 50,000 or 50000
        if (preg_match('/^(\d{1,3}(?:[.,]\d{3})*)$/i', $text, $matches)) {
            $num = str_replace(['.', ','], '', $matches[1]);

            return floatval($num);
        }

        // Plain number
        if (preg_match('/^(\d+)$/', $text, $matches)) {
            return floatval($matches[1]);
        }

        return 0;
    }

    /**
     * Handle transaction extraction
     *
     * MOVED FROM: ProcessIncomingMessage::handleTransaction()
     * LINES: 3420-3672
     * MODIFICATION: None (structural move only)
     */
    public function handleTransaction(string $messageText, ?array $finwaEntities = null): void
    {
        $result = null;

        // CHECK FOR PENDING TRANSACTION FOLLOW-UP
        // If user previously sent "naik ojek" (no amount), and now sends "15rb", combine them
        $msgTrimmed = trim($messageText);
        $isOnlyAmount = preg_match('/^(Rp\s?)?\d+([.,]\d+)?\s*(rb|ribu|k|jt|juta|m)?$/i', $msgTrimmed);

        if ($isOnlyAmount) {
            try {
                $contextService = new ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId());
                $pending = $contextService->getPendingTransaction();

                if ($pending) {
                    Log::info('Processing pending transaction with amount', [
                        'message_id' => $this->message->id,
                        'pending' => $pending,
                        'amount_text' => $msgTrimmed,
                    ]);

                    // Extract amount from message
                    $amount = $this->extractAmountFromText($msgTrimmed);

                    if ($amount > 0) {
                        // Clear pending transaction
                        $contextService->clearPendingTransaction();

                        // Determine category from the pending description
                        $categoryType = $pending['type'] === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
                        $categoryMapping = call_user_func($this->mapFinwaKategoriToCategoryTypeCallback, $pending['keyword'], $pending['type'] === 'income');
                        if ($categoryMapping) {
                            $categoryType = $categoryMapping;
                        }

                        // Create the transaction
                        $txData = [
                            'type' => $pending['type'],
                            'amount' => $amount,
                            'category' => $pending['keyword'],
                            'category_type' => $categoryType,
                            'description' => $pending['description'],
                            'account_name' => null,
                            'transaction_date' => now()->toDateString(),
                            'confidence_score' => 0.9,
                            'source' => 'pending_followup',
                        ];

                        $transaction = $this->createTransaction($txData, false);

                        if ($transaction) {
                            $this->sendTransactionConfirmation([$transaction], false);

                            return;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error processing pending transaction', ['error' => $e->getMessage()]);
            }
        }

        // If we have FinWa entities with amount, use them directly (FAST PATH)
        if ($finwaEntities && isset($finwaEntities['nominal']) && $finwaEntities['nominal'] > 0) {
            Log::info('Using FinWa-AI entities directly (fast path)', [
                'message_id' => $this->message->id,
                'nominal' => $finwaEntities['nominal'],
                'kategori' => $finwaEntities['kategori'] ?? null,
                'merchant' => $finwaEntities['merchant'] ?? null,
            ]);

            // PRIORITY 0: Check expense override patterns FIRST (overrides AI intent)
            // Read from config (single source of truth)
            $messageTextLower = strtolower($messageText);

            // NEW: If AI already provided category_type and is_income, use them directly
            $aiCategoryType = $finwaEntities['category_type'] ?? null;
            $aiIsIncome = $finwaEntities['is_income'] ?? null;
            $hasAiCategoryType = ! empty($aiCategoryType);

            $expenseOverridePatterns = config('finwa_category_rules.expense_detection_patterns', []);
            $isExpenseOverride = false;
            foreach ($expenseOverridePatterns as $pattern) {
                if (str_contains($messageTextLower, $pattern)) {
                    $isExpenseOverride = true;
                    break;
                }
            }
            // "Bayar" sebagai kata sendiri (bukan bagian dari "pembayaran")
            if (! $isExpenseOverride && preg_match('/\bbayar\b/u', $messageTextLower)) {
                $isExpenseOverride = true;
            }

            // PRIORITY 1: Check if FinWa-AI detected catat_pemasukan intent
            // But ONLY if expense override is NOT detected
            $finwaIntent = $finwaEntities['_finwa_intent'] ?? null;
            $isIncome = ! $isExpenseOverride && (
                ($finwaIntent === 'catat_pemasukan') ||
                preg_match('/\b(thr|bonus)\b/i', $messageTextLower)
            );

            // PRIORITY 2: Fallback to context detection if intent not available and no override
            // OR if AI said it was an expense but it contains clear income keywords
            // GUARD: If it looks like a receipt, don't force income
            $isReceiptText = str_contains($messageTextLower, 'terima kasih') || str_contains($messageTextLower, 'total') || str_contains($messageTextLower, 'subtotal');

            if (! $isExpenseOverride && ! $isIncome && ! $isReceiptText && ($finwaIntent !== 'catat_pengeluaran' || preg_match('/\b(pemasukan|pendapatan|thr|duit masuk|uang masuk|masuk\s+pembayaran|pembayaran\s+masuk|terima\s+gaji|terima\s+transfer|terima\s+pembayaran)\b/i', $messageTextLower))) {
                $incomeKeywords = config('finwa_category_rules.income_detection_keywords', []);
                foreach ($incomeKeywords as $keyword) {
                    if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $messageTextLower)) {
                        $isIncome = true;
                        break;
                    }
                }
                // Additional pattern: "terima" only with financial context (not "terima kasih")
                if (! $isIncome && preg_match('/\bterima\b/u', $messageTextLower) && ! str_contains($messageTextLower, 'terima kasih')
                    && (preg_match('/\bgaji\b/u', $messageTextLower) || preg_match('/\btransfer\b/u', $messageTextLower) || preg_match('/\bpembayaran\b/u', $messageTextLower))) {
                    $isIncome = true;
                }
                if (! $isIncome) {
                    $isIncome = preg_match('/di\s*(kasih|kasi|kirimin?|transfer)/i', $messageTextLower);
                }
            }

            // Map FinWa-AI kategori to system category_type format
            $kategori = $finwaEntities['kategori'] ?? null;

            // Override kategori when expense override is detected with gaji/upah/honor keywords
            // This bypasses unreliable AI category detection
            if ($isExpenseOverride && (preg_match('/\bgaji\b/u', $messageTextLower) || preg_match('/\bupah\b/u', $messageTextLower) || preg_match('/\bhonor\b/u', $messageTextLower))) {
                $kategori = 'gaji';
            }

            // AI category overrides — config-driven (single source of truth)
            // Expense overrides: ALWAYS expense ($isIncome dipaksa false)
            $aiOverrides = config('finwa_category_rules.ai_category_overrides', []);
            foreach ($aiOverrides as $keyword => $overrideKategori) {
                if (str_contains($messageTextLower, $keyword)) {
                    $kategori = $overrideKategori;
                    $isIncome = false;
                    break;
                }
            }

            // Income overrides: ALWAYS run (even if AI already set isIncome=true)
            // because AI may pick wrong kategori (e.g. "Transfer Masuk" instead of "Pendapatan Lainnya")
            $incomeOverrides = config('finwa_category_rules.ai_income_overrides', []);
            foreach ($incomeOverrides as $keyword => $overrideKategori) {
                if (str_contains($messageTextLower, $keyword)) {
                    $kategori = $overrideKategori;
                    $isIncome = true;
                    break;
                }
            }

            // Hutang / piutang: pakai intent FinWa (empat aliran), override inferensi income/expense + mapping kategori
            // EXTRA_CATEGORY_OVERRIDES removed - now handled by CategoryInferenceService
            if (is_string($finwaIntent) && in_array($finwaIntent, ['catat_hutang', 'catat_piutang', 'bayar_hutang', 'terima_piutang'], true)) {
                [$isIncome, $categoryType] = match ($finwaIntent) {
                    'catat_hutang' => [true, 'pendapatan_hutang'],
                    'terima_piutang' => [true, 'pendapatan_terima_piutang'],
                    'bayar_hutang' => [false, 'pengeluaran_bayar_hutang'],
                    'catat_piutang' => [false, 'pengeluaran_piutang'],
                };
            } elseif ($hasAiCategoryType) {
                // AI already provided category_type (e.g. "pendapatan_lainnya") — use it directly
                $categoryType = $aiCategoryType;
                if ($aiIsIncome !== null) {
                    $isIncome = $aiIsIncome;
                }
            } else {
                $categoryType = $this->mapFinwaKategoriToCategoryType($kategori, $isIncome);
            }

            $finwaDebtMeta = [];
            foreach (['lawan', 'pihak', 'counterparty', 'nama_pihak', 'nama_lawan'] as $entityKey) {
                if (! empty($finwaEntities[$entityKey]) && is_string($finwaEntities[$entityKey])) {
                    $v = trim($finwaEntities[$entityKey]);
                    if ($v !== '') {
                        $finwaDebtMeta['counterparty'] = $v;
                        $finwaDebtMeta['counterparty_normalized'] = mb_strtolower(preg_replace('/\s+/', ' ', $v));
                        break;
                    }
                }
            }

            $transaction = [
                'type' => $isIncome ? 'income' : 'expense',
                'amount' => $finwaEntities['nominal'],
                'category' => $finwaEntities['kategori'],
                'category_type' => $categoryType,
                'description' => $finwaEntities['catatan'] ?? $messageText,
                'merchant' => $finwaEntities['merchant'],
                'account_name' => $finwaEntities['account_name'] ?? null,
                'transaction_date' => $finwaEntities['tanggal'] ?? now()->toDateString(),
                'confidence_score' => 0.95,
                'source' => 'finwa_ai',
                'metadata' => $finwaDebtMeta,
            ];

            $result = [
                'success' => true,
                'data' => [
                    'extracted_transactions' => [$transaction],
                    'needs_review' => false,
                    'confidence' => 0.95,
                    'source' => 'finwa_ai',
                ],
            ];
        }

        // LOCAL EXTRACTION FALLBACK: Try to extract transaction locally without AI
        // This handles simple messages like "Makan Pagi Hara Chicken 60rb"
        if (! $result) {
            $localExtraction = $this->extractTransactionLocally($messageText);
            if ($localExtraction) {
                Log::info('Using local extraction for transaction (no AI needed)', [
                    'message_id' => $this->message->id,
                    'amount' => $localExtraction['amount'],
                    'type' => $localExtraction['type'],
                    'category_type' => $localExtraction['category_type'],
                ]);

                $result = [
                    'success' => true,
                    'data' => [
                        'extracted_transactions' => [$localExtraction],
                        'needs_review' => false,
                        'confidence' => 0.85,
                        'source' => 'local_extraction',
                    ],
                ];
            }
        }

        // Fallback to AIProcessorService if no local extraction
        if (! $result) {
            Log::info('Using AIProcessorService for transaction extraction', [
                'message_id' => $this->message->id,
            ]);

            $aiService = new AIProcessorService;

            $result = $aiService->extractTransaction(
                $this->message->tenant_id,
                $this->message->id,
                $messageText
            );
        }

        // Use CategoryInferenceService for enhanced context-aware categorization
        // This replaces ACARA FIX, DONASI FIX, and EXTRA_CATEGORY_OVERRIDES
        if ($result && $result['success'] && isset($result['data']['extracted_transactions'])) {
            $txCount = count($result['data']['extracted_transactions']);

            if ($txCount === 1) {
                $txText = $result['data']['extracted_transactions'][0]['description'] ?? $messageText;
                $inference = $this->categoryInference->infer($txText);

                Log::info('CategoryInferenceService result', [
                    'message_id' => $this->message->id,
                    'inference' => $inference,
                    'tx_count' => $txCount,
                ]);

                if ($inference['confidence'] >= 0.4) {
                    $oldCategoryType = $result['data']['extracted_transactions'][0]['category_type'] ?? 'null';
                    $result['data']['extracted_transactions'][0]['category_type'] = $inference['category_type'];
                    $result['data']['extracted_transactions'][0]['category'] = $inference['category_name'];
                    $result['data']['extracted_transactions'][0]['confidence_score'] = $inference['confidence'];

                    Log::info('CategoryInference: Applied to transaction', [
                        'message_id' => $this->message->id,
                        'old_category_type' => $oldCategoryType,
                        'new_category_type' => $inference['category_type'],
                        'new_category_name' => $inference['category_name'],
                        'confidence' => $inference['confidence'],
                        'source' => $inference['source'],
                    ]);
                } else {
                    Log::info('CategoryInference: Confidence too low, keeping original', [
                        'message_id' => $this->message->id,
                        'confidence' => $inference['confidence'],
                        'original_category' => $result['data']['extracted_transactions'][0]['category_type'] ?? 'unknown',
                    ]);
                }
            } elseif ($txCount > 1) {
                $applied = 0;
                $kept = 0;
                foreach ($result['data']['extracted_transactions'] as $i => $tx) {
                    $txText = $tx['description'] ?? $messageText;
                    $inference = $this->categoryInference->infer($txText);

                    if ($inference['confidence'] >= 0.4) {
                        $oldCategoryType = $tx['category_type'] ?? 'null';
                        $result['data']['extracted_transactions'][$i]['category_type'] = $inference['category_type'];
                        $result['data']['extracted_transactions'][$i]['category'] = $inference['category_name'];
                        $result['data']['extracted_transactions'][$i]['confidence_score'] = $inference['confidence'];
                        $applied++;

                        Log::info('CategoryInference: Applied to transaction (multi)', [
                            'message_id' => $this->message->id,
                            'tx_index' => $i,
                            'old_category_type' => $oldCategoryType,
                            'new_category_type' => $inference['category_type'],
                            'new_category_name' => $inference['category_name'],
                            'confidence' => $inference['confidence'],
                            'source' => $inference['source'],
                        ]);
                    } else {
                        $kept++;
                    }
                }

                Log::info('CategoryInference: Multi-transaction summary', [
                    'message_id' => $this->message->id,
                    'tx_count' => $txCount,
                    'applied' => $applied,
                    'kept' => $kept,
                ]);
            }
        }

        // Remove the old ACARA/HAJATAN CATEGORY FIX block - now handled by CategoryInferenceService
        // Remove the old DONASI CATEGORY FIX block - now handled by CategoryInferenceService

        if (! $result['success']) {
            Log::error('Failed to extract transaction', [
                'message_id' => $this->message->id,
                'error' => $result['error'],
                'message_text_preview' => mb_substr($messageText, 0, 200),
            ]);

            // CHECK KEYWORDS FIRST before sending generic error
            $msgTextLower = strtolower(trim($messageText));
            $incomeKeywordsConfig = config('finwa_category_rules.error_income_keywords', []);
            $expenseKeywordsConfig = config('finwa_category_rules.error_expense_keywords', []);
            $incomePattern = '/\b('.implode('|', $incomeKeywordsConfig).')\b/i';
            $expensePattern = '/\b('.implode('|', $expenseKeywordsConfig).')\b/i';
            $isIncomeKeyword = preg_match($incomePattern, $msgTextLower);
            $isExpenseKeyword = preg_match($expensePattern, $msgTextLower);

            if ($isIncomeKeyword || $isExpenseKeyword) {
                $detectedWord = 'transaksi';
                if ($isIncomeKeyword) {
                    preg_match($incomePattern, $msgTextLower, $matches);
                    $detectedWord = $matches[0] ?? 'pemasukan';
                } else {
                    preg_match($expensePattern, $msgTextLower, $matches);
                    $detectedWord = $matches[0] ?? 'pengeluaran';
                }

                $this->sendReply('Berapa biayanya?');

                // STORE PENDING TRANSACTION for follow-up
                try {
                    $contextService = new ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId());
                    $type = $isIncomeKeyword ? 'income' : 'expense';
                    $contextService->storePendingTransaction($messageText, $detectedWord, $type);
                } catch (\Exception $e) {
                    Log::warning('Failed to store pending transaction', ['error' => $e->getMessage()]);
                }

                return;
            }

            // Send generic error reply if no keywords detected
            $this->sendReply("⚠️ *Format Tidak Dikenali*\n\n".
                "Maaf, saya tidak bisa memahami format pesan Anda.\n\n".
                "📝 *Contoh Format yang Benar:*\n\n".
                "*Pengeluaran:*\n".
                "• _beli kopi 25rb_\n".
                "• _makan siang 50k_\n".
                "• _uang baju 100rb_\n".
                "• _bayar listrik 200k_\n\n".
                "*Pemasukan:*\n".
                "• _gaji bulan ini 5jt_\n".
                "• _dapat bonus 1jt_\n".
                "• _terima transfer 500rb_\n\n".
                "*Tips:*\n".
                "• Gunakan kata kerja: _beli, bayar, uang, dapat, terima_\n".
                "• Format nominal: _25rb, 50k, 1jt, 100.000_\n".
                "• Atau kirim foto struk 📸\n\n".
                'Ketik *help* untuk panduan lengkap!');

            return;
        }

        $data = $result['data'];

        Log::info('Transaction extraction result', [
            'message_id' => $this->message->id,
            'has_transactions' => isset($data['extracted_transactions']) && ! empty($data['extracted_transactions']),
            'transaction_count' => isset($data['extracted_transactions']) ? count($data['extracted_transactions']) : 0,
            'needs_review' => $data['needs_review'] ?? false,
        ]);

        if (! isset($data['extracted_transactions']) || empty($data['extracted_transactions'])) {
            Log::info('No transactions extracted from message', [
                'message_id' => $this->message->id,
                'message_text_preview' => mb_substr($this->message->content, 0, 200),
            ]);

            // Check if this is a simple message that might need amount
            $messageText = strtolower(trim($this->message->content ?? ''));
            $incomeKeywordsConfig = config('finwa_category_rules.error_income_keywords', []);
            $expenseKeywordsConfig = config('finwa_category_rules.error_expense_keywords', []);
            $incomePattern = '/\b('.implode('|', $incomeKeywordsConfig).')\b/i';
            $expensePattern = '/\b('.implode('|', $expenseKeywordsConfig).')\b/i';
            $isIncomeKeyword = preg_match($incomePattern, $messageText);
            $isExpenseKeyword = preg_match($expensePattern, $messageText);

            if ($isIncomeKeyword || $isExpenseKeyword) {
                $type = $isIncomeKeyword ? 'pemasukan' : 'pengeluaran';
                $detectedWord = 'transaksi';
                if ($isIncomeKeyword) {
                    preg_match($incomePattern, $messageText, $matches);
                    $detectedWord = $matches[0] ?? 'pemasukan';
                } else {
                    preg_match($expensePattern, $messageText, $matches);
                    $detectedWord = $matches[0] ?? 'pengeluaran';
                }

                $this->sendReply("💰 *Ingin mencatat '{$detectedWord}'? Berapa biayanya?*\n\n".
                    "Saya lihat Anda ingin mencatat transaksi {$detectedWord}, tapi nominalnya belum ada.\n\n".
                    "💡 *Silakan tulis ulang lengkap dengan harganya:*\n".
                    ($isIncomeKeyword
                        ? "• *{$detectedWord} 500rb*\n• *{$detectedWord} 1 juta*"
                        : "• *{$detectedWord} 15rb*\n• *{$detectedWord} 50.000*"));
            } else {
                // Send reply to user that no transaction was found
                $this->sendReply("⚠️ *Tidak ada transaksi yang terdeteksi*\n\n".
                    "Saya tidak dapat menemukan informasi transaksi dari pesan yang Anda kirim.\n\n".
                    "💡 *Tips:*\n".
                    "• Untuk pemasukan: *gaji 5000000* atau *bonus 1 juta*\n".
                    "• Untuk pengeluaran: *beli bensin 50000* atau *makan siang 25000*\n".
                    '• Atau kirim gambar struk belanja');
            }

            return;
        }

        // Create transactions
        $createdCount = 0;
        $createdTransactions = [];
        foreach ($data['extracted_transactions'] as $txData) {
            // FALLBACK: If account_name is not extracted by AI, try to extract from message
            if (empty($txData['account_name']) || $txData['account_name'] === null) {
                $extractedAccountName = $this->extractAccountNameFromMessage($messageText);
                if ($extractedAccountName) {
                    $txData['account_name'] = $extractedAccountName;
                    Log::info('Account name extracted from message (fallback)', [
                        'message_id' => $this->message->id,
                        'account_name' => $extractedAccountName,
                        'message_text' => mb_substr($messageText, 0, 100),
                    ]);
                }
            }

            $transaction = $this->createTransaction($txData, $data['needs_review'] ?? false);
            if ($transaction) {
                $createdTransactions[] = $transaction;
                $createdCount++;
            } else {
                Log::warning('Failed to create transaction', [
                    'message_id' => $this->message->id,
                    'tx_data' => $txData,
                ]);
            }
        }

        Log::info('Transactions created', [
            'message_id' => $this->message->id,
            'created_count' => $createdCount,
            'total_extracted' => count($data['extracted_transactions']),
        ]);

        // Send confirmation reply
        if ($createdCount > 0) {
            $this->sendTransactionConfirmation($createdTransactions, $data['needs_review'] ?? false);
            Log::info('Transaction confirmation sent', [
                'message_id' => $this->message->id,
                'transaction_count' => $createdCount,
            ]);
        } else {
            Log::warning('No transactions created, sending error reply', [
                'message_id' => $this->message->id,
            ]);
            $this->sendReply("⚠️ *Gagal membuat transaksi*\n\n".
                'Terjadi kesalahan saat menyimpan transaksi. Silakan coba lagi atau hubungi admin.');
        }
    }

    /**
     * Create transaction from extracted data
     *
     * MOVED FROM: ProcessIncomingMessage::createTransaction()
     * LINES: 5200-5423
     * MODIFICATION: Added free plan monthly transaction limit check
     */
    public function createTransaction(array $txData, bool $needsReview = false): ?Transaction
    {
        // DEBUG LOG: Track what category_type is being used
        Log::info('CREATE TRANSACTION: Received data', [
            'message_id' => $this->message->id,
            'category_type' => $txData['category_type'] ?? 'NOT SET',
            'type' => $txData['type'] ?? 'NOT SET',
            'amount' => $txData['amount'] ?? 'NOT SET',
            'description' => $txData['description'] ?? 'NOT SET',
        ]);

        $limitService = app(\App\Services\SubscriptionLimitService::class);
        $limitCheck = $limitService->canCreateTransaction($this->message->tenant_id);

        if (! $limitCheck['can_create']) {
            Log::info('Transaction blocked - monthly limit reached', [
                'tenant_id' => $this->message->tenant_id,
                'current' => $limitCheck['current'],
                'limit' => $limitCheck['limit'],
                'plan' => $limitCheck['plan'],
            ]);

            $this->sendReply(
                "⚠️ *Batas Transaksi Tercapai*\n\n".
                "Anda sudah mencapai batas *{$limitCheck['limit']} transaksi* bulan ini pada paket Gratis.\n\n".
                "📊 Transaksi bulan ini: {$limitCheck['current']}/{$limitCheck['limit']}\n\n".
                "🚀 *Upgrade ke Paket Lengkap* untuk transaksi tanpa batas!\n".
                '👉 '.config('app.url')."/subscriptions\n\n".
                '_Batas akan direset otomatis di awal bulan depan._'
            );

            return null;
        }

        // Find category by type
        $category = Category::where('tenant_id', $this->message->tenant_id)
            ->where('type', $txData['category_type'])
            ->first();

        // SELF-HEALING: Fix Gaji category if it exists but has wrong name/icon (e.g. labeled as Keluarga)
        if ($category && $txData['category_type'] === 'pengeluaran_gaji' && $category->name !== 'Gaji Karyawan') {
            Log::info('Self-healing Gaji category metadata', ['old_name' => $category->name, 'id' => $category->id]);
            try {
                $category->update([
                    'name' => 'Gaji Karyawan',
                    'icon' => '👷',
                    'slug' => 'gaji-karyawan-fixed-'.time(),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to self-heal category', ['error' => $e->getMessage()]);
            }
        }

        if (! $category) {
            Log::warning('Category not found, creating missing category for tenant', [
                'tenant_id' => $this->message->tenant_id,
                'category_type' => $txData['category_type'],
                'transaction_description' => $txData['description'] ?? 'N/A',
            ]);
            
            // Try to create missing category for this tenant
            try {
                $this->createCategoriesForTenant($this->message->tenant_id);
                Log::info('Categories created/repaired for tenant', [
                    'tenant_id' => $this->message->tenant_id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create categories for tenant', [
                    'tenant_id' => $this->message->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Try to find the ORIGINAL category again after creation
            $category = Category::where('tenant_id', $this->message->tenant_id)
                ->where('type', $txData['category_type'])
                ->first();

            // If still not found, use default category
            if (! $category) {
                $defaultCategoryType = $txData['type'] === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
                Log::warning('Original category still not found, falling back to default', [
                    'original_type' => $txData['category_type'],
                    'default_type' => $defaultCategoryType,
                ]);
                $category = Category::where('tenant_id', $this->message->tenant_id)
                    ->where('type', $defaultCategoryType)
                    ->first();
            }
        }

        // SPECIAL HANDLING: If specific category not found (e.g., pengeluaran_gaji)
        // Check if we can create this specific category on the fly
        if (! $category && isset($txData['category_type']) && $txData['category_type'] === 'pengeluaran_gaji') {
            try {
                $category = Category::create([
                    'tenant_id' => $this->message->tenant_id,
                    'type' => 'pengeluaran_gaji',
                    'name' => 'Gaji Karyawan',
                    'slug' => 'gaji-karyawan-'.time(),
                    'description' => 'Pengeluaran untuk pembayaran gaji karyawan/tukang',
                    'icon' => '👷',
                    'color' => '#ef4444',
                    'is_system' => true,
                ]);
                Log::info('Created missing system category on the fly', ['type' => 'pengeluaran_gaji']);
            } catch (\Exception $e) {
                Log::error('Failed to create specific missing category', ['error' => $e->getMessage()]);
            }
        }

        if (! $category) {
            Log::error('Category not found and could not be created', [
                'tenant_id' => $this->message->tenant_id,
                'category_type' => $txData['category_type'],
                'default_type' => $txData['type'] === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya',
            ]);

            return null;
        }

        // Find or create balance account
        $balanceService = app(BalanceService::class);
        $accountName = $txData['account_name'] ?? null;
        $balance = null;

        if ($accountName) {
            Log::info('Account name found in transaction', [
                'tenant_id' => $this->message->tenant_id,
                'account_name' => $accountName,
                'message_id' => $this->message->id,
            ]);

            // Determine account type
            $accountType = $balanceService->determineAccountType($accountName);
            // Don't pass accountType to prevent auto-creation of new wallet
            $balance = $balanceService->findOrCreateBalance(
                $this->message->tenant_id,
                $accountName,
                null  // Don't auto-create, let fallback handle it
            );

            if ($balance) {
                Log::info('Balance found/created for transaction', [
                    'tenant_id' => $this->message->tenant_id,
                    'balance_id' => $balance->id,
                    'account_name' => $balance->account_name,
                    'requested_account_name' => $accountName,
                ]);
            } else {
                // Wallet not found, fallback to default
                Log::warning('Specified wallet not found, falling back to default', [
                    'tenant_id' => $this->message->tenant_id,
                    'requested_wallet' => $accountName,
                ]);

                $balance = $balanceService->getDefaultBalance($this->message->tenant_id);

                // Store warning message for display
                $txData['wallet_not_found_warning'] = $accountName;

                if ($balance) {
                    Log::info('Using default balance as fallback', [
                        'tenant_id' => $this->message->tenant_id,
                        'balance_id' => $balance->id,
                        'account_name' => $balance->account_name,
                    ]);
                }
            }
        } else {
            // Use default balance (kantong utama) if no account specified
            $balance = $balanceService->getDefaultBalance($this->message->tenant_id);

            if ($balance) {
                Log::info('Using default balance (dompet utama) for transaction', [
                    'tenant_id' => $this->message->tenant_id,
                    'balance_id' => $balance->id,
                    'account_name' => $balance->account_name,
                    'is_default' => $balance->is_default,
                ]);
            } else {
                Log::warning('No default balance found, transaction will not be linked to any balance', [
                    'tenant_id' => $this->message->tenant_id,
                ]);
            }
        }

        // Store group info in metadata if from group
        $metadata = is_array($this->message->metadata) ? $this->message->metadata : json_decode($this->message->metadata ?? '{}', true);
        $isGroup = $metadata['is_group'] ?? false;

        $txMetadata = $txData['metadata'] ?? [];

        $debtCategoryTypes = ['pendapatan_hutang', 'pengeluaran_bayar_hutang', 'pengeluaran_piutang', 'pendapatan_terima_piutang'];
        $categoryTypeForDebt = $txData['category_type'] ?? null;
        if (is_string($categoryTypeForDebt) && in_array($categoryTypeForDebt, $debtCategoryTypes, true)) {
            $cp = null;
            if (isset($txMetadata['counterparty']) && is_string($txMetadata['counterparty'])) {
                $cp = trim($txMetadata['counterparty']);
            }
            if ($cp === null || $cp === '') {
                $cp = CounterpartyExtractor::extract($txData['description'] ?? '') ?? '';
                $cp = trim((string) $cp);
            }
            if ($cp !== '') {
                $txMetadata['counterparty'] = $cp;
                $txMetadata['counterparty_normalized'] = mb_strtolower(preg_replace('/\s+/', ' ', $cp));
            }
            $txMetadata['debt_flow'] = match ($categoryTypeForDebt) {
                'pendapatan_hutang' => 'terima_hutang',
                'pengeluaran_bayar_hutang' => 'bayar_hutang',
                'pengeluaran_piutang' => 'keluar_piutang',
                'pendapatan_terima_piutang' => 'terima_piutang',
            };
        }

        if ($isGroup) {
            $txMetadata['group_id'] = $this->message->sender_id;
            $txMetadata['is_group'] = true;
            // Also store the participant (author) who sent it
            $txMetadata['author'] = $metadata['author'] ?? null;
        }

        $transaction = Transaction::create([
            'tenant_id' => $this->message->tenant_id,
            'category_id' => $category->id,
            'message_id' => $this->message->id,
            'balance_id' => $balance?->id,
            'type' => $txData['type'],
            'amount' => $txData['amount'],
            'transaction_date' => $txData['transaction_date'],
            'source' => $txData['source'] ?? null,
            'description' => $txData['description'],
            'confidence_score' => $txData['confidence_score'] ?? 0.5,
            'status' => $needsReview || ($txData['confidence_score'] ?? 0.5) < 0.7 ? 'review' : 'confirmed',
            'metadata' => $txMetadata,
        ]);

        // Log transaction creation for debugging
        Log::info('Transaction created', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'type' => $transaction->type,
            'description' => $transaction->description,
            'confidence_score' => $transaction->confidence_score,
            'message_id' => $this->message->id,
        ]);

        // CONTEXT MEMORY: Store last transaction ID for context-based edit/correction
        try {
            $contextService = new ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId());
            $contextService->storeLastTransactionId($transaction->id);
        } catch (\Exception $e) {
            Log::warning('Failed to store last transaction ID in context', ['error' => $e->getMessage()]);
        }

        // Auto-update balance if transaction is confirmed and has balance
        if ($transaction->status === 'confirmed' && $transaction->balance_id) {
            $balanceService->updateBalanceFromTransaction($transaction);
        }

        // Check budget alert for expenses
        if ($transaction->type === 'expense' && $transaction->status === 'confirmed') {
            $budgetAlert = $this->checkBudgetAlert($transaction);
            if ($budgetAlert) {
                $transaction->budget_alert = $budgetAlert;
            }
        }

        // Check and award achievements
        if ($transaction->status === 'confirmed') {
            try {
                $achievementService = new AchievementService($this->message->tenant_id);
                $newAchievements = $achievementService->checkAfterTransaction();

                // Store new achievements for notification
                if (! empty($newAchievements)) {
                    $transaction->new_achievements = $newAchievements;
                }
            } catch (\Exception $e) {
                Log::warning('Error checking achievements', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Add wallet warning as temporary attribute if exists
        if (isset($txData['wallet_not_found_warning'])) {
            $transaction->wallet_not_found_warning = $txData['wallet_not_found_warning'];
        }

        return $transaction;
    }

    /**
     * Create transaction from standardized data array
     *
     * MOVED FROM: ProcessIncomingMessage::createTransactionFromData()
     * LINES: 1737-1816
     * MODIFICATION: Added free plan monthly transaction limit check
     */
    public function createTransactionFromData(array $data): void
    {
        $limitService = app(\App\Services\SubscriptionLimitService::class);
        $limitCheck = $limitService->canCreateTransaction($this->message->tenant_id);

        if (! $limitCheck['can_create']) {
            Log::info('Transaction blocked (fromData) - monthly limit reached', [
                'tenant_id' => $this->message->tenant_id,
                'current' => $limitCheck['current'],
                'limit' => $limitCheck['limit'],
                'plan' => $limitCheck['plan'],
            ]);

            $this->sendReply(
                "⚠️ *Batas Transaksi Tercapai*\n\n".
                "Anda sudah mencapai batas *{$limitCheck['limit']} transaksi* bulan ini pada paket Gratis.\n\n".
                "📊 Transaksi bulan ini: {$limitCheck['current']}/{$limitCheck['limit']}\n\n".
                "🚀 *Upgrade ke Paket Lengkap* untuk transaksi tanpa batas!\n".
                '👉 '.config('app.url')."/subscriptions\n\n".
                '_Batas akan direset otomatis di awal bulan depan._'
            );

            return;
        }

        $rawAmount = $data['amount'];
        $type = $data['type']; // 'income' or 'expense'
        $categoryType = $data['category_type'];
        $description = $data['description'];

        // 1. Determine Wallet
        $balanceService = app(BalanceService::class);
        $accountName = $data['account_name'] ?? null;

        $balance = $balanceService->findOrCreateBalance(
            $this->message->tenant_id,
            $accountName,
            null // Don't auto-create new wallet types, fallback to default
        );

        if (! $balance) {
            $balance = $balanceService->getDefaultBalance($this->message->tenant_id);

            if ($accountName) {
                $description .= "\n⚠️ Dompet {$accountName} tidak ditemukan, menggunakan dompet default";
            }
        }

        // 2. Create Transaction
        $transaction = new Transaction;
        $transaction->tenant_id = $this->message->tenant_id;
        $transaction->balance_id = $balance->id;
        $transaction->amount = $rawAmount;
        $transaction->type = $type;
        $transaction->category = $categoryType; // Use category_type for DB standard
        $transaction->description = $description;
        $transaction->transaction_date = $data['transaction_date'] ?? now()->toDateString();
        $transaction->save();

        // 3. Update Balance (column is `balance`, not current_balance)
        if ($type === 'income') {
            $balance->balance += $rawAmount;
        } else {
            $balance->balance -= $rawAmount;
        }
        $balance->save();

        // 4. Send Confirmation
        $formattedAmount = 'Rp '.number_format($rawAmount, 0, ',', '.');
        $formattedBalance = 'Rp '.number_format((float) $balance->balance, 0, ',', '.');

        $emoji = $type === 'income' ? '💰' : '💸';
        $typeText = $type === 'income' ? 'Pemasukan' : 'Pengeluaran';

        // Category Label
        $categoryLabel = ucwords(str_replace('_', ' ', str_replace(['pengeluaran_', 'pendapatan_'], '', $categoryType)));

        // Date info if backdated
        $txDate = $data['transaction_date'] ?? now()->toDateString();
        $isBackdated = $txDate !== now()->toDateString();
        $dateInfo = $isBackdated ? "\n📅 Tanggal: ".\Carbon\Carbon::parse($txDate)->translatedFormat('d F Y') : '';

        $reply = "✅ Siap, Sudah Masuk! ✅\n\n".
            "{$emoji} {$typeText}\n".
            "💵 {$formattedAmount}\n".
            "{$categoryLabel} • {$description}".
            $dateInfo."\n".
            "👛 Sisa saldo {$balance->account_name}: {$formattedBalance}";

        $this->sendReply($reply);
    }

    /**
     * Process transfer after clarification response
     *
     * MOVED FROM: ProcessIncomingMessage::processTransferWithClarification()
     * LINES: 1703-1735
     * MODIFICATION: None (structural move only)
     */
    public function processTransferWithClarification(float $amount, bool $isIncome): void
    {
        Log::info('Processing transfer with clarification', [
            'message_id' => $this->message->id,
            'amount' => $amount,
            'is_income' => $isIncome,
        ]);

        $type = $isIncome ? 'income' : 'expense';
        $category = $isIncome ? 'pendapatan_lainnya' : 'transfer';
        $categoryType = $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
        $description = $isIncome ? 'Terima transfer Rp '.number_format($amount, 0, ',', '.')
                                 : 'Kirim transfer Rp '.number_format($amount, 0, ',', '.');

        // Create transaction data
        $txData = [
            'type' => $type,
            'amount' => $amount,
            'category' => $category,
            'category_type' => $categoryType,
            'description' => $description." [LocalType:{$categoryType}]", // Add debug info
            'account_name' => null,
            'transaction_date' => now()->toDateString(),
            'confidence_score' => 0.95,
            'source' => 'clarification_response',
        ];

        // Create the transaction
        $this->createTransactionFromData($txData);
    }

    /**
     * Handle delete transaction request (hapus transaksi terakhir)
     *
     * MOVED FROM: ProcessIncomingMessage::handleDeleteTransaction()
     * LINES: 1935-2001
     * MODIFICATION: None (structural move only)
     */
    public function handleDeleteTransaction(): void
    {
        try {
            // Find last transaction for this tenant
            $lastTransaction = Transaction::where('tenant_id', $this->message->tenant_id)
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $lastTransaction) {
                $this->sendReply(
                    "⚠️ *Tidak ada transaksi untuk dihapus*\n\n".
                    'Anda belum memiliki transaksi yang tercatat.'
                );

                return;
            }

            // Load category
            $lastTransaction->load('category');

            // Format transaction info
            $typeEmoji = $lastTransaction->type === 'income' ? '💰' : '💸';
            $typeLabel = $lastTransaction->type === 'income' ? 'Pemasukan' : 'Pengeluaran';
            $amount = number_format($lastTransaction->amount, 0, ',', '.');
            $category = $lastTransaction->category->name ?? 'Lainnya';
            $desc = $lastTransaction->description ?? '-';
            $date = $lastTransaction->transaction_date->format('d M Y');

            // Update balance - ALWAYS reverse the transaction amount
            $balanceService = app(BalanceService::class);

            if ($lastTransaction->balance_id) {
                // Transaction has linked balance - use it
                $balanceService->reverseBalanceUpdate($lastTransaction);
            } else {
                // Transaction has no linked balance - use default wallet
                $defaultWallet = Balance::where('tenant_id', $this->message->tenant_id)
                    ->where('is_default', true)
                    ->where('is_active', true)
                    ->first();

                if ($defaultWallet) {
                    // Manually reverse the balance
                    if ($lastTransaction->type === 'income') {
                        $defaultWallet->balance -= $lastTransaction->amount;
                    } else {
                        $defaultWallet->balance += $lastTransaction->amount;
                    }
                    $defaultWallet->save();

                    Log::info('Balance reversed using default wallet (no balance_id)', [
                        'wallet_id' => $defaultWallet->id,
                        'wallet_name' => $defaultWallet->account_name,
                        'transaction_id' => $lastTransaction->id,
                        'amount' => $lastTransaction->amount,
                        'type' => $lastTransaction->type,
                        'new_balance' => $defaultWallet->balance,
                    ]);
                }
            }

            // Delete the transaction
            $lastTransaction->delete();

            Log::info('Transaction deleted via chat', [
                'message_id' => $this->message->id,
                'transaction_id' => $lastTransaction->id,
                'amount' => $lastTransaction->amount,
            ]);

            $this->sendReply(
                "🗑️ *Transaksi Dihapus!*\n\n".
                "{$typeEmoji} *{$typeLabel}*\n".
                "💵 Rp {$amount}\n".
                "📁 {$category}\n".
                "📝 {$desc}\n".
                "📅 {$date}\n\n".
                '✅ Transaksi berhasil dihapus dari catatan Anda.'
            );

        } catch (\Exception $e) {
            Log::error('Error deleting transaction', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal menghapus transaksi*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle delete transaction by keyword/description
     * Example: "hapus beli kue", "hapus transaksi makan siang"
     */
    public function handleDeleteTransactionByKeyword(string $messageText): void
    {
        try {
            // Handle multi-line messages - only process first line
            $lines = preg_split('/[\r\n]+/', trim($messageText));
            $firstLine = $lines[0] ?? $messageText;

            // Extract keyword from message
            // Pattern: "hapus [transaksi] keyword"
            $keyword = preg_replace('/^(hapus|delete|batal|batalkan)\s+(transaksi\s+)?/i', '', trim($firstLine));
            $keyword = trim($keyword);

            if (empty($keyword) || strlen($keyword) < 3) {
                $this->sendReply(
                    "⚠️ *Keyword tidak valid*\n\n".
                    "Silakan sebutkan transaksi yang ingin dihapus.\n\n".
                    "Contoh:\n".
                    "• _hapus beli kue_\n".
                    "• _hapus makan siang_\n".
                    '• _hapus transaksi bensin_'
                );

                return;
            }

            // Search for matching transaction today only
            $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
                ->where('description', 'LIKE', '%'.$keyword.'%')
                ->whereDate('transaction_date', today())
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $transaction) {
                // Try case-insensitive search with lower()
                $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
                    ->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($keyword).'%'])
                    ->whereDate('transaction_date', today())
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            if (! $transaction) {
                $this->sendReply(
                    "⚠️ *Transaksi tidak ditemukan*\n\n".
                    "Tidak ada transaksi dengan keyword \"*{$keyword}*\" hari ini.\n\n".
                    '💡 Ketik _lihat transaksi_ untuk melihat daftar transaksi Anda.'
                );

                return;
            }

            // Load category
            $transaction->load('category');

            // Format transaction info
            $typeEmoji = match ($transaction->type) {
                'income' => '💰',
                'expense' => '💸',
                'debit_internal', 'kredit_internal' => '🔄',
                default => '📝'
            };
            $typeLabel = match ($transaction->type) {
                'income' => 'Pemasukan',
                'expense' => 'Pengeluaran',
                'debit_internal', 'kredit_internal' => 'Transfer Internal',
                default => 'Transaksi'
            };
            $amount = number_format($transaction->amount, 0, ',', '.');
            $category = $transaction->category->name ?? 'Lainnya';
            $desc = $transaction->description ?? '-';
            $date = $transaction->transaction_date->format('d M Y');

            // Update balance - reverse the transaction amount
            $balanceService = app(BalanceService::class);

            if ($transaction->balance_id) {
                $balanceService->reverseBalanceUpdate($transaction);
            } else {
                $defaultWallet = Balance::where('tenant_id', $this->message->tenant_id)
                    ->where('is_default', true)
                    ->where('is_active', true)
                    ->first();

                if ($defaultWallet) {
                    if ($transaction->type === 'income') {
                        $defaultWallet->balance -= $transaction->amount;
                    } else {
                        $defaultWallet->balance += $transaction->amount;
                    }
                    $defaultWallet->save();
                }
            }

            // Delete the transaction
            $transaction->delete();

            Log::info('Transaction deleted by keyword via chat', [
                'message_id' => $this->message->id,
                'transaction_id' => $transaction->id,
                'keyword' => $keyword,
                'amount' => $transaction->amount,
            ]);

            $this->sendReply(
                "🗑️ *Transaksi Dihapus!*\n\n".
                "{$typeEmoji} *{$typeLabel}*\n".
                "💵 Rp {$amount}\n".
                "📁 {$category}\n".
                "📝 {$desc}\n".
                "📅 {$date}\n\n".
                '✅ Transaksi berhasil dihapus.'
            );

        } catch (\Exception $e) {
            Log::error('Error deleting transaction by keyword', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal menghapus transaksi*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
            );
        }
    }

    /**
     * Handle edit transaction by keyword/description
     * Example: "ubah beli kue jadi 25rb", "edit makan siang 30rb"
     */
    public function handleEditTransactionByKeyword(string $messageText): void
    {
        try {
            // Handle multi-line messages - only process first line
            $lines = preg_split('/[\r\n]+/', trim($messageText));
            $firstLine = $lines[0] ?? $messageText;

            // Extract keyword and new amount from message
            // Pattern: "ubah/edit [transaksi] keyword jadi/ke newAmount"
            $pattern = '/^(ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?(.+?)\s+(?:jadi|ke|menjadi)\s+(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?/i';

            if (! preg_match($pattern, trim($firstLine), $matches)) {
                // Try simpler pattern: "ubah keyword 25rb"
                $pattern2 = '/^(ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?(.+?)\s+(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?$/i';
                if (! preg_match($pattern2, trim($firstLine), $matches)) {

                    $this->sendReply(
                        "⚠️ *Format tidak valid*\n\n".
                        "Untuk mengedit transaksi spesifik:\n\n".
                        "Contoh:\n".
                        "• _ubah beli kue jadi 25rb_\n".
                        "• _edit makan siang 30rb_\n".
                        "• _koreksi bensin ke 50rb_\n\n".
                        '💡 Atau ketik _lihat transaksi_ untuk melihat daftar.'
                    );

                    return;
                }
            }

            $keyword = trim($matches[2]);
            $numValue = floatval(str_replace(',', '.', $matches[3]));
            $suffix = strtolower($matches[4] ?? '');

            // Calculate new amount
            $multipliers = [
                'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                'jt' => 1000000, 'juta' => 1000000,
            ];
            $multiplier = $multipliers[$suffix] ?? 1;
            $newAmount = (int) ($numValue * $multiplier);

            if ($newAmount <= 0) {
                $this->sendReply('⚠️ Nominal tidak valid. Silakan coba lagi.');

                return;
            }

            // Search for matching transaction today only
            $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
                ->where('description', 'LIKE', '%'.$keyword.'%')
                ->whereDate('transaction_date', today())
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $transaction) {
                $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
                    ->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($keyword).'%'])
                    ->whereDate('transaction_date', today())
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            if (! $transaction) {
                $this->sendReply(
                    "⚠️ *Transaksi tidak ditemukan*\n\n".
                    "Tidak ada transaksi dengan keyword \"*{$keyword}*\" hari ini.\n\n".
                    '💡 Ketik _lihat transaksi_ untuk melihat daftar transaksi Anda.'
                );

                return;
            }

            // Load category
            $transaction->load('category');

            // Store old values
            $oldAmount = $transaction->amount;
            $typeEmoji = match ($transaction->type) {
                'income' => '💰',
                'expense' => '💸',
                'debit_internal', 'kredit_internal' => '🔄',
                default => '📝'
            };
            $typeLabel = match ($transaction->type) {
                'income' => 'Pemasukan',
                'expense' => 'Pengeluaran',
                'debit_internal', 'kredit_internal' => 'Transfer Internal',
                default => 'Transaksi'
            };
            $category = $transaction->category->name ?? 'Lainnya';
            $desc = $transaction->description ?? '-';

            // Update balance
            $balanceService = app(BalanceService::class);
            $amountDiff = $newAmount - $oldAmount;

            if ($transaction->balance_id) {
                $balance = Balance::find($transaction->balance_id);
                if ($balance) {
                    if ($transaction->type === 'income') {
                        $balance->balance += $amountDiff;
                    } else {
                        $balance->balance -= $amountDiff;
                    }
                    $balance->save();
                }
            }

            // Update transaction
            $transaction->amount = $newAmount;
            $transaction->save();

            Log::info('Transaction edited by keyword via chat', [
                'message_id' => $this->message->id,
                'transaction_id' => $transaction->id,
                'keyword' => $keyword,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
            ]);

            $oldFormatted = number_format($oldAmount, 0, ',', '.');
            $newFormatted = number_format($newAmount, 0, ',', '.');

            $this->sendReply(
                "✏️ *Transaksi Diubah!*\n\n".
                "{$typeEmoji} *{$typeLabel}*\n".
                "📝 {$desc}\n".
                "📁 {$category}\n\n".
                "💵 Nominal:\n".
                "   Rp {$oldFormatted} → *Rp {$newFormatted}*\n\n".
                '✅ Perubahan berhasil disimpan.'
            );

        } catch (\Exception $e) {
            Log::error('Error editing transaction by keyword', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mengubah transaksi*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
            );
        }
    }

    /**
     * Handle view transactions request (lihat transaksi hari ini)
     *
     * MOVED FROM: ProcessIncomingMessage::handleViewTransactions()
     * LINES: 2003-2151
     * MODIFICATION: None (structural move only)
     */
    public function handleViewTransactions(): void
    {
        try {
            // Get today's date
            $today = now()->toDateString();

            // First, try to get today's transactions
            $todayTransactions = Transaction::where('tenant_id', $this->message->tenant_id)
                ->whereDate('transaction_date', $today)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // If no transactions today, get recent transactions (last 7 days)
            if ($todayTransactions->isEmpty()) {
                $weekAgo = now()->subDays(7)->toDateString();
                $recentTransactions = Transaction::where('tenant_id', $this->message->tenant_id)
                    ->whereDate('transaction_date', '>=', $weekAgo)
                    ->orderBy('transaction_date', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get();

                if ($recentTransactions->isEmpty()) {
                    $this->sendReply(
                        "📋 *Daftar Transaksi*\n\n".
                        "Belum ada transaksi tercatat dalam 7 hari terakhir.\n\n".
                        "💡 Mulai catat dengan:\n".
                        "• _\"makan siang 25rb\"_\n".
                        '• _"gaji bulan ini 5jt"_'
                    );

                    return;
                }

                // Show recent transactions
                $reply = "📋 *Transaksi 7 Hari Terakhir*\n";
                $reply .= "━━━━━━━━━━━━━━━\n\n";

                $totalIncome = 0;
                $totalExpense = 0;
                $num = 1;

                foreach ($recentTransactions as $tx) {
                    $tx->load('category');
                    $typeEmoji = match ($tx->type) {
                        'income' => '💰',
                        'expense' => '💸',
                        'debit_internal', 'kredit_internal' => '🔄',
                        default => '📝'
                    };
                    $amount = number_format($tx->amount, 0, ',', '.');
                    $category = $tx->category->name ?? 'Lainnya';
                    $date = \Carbon\Carbon::parse($tx->transaction_date)->translatedFormat('d M');
                    $desc = $tx->description ?? '';
                    // Clean description - remove amount patterns that might be duplicated
                    $desc = preg_replace('/\s*\d+[.,]?\d*\s*$/i', '', $desc);
                    $desc = trim($desc);

                    if ($tx->type === 'income') {
                        $totalIncome += $tx->amount;
                    } elseif ($tx->type === 'expense') {
                        $totalExpense += $tx->amount;
                    }

                    $reply .= "*{$num}.* {$typeEmoji} *Rp {$amount}*\n";
                    $reply .= "    📁 {$category}\n";
                    $reply .= "    📅 {$date}\n";
                    if ($desc) {
                        $reply .= "    📝 {$desc}\n";
                    }
                    $reply .= "\n";
                    $num++;
                }

                $reply .= "━━━━━━━━━━━━━━━\n";
                if ($totalIncome > 0) {
                    $reply .= '💰 Total Masuk: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
                }
                if ($totalExpense > 0) {
                    $reply .= '💸 Total Keluar: Rp '.number_format($totalExpense, 0, ',', '.')."\n";
                }

                $this->sendReply($reply);

                return;
            }

            // Show today's transactions
            $reply = "📋 *Transaksi Hari Ini*\n";
            $reply .= '📅 '.now()->translatedFormat('l, d F Y')."\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";

            $totalIncome = 0;
            $totalExpense = 0;
            $num = 1;

            foreach ($todayTransactions as $tx) {
                $tx->load('category');
                $typeEmoji = match ($tx->type) {
                    'income' => '💰',
                    'expense' => '💸',
                    'debit_internal', 'kredit_internal' => '🔄',
                    default => '📝'
                };
                $amount = number_format($tx->amount, 0, ',', '.');
                $category = $tx->category->name ?? 'Lainnya';
                $time = $tx->created_at ? $tx->created_at->format('H:i') : '-';
                $desc = $tx->description ?? '';
                // Clean description - remove amount patterns that might be duplicated
                $desc = preg_replace('/\s*\d+[.,]?\d*\s*$/i', '', $desc);
                $desc = trim($desc);

                if ($tx->type === 'income') {
                    $totalIncome += $tx->amount;
                } elseif ($tx->type === 'expense') {
                    $totalExpense += $tx->amount;
                }

                $reply .= "*{$num}.* {$typeEmoji} *Rp {$amount}*\n";
                $reply .= "    📁 {$category}\n";
                $reply .= "    ⏰ {$time}\n";
                if ($desc) {
                    $reply .= "    📝 {$desc}\n";
                }
                $reply .= "\n";
                $num++;
            }

            $reply .= "━━━━━━━━━━━━━━━\n";
            if ($totalIncome > 0) {
                $reply .= '💰 Total Masuk: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
            }
            if ($totalExpense > 0) {
                $reply .= '💸 Total Keluar: Rp '.number_format($totalExpense, 0, ',', '.')."\n";
            }

            $net = $totalIncome - $totalExpense;
            if ($net != 0) {
                $netEmoji = $net > 0 ? '📈' : '📉';
                $netLabel = $net > 0 ? 'Surplus' : 'Defisit';
                $reply .= "{$netEmoji} {$netLabel}: Rp ".number_format(abs($net), 0, ',', '.');
            }

            $this->sendReply($reply);

        } catch (\Exception $e) {
            Log::error('Error viewing transactions', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal memuat transaksi*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle edit transaction request (edit transaksi terakhir)
     * Supports: "edit jadi 50rb", "ubah ke 100rb", "ganti nominal 75rb", "edit tanggal 11 des 2025"
     *
     * MOVED FROM: ProcessIncomingMessage::handleEditTransaction()
     * LINES: 2153-2348
     * MODIFICATION: None (structural move only)
     */
    public function handleEditTransaction(string $messageText, ?array $finwaEntities = null): void
    {
        try {
            // Find last transaction for this tenant
            $lastTransaction = Transaction::where('tenant_id', $this->message->tenant_id)
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $lastTransaction) {
                $this->sendReply(
                    "⚠️ *Tidak ada transaksi untuk diedit*\n\n".
                    'Anda belum memiliki transaksi yang tercatat.'
                );

                return;
            }

            // Load category
            $lastTransaction->load('category');

            // Get old values
            $oldAmount = $lastTransaction->amount;
            $oldCategory = $lastTransaction->category->name ?? 'Lainnya';
            $oldDesc = $lastTransaction->description ?? '-';
            $oldDate = $lastTransaction->transaction_date->format('d M Y');

            // Track what was changed
            $changes = [];

            // First, check if this is specifically a DATE edit request
            // Pattern: "edit tanggal ...", "ubah tgl ...", etc.
            $isDateEditRequest = preg_match('/(?:edit|ubah|ganti)\s*[,]?\s*(?:tanggal|tgl|date)\s/i', $messageText);

            // Check if new amount is provided (from FinWa entities or text parsing)
            // SKIP amount extraction if this is a date edit request to avoid year being interpreted as amount
            $newAmount = null;
            if (! $isDateEditRequest) {
                if ($finwaEntities && isset($finwaEntities['nominal']) && $finwaEntities['nominal'] > 0) {
                    $newAmount = $finwaEntities['nominal'];
                } else {
                    // Try to extract amount from message text
                    // Pattern: "edit jadi 50rb", "ubah ke 100rb", "ganti 75rb"
                    if (preg_match('/(?:jadi|ke|ganti|ubah|edit)\s*(?:nominal\s*)?(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)/i', $messageText, $matches)) {
                        $num = floatval(str_replace(',', '.', $matches[1]));
                        $suffix = strtolower($matches[2] ?? '');

                        $multipliers = [
                            'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                            'jt' => 1000000, 'juta' => 1000000,
                        ];

                        $multiplier = $multipliers[$suffix] ?? 1;
                        $newAmount = (int) ($num * $multiplier);
                    }
                }
            }

            // Check for category change
            $newCategory = null;

            // 1. Try to extract category name from text
            if (preg_match('/(?:kategori|masuk|pindah(?:in)?|ubah|ganti)\s+(?:ke\s+|jadi\s+)?([a-zA-Z\s]+)/i', $messageText, $catMatches)) {
                $candidate = trim($catMatches[1]);
                $candidate = preg_replace('/^(jadi|ke)\s+/i', '', $candidate);

                // Avoid capturing date/amount keywords
                if (! preg_match('/^(\d+|rp|rupiah|tanggal|tgl|harga|nominal)/i', $candidate) && strlen($candidate) > 2) {
                    // Try to find category by name or slug
                    $newCategory = Category::where('tenant_id', $this->message->tenant_id)
                        ->where(function ($q) use ($candidate) {
                            $q->where('name', 'LIKE', '%'.$candidate.'%')
                                ->orWhere('slug', 'LIKE', '%'.$candidate.'%');
                        })
                        ->first();
                }
            }

            // 2. Fallback to common keywords matched to Name
            if (! $newCategory) {
                $categoryPatterns = [
                    'makan' => 'Makanan', 'transport' => 'Transport', 'belanja' => 'Belanja',
                    'keluarga' => 'Keluarga', 'kesehatan' => 'Kesehatan', 'hiburan' => 'Hiburan',
                    'tagihan' => 'Utilitas', 'gaji' => 'Gaji', 'bonus' => 'Bonus',
                ];

                foreach ($categoryPatterns as $keyword => $catNamePartial) {
                    if (preg_match('/(?:kategori|ke)\s+(?:jadi\s+)?'.$keyword.'/i', $messageText)) {
                        $newCategory = Category::where('tenant_id', $this->message->tenant_id)
                            ->where('name', 'LIKE', '%'.$catNamePartial.'%')
                            ->first();
                        if ($newCategory) {
                            break;
                        }
                    }
                }
            }

            // Check for date change
            // Pattern: "edit tanggal 11 des 2025", "ubah tanggal kemarin", "ganti tanggal 11/12/2025"
            $newDate = null;
            if (preg_match('/(?:tanggal|tgl|date)\s*[,:]?\s*(.+)/i', $messageText, $dateMatch)) {
                $dateText = trim($dateMatch[1]);
                $parsedDate = $this->parseDateFromHeader($dateText);

                // Validate parsed date is not today (default fallback) unless explicitly stated
                $textLower = strtolower($dateText);
                if ($parsedDate !== now()->toDateString() || str_contains($textLower, 'hari ini')) {
                    $newDate = $parsedDate;
                } elseif (str_contains($textLower, 'kemarin')) {
                    $newDate = now()->subDay()->toDateString();
                }
            }

            // Apply changes
            if ($newAmount && $newAmount != $oldAmount) {
                $lastTransaction->amount = $newAmount;
                $changes[] = 'Nominal: Rp '.number_format($oldAmount, 0, ',', '.').' → Rp '.number_format($newAmount, 0, ',', '.');
            }

            if ($newCategory && $newCategory->id != $lastTransaction->category_id) {
                $lastTransaction->category_id = $newCategory->id;
                $changes[] = "Kategori: {$oldCategory} → {$newCategory->name}";
            }

            if ($newDate && $newDate !== $lastTransaction->transaction_date->toDateString()) {
                $lastTransaction->transaction_date = $newDate;
                $newDateFormatted = \Carbon\Carbon::parse($newDate)->format('d M Y');
                $changes[] = "Tanggal: {$oldDate} → {$newDateFormatted}";
            }

            // If nothing to change, show current transaction info
            if (empty($changes)) {
                $typeEmoji = match ($lastTransaction->type) {
                    'income' => '💰',
                    'expense' => '💸',
                    'debit_internal', 'kredit_internal' => '🔄',
                    default => '📝'
                };
                $amount = number_format($lastTransaction->amount, 0, ',', '.');
                $date = $lastTransaction->transaction_date->format('d M Y');

                $this->sendReply(
                    "✏️ *Edit Transaksi*\n\n".
                    "Transaksi terakhir Anda:\n\n".
                    "{$typeEmoji} *Rp {$amount}*\n".
                    "📁 {$oldCategory}\n".
                    "📝 {$oldDesc}\n".
                    "📅 {$date}\n\n".
                    "━━━━━━━━━━━━━━━\n\n".
                    "*Cara edit:*\n".
                    "• _edit jadi 50rb_ (ubah nominal)\n".
                    "• _ubah ke 100k_ (ubah nominal)\n".
                    "• _ganti kategori makan_ (ubah kategori)\n".
                    "• _edit tanggal 11 des 2025_ (ubah tanggal)\n".
                    "• _ubah tanggal kemarin_ (ubah tanggal)\n\n".
                    'Ketik perintah edit yang diinginkan!'
                );

                return;
            }

            // Save changes
            $lastTransaction->save();

            if ($newCategory && $newCategory->id != $lastTransaction->getOriginal('category_id')) {
                $correctionService = app(CategoryCorrectionService::class);
                $correctionService->recordCorrection(
                    $this->message->tenant_id,
                    $lastTransaction->description ?? '',
                    $oldCategory,
                    $newCategory->name,
                    $lastTransaction->merchant,
                    $lastTransaction->amount
                );
            }

            // Update balance if amount changed
            if ($newAmount !== null && $newAmount != $oldAmount) {
                $diff = $newAmount - $oldAmount;

                if ($lastTransaction->balance_id) {
                    // Transaction has linked balance - use it
                    $balance = Balance::find($lastTransaction->balance_id);
                    if ($balance) {
                        if ($lastTransaction->type === 'expense') {
                            $balance->balance -= $diff; // More expense = less balance
                        } else {
                            $balance->balance += $diff; // More income = more balance
                        }
                        $balance->save();

                        Log::info('Balance updated after edit (linked wallet)', [
                            'wallet' => $balance->account_name,
                            'diff' => $diff,
                            'new_balance' => $balance->balance,
                        ]);
                    }
                } else {
                    // Transaction has no linked balance - use default wallet
                    $defaultWallet = Balance::where('tenant_id', $this->message->tenant_id)
                        ->where('is_default', true)
                        ->where('is_active', true)
                        ->first();

                    if ($defaultWallet) {
                        if ($lastTransaction->type === 'expense') {
                            $defaultWallet->balance -= $diff;
                        } else {
                            $defaultWallet->balance += $diff;
                        }
                        $defaultWallet->save();

                        Log::info('Balance updated after edit (default wallet)', [
                            'wallet' => $defaultWallet->account_name,
                            'diff' => $diff,
                            'new_balance' => $defaultWallet->balance,
                        ]);
                    }
                }
            }

            // Check Budget Alert
            $budgetAlertMsg = '';
            if ($lastTransaction->type === 'expense') {
                $alert = $this->checkBudgetAlert($lastTransaction);
                if ($alert) {
                    $budgetAlertMsg = $alert;
                }
            }

            Log::info('Transaction edited via chat', [
                'message_id' => $this->message->id,
                'transaction_id' => $lastTransaction->id,
                'changes' => $changes,
            ]);

            // Reload to get new category name
            $lastTransaction->load('category');
            $newCategoryName = $lastTransaction->category->name ?? 'Lainnya';

            $reply = "✅ *Transaksi Berhasil Diedit!*\n\n";
            $reply .= "*Perubahan:*\n";
            foreach ($changes as $change) {
                $reply .= "• {$change}\n";
            }
            $reply .= "\n";

            $typeEmoji = $lastTransaction->type === 'income' ? '💰' : '💸';
            $amount = number_format($lastTransaction->amount, 0, ',', '.');
            $updatedDate = $lastTransaction->transaction_date->format('d M Y');
            $reply .= "*Transaksi sekarang:*\n";
            $reply .= "{$typeEmoji} Rp {$amount}\n";
            $reply .= "📁 {$newCategoryName}\n";
            $reply .= "📝 {$lastTransaction->description}\n";
            $reply .= "📅 {$updatedDate}";
            $reply .= $budgetAlertMsg;

            $this->sendReply($reply);

        } catch (\Exception $e) {
            Log::error('Error editing transaction', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mengedit transaksi*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle delete all transactions (keeps wallets and reminders)
     *
     * MOVED FROM: ProcessIncomingMessage::handleDeleteAllTransactions()
     * LINES: 2793-2870
     * MODIFICATION: None (structural move only)
     */
    public function handleDeleteAllTransactions(string $messageText): void
    {
        try {
            $textLower = strtolower($messageText);

            // Check if user confirmed with specific keyword
            $isConfirmed = str_contains($textLower, 'konfirmasi hapus transaksi') ||
                           str_contains($textLower, 'confirm delete') ||
                           str_contains($textLower, 'ya hapus semua transaksi');

            if (! $isConfirmed) {
                // Show warning and ask for confirmation
                $this->sendReply(
                    "⚠️ *PERINGATAN: Hapus Semua Transaksi*\n\n".
                    "Anda akan menghapus SEMUA transaksi:\n".
                    "• Semua pemasukan\n".
                    "• Semua pengeluaran\n\n".
                    "📝 Rekening/dompet dan pengingat akan tetap ada.\n\n".
                    "🚨 *Tindakan ini TIDAK DAPAT dibatalkan!*\n\n".
                    "Untuk melanjutkan, ketik:\n".
                    "*KONFIRMASI HAPUS TRANSAKSI*\n\n".
                    'Untuk membatalkan, abaikan pesan ini.'
                );

                return;
            }

            // User confirmed - proceed with deletion
            $tenantId = $this->message->tenant_id;

            // Count transactions before deletion
            $transactionCount = Transaction::where('tenant_id', $tenantId)->count();
            $incomeCount = Transaction::where('tenant_id', $tenantId)->where('type', 'income')->count();
            $expenseCount = Transaction::where('tenant_id', $tenantId)->where('type', 'expense')->count();

            // Delete all transactions only
            Transaction::where('tenant_id', $tenantId)->delete();

            // RESET ALL WALLET BALANCES TO 0
            // Crucial to prevent stranded balance (GHOST MONEY)
            Balance::where('tenant_id', $tenantId)->update(['balance' => 0]);

            Log::warning('All transactions deleted via WhatsApp', [
                'message_id' => $this->message->id,
                'tenant_id' => $tenantId,
                'transactions_deleted' => $transactionCount,
                'income_deleted' => $incomeCount,
                'expense_deleted' => $expenseCount,
            ]);

            $this->sendReply(
                "🗑️ *Akun Berhasil Di-Reset!*\n\n".
                "Data yang dihapus:\n".
                "• {$incomeCount} pemasukan\n".
                "• {$expenseCount} pengeluaran\n".
                "• Total: {$transactionCount} transaksi\n\n".
                "✅ Semua transaksi sudah dihapus!\n".
                "✅ Semua saldo dompet di-reset ke 0!\n\n".
                "📝 Rekening dan pengingat Anda masih tersimpan.\n\n".
                "💡 Mulai catat transaksi baru:\n".
                "• _gaji 5jt_\n".
                '• _makan siang 25rb_'
            );

        } catch (\Exception $e) {
            Log::error('Error deleting all transactions', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal menghapus transaksi*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle edit transaction with context
     * Allows users to correct their last transaction using "salah harusnya 50rb"
     *
     * MOVED FROM: ProcessIncomingMessage::handleEditWithContext()
     * LINES: 6255-6351
     * MODIFICATION: None (structural move only)
     */
    public function handleEditWithContext(string $messageText): void
    {
        try {
            $contextService = new ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId());
            $lastTransactionId = $contextService->getLastTransactionId();

            if (! $lastTransactionId) {
                $this->sendReply(
                    "⚠️ *Tidak ada transaksi untuk dikoreksi*\n\n".
                    "Saya tidak menemukan transaksi terakhir untuk diubah.\n\n".
                    "Silakan gunakan perintah edit langsung:\n".
                    "• _edit terakhir jadi 50rb_\n".
                    '• _hapus terakhir_'
                );

                return;
            }

            // Find the transaction
            $transaction = Transaction::find($lastTransactionId);

            if (! $transaction || $transaction->tenant_id !== $this->message->tenant_id) {
                $this->sendReply(
                    "⚠️ *Transaksi tidak ditemukan*\n\n".
                    'Transaksi yang ingin Anda koreksi tidak ditemukan atau sudah dihapus.'
                );

                return;
            }

            // Extract new amount from message using regex
            $textLower = strtolower($messageText);
            $newAmount = null;
            $newCategoryName = null;
            $updates = [];

            // Pattern: number followed by rb/ribu/k/jt/juta
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta|rbu)?/i', $textLower, $matches)) {
                $number = (float) str_replace(',', '.', $matches[1]);
                $suffix = strtolower($matches[2] ?? '');

                if (in_array($suffix, ['rb', 'ribu', 'k', 'rbu'])) {
                    $newAmount = $number * 1000;
                } elseif (in_array($suffix, ['jt', 'juta'])) {
                    $newAmount = $number * 1000000;
                } elseif ($number >= 1000) {
                    $newAmount = $number; // Already in full amount
                } elseif ($suffix === '') {
                    // If no suffix, check if mentioned in numeric update context
                    // Only assume amount if explicit correction keywords are used
                    if (preg_match('/(jadi|ubah|ganti)\s+(\d+)/', $textLower)) {
                        $newAmount = $number * 1000;
                    }
                } else {
                    $newAmount = $number * 1000; // Assume thousands if rb missing but likely intended?
                }
            }

            // Extract category update
            // "ganti kategori jadi hiburan", "pindahin ke makan", "masuk ke belanja"
            if (preg_match('/(?:kategori|masuk|pindah(?:in)?|ubah|ganti)\s+(?:ke\s+|jadi\s+)?([a-zA-Z\s]+)/i', $textLower, $catMatches)) {
                // Ignore "jadi 50rb" or "jadi rp"
                $candidate = trim($catMatches[1]);
                if (! preg_match('/^(\d+|rp|rupiah)/i', $candidate) && strlen($candidate) > 2) {
                    $newCategoryName = $candidate;
                }
            }

            if (! $newAmount && ! $newCategoryName) {
                $this->sendReply(
                    "⚠️ *Koreksi tidak jelas*\n\n".
                    "Silakan sebutkan nominal atau kategori yang benar.\n".
                    "Contoh:\n".
                    "• _salah harusnya 50rb_\n".
                    '• _ganti kategori jadi hiburan_'
                );

                return;
            }

            $replyMsg = "✅ *Transaksi Dikoreksi*\n\n";

            // Handle Amount Update
            if ($newAmount) {
                $oldAmount = $transaction->amount;

                // Update balance first (reverse old, apply new - handled by updateBalanceFromTransaction if we were creating, but here we update)
                // Actually, simply updating transaction amount is not enough if balance triggers.
                // We should reverse old adjustment and apply new.

                if ($transaction->balance_id) {
                    $balanceService = app(BalanceService::class);
                    // Reverse OLD amount
                    $balanceService->reverseBalanceUpdate($transaction);
                }

                $transaction->amount = $newAmount;
                $transaction->save();

                if ($transaction->balance_id) {
                    $balanceService = app(BalanceService::class);
                    // Apply NEW amount
                    $balanceService->updateBalanceFromTransaction($transaction);
                }

                $replyMsg .= '💰 Nominal: ~'.number_format($oldAmount, 0, ',', '.').'~ ➝ *'.number_format($newAmount, 0, ',', '.')."*\n";
            }

            // Handle Category Update
            if ($newCategoryName) {
                // Find category
                $category = Category::where('tenant_id', $this->message->tenant_id)
                    ->where(function ($q) use ($newCategoryName) {
                        $q->where('name', 'LIKE', '%'.$newCategoryName.'%')
                            ->orWhere('slug', 'LIKE', '%'.$newCategoryName.'%');
                    })
                    ->first();

                if ($category) {
                    $oldCategoryName = $transaction->category->name ?? 'Lainnya';
                    $transaction->category_id = $category->id;
                    $transaction->save();

                    $correctionService = app(CategoryCorrectionService::class);
                    $correctionService->recordCorrection(
                        $this->message->tenant_id,
                        $transaction->description ?? '',
                        $oldCategoryName,
                        $category->name,
                        $transaction->merchant,
                        $transaction->amount
                    );

                    $replyMsg .= "📁 Kategori: ~{$oldCategoryName}~ ➝ *{$category->name}*\n";

                    // Check budget alert for new category
                    if ($transaction->type === 'expense') {
                        $budgetAlert = $this->checkBudgetAlert($transaction);
                        if ($budgetAlert) {
                            $transaction->budget_alert = $budgetAlert;
                            $replyMsg .= $budgetAlert;
                        }
                    }
                } else {
                    $replyMsg .= "⚠️ Gagal ubah kategori (tidak ditemukan: $newCategoryName)\n";
                }
            }

            $replyMsg .= "\n📝 ".($transaction->description ?? '-')."\n";
            $replyMsg .= "\n_Data berhasil diperbarui_";

            Log::info('Transaction corrected via context', [
                'transaction_id' => $transaction->id,
                'new_amount' => $newAmount,
                'new_category' => $newCategoryName ?? null,
            ]);

            $this->sendReply($replyMsg);

        } catch (\Exception $e) {
            Log::error('Error editing transaction with context', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mengoreksi transaksi*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
            );
        }
    }

    /**
     * Handle multiple transaction commands (hapus/edit) from multi-line message.
     *
     * MOVED FROM: ProcessIncomingMessage::handleMultipleTransactionCommands()
     */
    public function handleMultipleTransactionCommands(array $lines): void
    {
        $results = [];
        $deleteCount = 0;
        $editCount = 0;
        $errorCount = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $lineLower = strtolower($line);

            if (preg_match('/^(hapus|delete|batal)\s+/i', $lineLower)) {
                $result = $this->processDeleteCommand($line);
                if ($result['success']) {
                    $deleteCount++;
                    $results[] = "🗑️ Dihapus: {$result['description']} (Rp {$result['amount']})";
                } else {
                    $errorCount++;
                    $results[] = "❌ Gagal hapus: {$result['error']}";
                }
            } elseif (preg_match('/^(ubah|edit|ganti|koreksi)\s+/i', $lineLower)) {
                $result = $this->processEditCommand($line);
                if ($result['success']) {
                    $editCount++;
                    $results[] = "✏️ Diubah: {$result['description']} → Rp {$result['new_amount']}";
                } else {
                    $errorCount++;
                    $results[] = "❌ Gagal edit: {$result['error']}";
                }
            }
        }

        $totalProcessed = $deleteCount + $editCount;
        if ($totalProcessed > 0) {
            $reply = "✅ *{$totalProcessed} Perintah Berhasil Diproses*\n\n";
            $reply .= implode("\n", $results);
            if ($errorCount > 0) {
                $reply .= "\n\n⚠️ {$errorCount} perintah gagal diproses.";
            }
        } else {
            $reply = "⚠️ *Tidak ada perintah yang berhasil diproses*\n\n";
            $reply .= implode("\n", $results);
        }

        $this->sendReply($reply);
    }

    /**
     * Process a single delete command (used by batch processor).
     *
     * MOVED FROM: ProcessIncomingMessage::processDeleteCommand()
     */
    public function processDeleteCommand(string $line): array
    {
        try {
            $keyword = preg_replace('/^(hapus|delete|batal|batalkan)\s+(transaksi\s+)?/i', '', trim($line));
            $keyword = trim($keyword);

            if (empty($keyword) || strlen($keyword) < 3) {
                return ['success' => false, 'error' => 'keyword terlalu pendek'];
            }

            $transaction = \App\Models\Transaction::where('tenant_id', $this->message->tenant_id)
                ->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($keyword).'%'])
                ->whereDate('transaction_date', today())
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $transaction) {
                return ['success' => false, 'error' => "'{$keyword}' tidak ditemukan"];
            }

            $balanceService = app(\App\Services\BalanceService::class);
            if ($transaction->balance_id) {
                $balanceService->reverseBalanceUpdate($transaction);
            }

            $description = $transaction->description;
            $amount = number_format($transaction->amount, 0, ',', '.');

            $transaction->delete();

            return ['success' => true, 'description' => $description, 'amount' => $amount];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process a single edit command (used by batch processor).
     *
     * MOVED FROM: ProcessIncomingMessage::processEditCommand()
     */
    public function processEditCommand(string $line): array
    {
        try {
            $pattern = '/^(ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?(.+?)\s+(?:jadi|ke|menjadi)?\s*(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?/i';

            if (! preg_match($pattern, trim($line), $matches)) {
                return ['success' => false, 'error' => 'format tidak valid'];
            }

            $keyword = trim($matches[2]);
            $numValue = floatval(str_replace(',', '.', $matches[3]));
            $suffix = strtolower($matches[4] ?? '');

            $multipliers = ['rb' => 1000, 'ribu' => 1000, 'k' => 1000, 'jt' => 1000000, 'juta' => 1000000];
            $multiplier = $multipliers[$suffix] ?? 1;
            $newAmount = (int) ($numValue * $multiplier);

            if ($newAmount <= 0) {
                return ['success' => false, 'error' => 'nominal tidak valid'];
            }

            $transaction = \App\Models\Transaction::where('tenant_id', $this->message->tenant_id)
                ->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($keyword).'%'])
                ->whereDate('transaction_date', today())
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $transaction) {
                return ['success' => false, 'error' => "'{$keyword}' tidak ditemukan"];
            }

            $oldAmount = $transaction->amount;
            $amountDiff = $newAmount - $oldAmount;

            if ($transaction->balance_id) {
                $balance = \App\Models\Balance::find($transaction->balance_id);
                if ($balance) {
                    if ($transaction->type === 'income') {
                        $balance->balance += $amountDiff;
                    } else {
                        $balance->balance -= $amountDiff;
                    }
                    $balance->save();
                }
            }

            $transaction->amount = $newAmount;
            $transaction->save();

            return [
                'success' => true,
                'description' => $transaction->description,
                'new_amount' => number_format($newAmount, 0, ',', '.'),
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
