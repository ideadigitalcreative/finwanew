<?php

namespace App\Services\Transaction;

use App\Helpers\KeywordNormalizer;
use App\Models\Balance;
use App\Models\Category;
use App\Models\Message;
use App\Models\Transaction;
use App\Services\AchievementService;
use App\Services\AIProcessorService;
use App\Services\BalanceService;
use App\Services\Category\CategoryCorrectionService;
use App\Services\Category\CategoryManagerService;
use App\Services\ConversationContextService;
use App\Services\DebtReceivable\CounterpartyExtractor;
use App\Services\DebtReceivable\CreditFacility;
use App\Services\DebtReceivable\DebtCashMode;
use App\Services\DebtReceivable\DebtReceivableLedgerService;
use App\Services\Ledger\TransactionLedger;
use Illuminate\Support\Facades\Cache;
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
    protected CategoryManagerService $categoryManager;

    protected $sendReplyCallback;

    protected $extractTransactionLocallyCallback;

    protected $extractAccountNameFromMessageCallback;

    protected $mapFinwaKategoriToCategoryTypeCallback;

    protected $createCategoriesForTenantCallback;

    protected $checkBudgetAlertCallback;

    protected $sendTransactionConfirmationCallback;

    protected $parseDateFromHeaderCallback;

    /**
     * Stores the last ambiguous extraction result (set when local extraction returns type='ambiguous').
     * ProcessIncomingMessage checks this after calling handleTransaction() to trigger confirmation flow.
     */
    public ?array $lastAmbiguousResult = null;

    /**
     * Constructor with dependency injection for cross-service methods
     */
    public function __construct(
        Message $message,
        CategoryInferenceService $categoryInference,
        CategoryManagerService $categoryManager,
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
        $this->categoryManager = $categoryManager;
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
     * Sanitize extracted transaction date.
     * If the date is in the future but the message has no explicit date keyword,
     * default to today — prevents dates embedded in descriptions (flight dates, order numbers)
     * from being used as the transaction date.
     */
    protected function sanitizeTransactionDate(?string $extractedDate, string $messageText): string
    {
        // Kata waktu di pesan ("kemarin", "5 oktober", "2 minggu lalu") dibaca aturan lokal
        // yang pasti — LLM sering mengabaikannya / mengembalikan tanggal hari ini.
        $localDate = app(TransactionExtractorService::class)->extractDateFromText($messageText);
        if ($localDate !== null) {
            return $localDate;
        }

        if (! $extractedDate) {
            return now()->toDateString();
        }

        $today = now()->toDateString();
        $textLower = strtolower($messageText);

        // If date is today or past, it's fine
        if ($extractedDate <= $today) {
            return $extractedDate;
        }

        // Date is in the future — only accept if message has explicit date keyword
        $hasDateKeyword = preg_match('/\b(tgl|tanggal|date)\b/i', $textLower)
            || preg_match('/\b(\d{1,2})\s+(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember|jan|feb|mar|apr|mei|jun|jul|agu|sep|okt|nov|des)\b/i', $textLower);

        if ($hasDateKeyword) {
            return $extractedDate;
        }

        Log::info('Future date rejected (no explicit date keyword), defaulting to today', [
            'extracted_date' => $extractedDate,
            'message_preview' => mb_substr($messageText, 0, 100),
        ]);

        return $today;
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
     * Resolve nomor WhatsApp pengirim pesan menjadi ID record user_whatsapp_numbers.
     * Mengembalikan null jika pengirim tidak terdaftar (mis. transaksi manual/dashboard).
     */
    protected function resolveSenderWhatsAppNumberId(): ?int
    {
        if (! $this->message->sender_id) {
            return null;
        }

        try {
            return app(\App\Services\WhatsAppUserMappingService::class)
                ->resolveUserWhatsAppNumberId($this->getAttributionSenderId(), $this->message->tenant_id);
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve sender WhatsApp number for transaction', [
                'message_id' => $this->message->id,
                'sender_id' => $this->message->sender_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
     * MODIFICATION: Added force_type support and ambiguous detection
     */
    public function handleTransaction(string $messageText, ?array $finwaEntities = null, ?array $options = null): void
    {
        // Reset ambiguous result
        $this->lastAmbiguousResult = null;

        // FORCE TYPE: If force_type is provided (from ambiguous confirmation), skip extraction
        if ($options && isset($options['force_type'])) {
            $this->handleForcedTransaction($messageText, $options);
            return;
        }

        $result = null;

        // CHECK FOR PENDING TRANSACTION FOLLOW-UP
        // If user previously sent "naik ojek" (no amount), and now sends "15rb", combine them
        $msgTrimmed = trim($messageText);
        $isOnlyAmount = preg_match('/^(Rp\s?)?(\d{1,3}([.,]\d{3})*|\d+)([.,]\d+)?\s*(rb|ribu|k|jt|juta|m)?$/i', $msgTrimmed);

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

            // ── HYBRID (Hybrid.md Fase 2, §7-2b) ──
            // Entities bertanda '_source=hybrid' sudah divalidasi ExtractionValidator
            // (nominal dari rules deklarasi eksplisit > angka LLM, nominal beda → rules).
            // Lewati tumpukan deteksi tipe di bawah (PRIORITY 0..FINAL) → langsung
            // pembentukan $transaction. Tumpukan lama tetap utuh di cabang `else`
            // untuk SEMUA panggilan non-hybrid (FinWa-AI, tanpa entities, force_type).
            // Catatan: blok `else` sengaja tidak indent ulang agar diff minimal.
            if (($finwaEntities['_source'] ?? null) === 'hybrid') {
                $isIncome = (bool) ($finwaEntities['is_income'] ?? false);
                $categoryType = $finwaEntities['category_type'] ?? null;
                if ($categoryType === null || $categoryType === '') {
                    $categoryType = $this->mapFinwaKategoriToCategoryType($finwaEntities['kategori'] ?? null, $isIncome);
                }
            } else {

            // PRIORITY 0: Check expense override patterns FIRST (overrides AI intent)
            // Read from config (single source of truth)
            $messageTextLower = strtolower($messageText);

            // PRIORITY -1: Deklarasi eksplisit user di awal pesan
            // (mis. "Pengeluaran mengganti motor ... 3jt") — menang atas AI intent,
            // income keyword, dan income override.
            $declaredType = \App\Services\Transaction\TransactionTypeDetector::detectDeclaredType($messageTextLower);

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

            if (! $isExpenseOverride && ! $isIncome && ! $isReceiptText && ($finwaIntent !== 'catat_pengeluaran' || preg_match('/\b(pemasukan|pendapatan|thr|duit masuk|uang masuk|masuk uang|masuk duit|masuk\s+pembayaran|pembayaran\s+masuk|terima\s+gaji|terima\s+transfer|terima\s+pembayaran|tambah\s+transfer\s+masuk|tambah\s+uang\s+masuk)\b/i', $messageTextLower))) {
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

            // Toleransi typo income keyword (mis. "pemasukann cash 150000")
            // Berlaku walau AI salah intent — tapi tidak menimpa expense override / receipt.
            if (! $isExpenseOverride && ! $isIncome && ! $isReceiptText
                && \App\Services\Transaction\TransactionTypeDetector::detectFuzzyIncome($messageTextLower)) {
                $isIncome = true;
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
            // GUARD: Validasi teks mengandung keyword hutang/piutang sebelum mempercayai intent AI
            // Mencegah misklasifikasi seperti "Nasi kuning Rp 32.000" → catat_piutang
            // Satu sumber dengan routing ProcessIncomingMessage (config/finwa_query.php)
            $debtKeywordsGuard = config('finwa_query.debt_keywords', []);
            $hasDebtKeyword = false;
            foreach ($debtKeywordsGuard as $dk) {
                if (str_contains($messageTextLower, $dk)) {
                    $hasDebtKeyword = true;
                    break;
                }
            }

            if ($hasDebtKeyword && is_string($finwaIntent) && in_array($finwaIntent, ['catat_hutang', 'catat_piutang', 'bayar_hutang', 'terima_piutang'], true)) {
                [$isIncome, $categoryType] = match ($finwaIntent) {
                    'catat_hutang' => [true, 'pendapatan_hutang'],
                    'terima_piutang' => [true, 'pendapatan_terima_piutang'],
                    'bayar_hutang' => [false, 'pengeluaran_bayar_hutang'],
                    'catat_piutang' => [false, 'pengeluaran_piutang'],
                };

                Log::info('Debt/receivable intent validated with keyword guard', [
                    'message_id' => $this->message->id,
                    'finwa_intent' => $finwaIntent,
                    'matched_keyword' => true,
                ]);
            } else {
                if (!$hasDebtKeyword && is_string($finwaIntent) && in_array($finwaIntent, ['catat_hutang', 'catat_piutang', 'bayar_hutang', 'terima_piutang'], true)) {
                    // AI salah klasifikasi intent hutang/piutang — teks tidak mengandung keyword terkait
                    // Jatuh ke flow normal (mapping kategori biasa)
                    Log::warning('Debt/receivable intent REJECTED by keyword guard — no debt keyword found in text', [
                        'message_id' => $this->message->id,
                        'finwa_intent' => $finwaIntent,
                        'message_preview' => mb_substr($messageText, 0, 100),
                    ]);
                }

                if ($hasAiCategoryType) {
                    // AI already provided category_type — validate it matches transaction type
                    $categoryType = $aiCategoryType;
                    if ($aiIsIncome !== null) {
                        $isIncome = $aiIsIncome;
                    }

                    // GUARD: Ensure category_type prefix matches income/expense
                    // Prevents AI from returning 'pengeluaran_gaji' for income transactions
                    // Pasangan tak mungkin (mis. "rani bayar utang" → income +
                    // pengeluaran_bayar_hutang) dipetakan ke arah kas yang benar,
                    // bukan sekadar ganti prefix (yang membuat kategori nyasar
                    // "pendapatan_bayar_hutang").
                    if ($isIncome && in_array($categoryType, ['pengeluaran_bayar_hutang', 'pengeluaran_piutang', 'pendapatan_bayar_hutang'], true)) {
                        $correctedType = 'pendapatan_terima_piutang';
                        Log::info('Correcting debt category_type for income', [
                            'original' => $categoryType,
                            'corrected' => $correctedType,
                        ]);
                        $categoryType = $correctedType;
                    } elseif (! $isIncome && in_array($categoryType, ['pendapatan_hutang', 'pendapatan_terima_piutang'], true)) {
                        $correctedType = 'pengeluaran_bayar_hutang';
                        Log::info('Correcting debt category_type for expense', [
                            'original' => $categoryType,
                            'corrected' => $correctedType,
                        ]);
                        $categoryType = $correctedType;
                    } elseif ($isIncome && str_starts_with($categoryType, 'pengeluaran_')) {
                        $correctedType = 'pendapatan_' . substr($categoryType, strlen('pengeluaran_'));
                        Log::info('Correcting category_type prefix for income', [
                            'original' => $categoryType,
                            'corrected' => $correctedType,
                        ]);
                        $categoryType = $correctedType;
                    } elseif (! $isIncome && str_starts_with($categoryType, 'pendapatan_')) {
                        $correctedType = 'pengeluaran_' . substr($categoryType, strlen('pendapatan_'));
                        Log::info('Correcting category_type prefix for expense', [
                            'original' => $categoryType,
                            'corrected' => $correctedType,
                        ]);
                        $categoryType = $correctedType;
                    }
                } else {
                    $categoryType = $this->mapFinwaKategoriToCategoryType($kategori, $isIncome);
                }
            }

            // FINAL: Deklarasi eksplisit user di awal pesan menang atas semua
            // override di atas (AI intent, income keyword, income override, dst.)
            if ($declaredType !== null) {
                $isIncome = $declaredType === 'income';

                // Selaraskan prefix category_type dengan tipe yang dideklarasikan
                // (AI bisa saja sudah men-set 'pendapatan_transfer' dari kata "tf").
                if (isset($categoryType) && $isIncome && str_starts_with($categoryType, 'pengeluaran_')) {
                    $categoryType = 'pendapatan_'.substr($categoryType, strlen('pengeluaran_'));
                } elseif (isset($categoryType) && ! $isIncome && str_starts_with($categoryType, 'pendapatan_')) {
                    $categoryType = 'pengeluaran_'.substr($categoryType, strlen('pendapatan_'));
                }
            }

            } // ← HYBRID: akhir cabang `else` (tumpukan deteksi tipe lama)

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
                'transaction_date' => $this->sanitizeTransactionDate($finwaEntities['tanggal'] ?? null, $messageText),
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
                // CHECK FOR AMBIGUOUS: If local extraction returned type='ambiguous', signal back to caller
                if (isset($localExtraction['type']) && $localExtraction['type'] === 'ambiguous') {
                    Log::info('Ambiguous transaction detected by local extraction', [
                        'message_id' => $this->message->id,
                        'pattern' => $localExtraction['pattern'] ?? null,
                        'amount' => $localExtraction['amount'],
                    ]);
                    $this->lastAmbiguousResult = $localExtraction;
                    return;
                }

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
        Log::info('CategoryInference: Reached inference block', [
            'has_result' => (bool) $result,
            'success' => $result['success'] ?? false,
            'has_transactions' => isset($result['data']['extracted_transactions']),
        ]);
        if ($result && $result['success'] && isset($result['data']['extracted_transactions'])) {
            $txCount = count($result['data']['extracted_transactions']);

            // GUARD: kategori aliran hutang/piutang tidak boleh ditimpa inferensi generik
            $debtCategoryTypes = ['pendapatan_hutang', 'pengeluaran_bayar_hutang', 'pengeluaran_piutang', 'pendapatan_terima_piutang'];

            if ($txCount === 1) {
                $txText = $result['data']['extracted_transactions'][0]['description'] ?? $messageText;
                $inference = $this->categoryInference->infer($txText, null, $this->message->tenant_id);

                Log::info('CategoryInferenceService result', [
                    'message_id' => $this->message->id,
                    'inference' => $inference,
                    'tx_count' => $txCount,
                ]);

                // GUARD: Jangan timpa kategori aliran hutang/piutang.
                // Inferensi keyword generik bisa menimpa kategori spesifik
                // (mis. "rani bayar utang 120rb" yang benar
                // pendapatan_terima_piutang → dipaksa pengeluaran_bayar_hutang),
                // membuat transaksi jatuh ke kategori nyasar / tidak masuk ledger.
                $originalCategoryType = $result['data']['extracted_transactions'][0]['category_type'] ?? null;
                $isDebtFlow = is_string($originalCategoryType) && in_array($originalCategoryType, $debtCategoryTypes, true);

                if ($isDebtFlow) {
                    Log::info('CategoryInference: Skipped for debt flow', [
                        'message_id' => $this->message->id,
                        'kept_category_type' => $originalCategoryType,
                    ]);
                } elseif ($inference['confidence'] >= 0.4) {
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
                    $inference = $this->categoryInference->infer($txText, null, $this->message->tenant_id);

                    $oldCat = $tx['category_type'] ?? null;
                    if (is_string($oldCat) && in_array($oldCat, $debtCategoryTypes, true)) {
                        $kept++;
                        continue;
                    }

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
                // NOTE: Per-pengirim, konsisten dengan ProcessIncomingMessage yang kini
                // membuat/membaca context DENGAN senderId (addContext & getPendingTransaction).
                // Keduanya wajib sama; kalau tidak, pending tidak ketemu saat follow-up nominal.
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
     * Handle a transaction with forced type/category (from ambiguous confirmation).
     * Skips extraction and AI processing — creates the transaction directly.
     */
    protected function handleForcedTransaction(string $messageText, array $options): void
    {
        $forceType = $options['force_type']; // 'income' or 'expense'
        $forceCategoryType = $options['force_category_type'];
        $transactionDate = $options['transaction_date'] ?? now()->toDateString();

        // Nominal yang sudah dibaca saat pertanyaan diajukan menang atas baca ulang dari teks
        $amount = isset($options['amount']) && (float) $options['amount'] > 0
            ? (float) $options['amount']
            : $this->extractAmountFromText($messageText);

        // If amount is 0, try to extract from the full message using pattern matching
        if ($amount <= 0) {
            // Try to find amount anywhere in the message
            if (preg_match('/(\d+(?:[.,]\d{3})*)\s*(rb|ribu|k|jt|juta|m)?/i', $messageText, $amtMatches)) {
                $numStr = str_replace(['.', ','], ['', '.'], $amtMatches[1]);
                $multiplier = 1;
                $suffix = strtolower($amtMatches[2] ?? '');
                if (in_array($suffix, ['rb', 'ribu', 'k'])) {
                    $multiplier = 1000;
                } elseif (in_array($suffix, ['jt', 'juta', 'm'])) {
                    $multiplier = 1000000;
                }
                $amount = floatval($numStr) * $multiplier;
            }
        }

        if ($amount <= 0) {
            Log::warning('Forced transaction: could not extract amount', [
                'message_id' => $this->message->id,
                'message_text' => $messageText,
            ]);
            $this->sendReply("⚠️ *Gagal mencatat transaksi*\n\nNominal tidak terdeteksi dari pesan asli.");
            return;
        }

        $txData = [
            'type' => $forceType,
            'amount' => $amount,
            'category' => null,
            'category_type' => $forceCategoryType,
            'description' => $messageText,
            // Dompet pilihan user dari pertanyaan bernomor menang atas tebakan dari teks
            'account_name' => $options['account_name'] ?? $this->extractAccountNameFromMessage($messageText),
            'transaction_date' => $transactionDate,
            'confidence_score' => 0.95,
            'source' => 'ambiguous_confirmation',
        ];
        // Jawaban "uangnya masuk dompet / hanya dicatat" untuk hutang-piutang
        if (array_key_exists('debt_record_only', $options) && $options['debt_record_only'] !== null) {
            $txData['debt_record_only'] = (bool) $options['debt_record_only'];
        }
        if (! empty($options['counterparty']) && is_string($options['counterparty'])) {
            $txData['metadata'] = ['counterparty' => trim($options['counterparty'])];
        }

        $transaction = $this->createTransaction($txData, false);

        if ($transaction) {
            $this->sendTransactionConfirmation([$transaction], false);
        } else {
            Log::warning('Forced transaction: createTransaction returned null', [
                'message_id' => $this->message->id,
                'tx_data' => $txData,
            ]);
            $this->sendReply("⚠️ *Gagal membuat transaksi*\n\nTerjadi kesalahan saat menyimpan. Silakan coba lagi.");
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

        // GUARD: Ensure category_type prefix matches transaction type
        // Catches ALL code paths (AI fast path, local extraction, etc.)
        // Khusus hutang/piutang: arah kas sudah ditentukan `type`, sedangkan
        // kategori hasil inferensi bisa tertukar (mis. "baso bayar utang" →
        // income + pengeluaran_bayar_hutang). Peta eksplisit agar tidak
        // membuat kategori nyasar seperti "pendapatan_bayar_hutang".
        if ($txData['type'] === 'income' && isset($txData['category_type'])) {
            $txData['category_type'] = match ($txData['category_type']) {
                'pengeluaran_bayar_hutang', 'pengeluaran_piutang' => 'pendapatan_terima_piutang',
                default => str_starts_with($txData['category_type'], 'pengeluaran_')
                    ? 'pendapatan_' . substr($txData['category_type'], strlen('pengeluaran_'))
                    : $txData['category_type'],
            };
        } elseif ($txData['type'] === 'expense' && isset($txData['category_type'])) {
            $txData['category_type'] = match ($txData['category_type']) {
                'pendapatan_hutang', 'pendapatan_terima_piutang' => 'pengeluaran_bayar_hutang',
                default => str_starts_with($txData['category_type'], 'pendapatan_')
                    ? 'pengeluaran_' . substr($txData['category_type'], strlen('pendapatan_'))
                    : $txData['category_type'],
            };
        }

        // Hutang/piutang yang HANYA DICATAT (hutang lama, paylater, kartu kredit):
        // tidak ada uang masuk/keluar dompet → tanpa dompet & bukan pemasukan/pengeluaran.
        $debtRecordOnly = $this->isDebtRecordOnly($txData);
        if ($debtRecordOnly) {
            $txData['type'] = DebtCashMode::recordOnlyType($txData['category_type']);
            $txData['account_name'] = null;
            Log::info('Debt recorded without cash movement (record only)', [
                'message_id' => $this->message->id,
                'category_type' => $txData['category_type'],
            ]);
        }

        // Paylater / kartu kredit:
        //  - belanja pakai fasilitas → pengeluaran + hutang naik, dompet tidak berubah
        //  - bayar tagihan → hutang turun; tidak dihitung pengeluaran lagi bila
        //    belanjanya sudah tercatat sebagai pengeluaran
        $creditPurchase = null;
        $creditRepayment = false;
        if (! $debtRecordOnly && $txData['type'] === 'expense') {
            [$creditPurchase, $creditRepayment] = $this->applyCreditFacility($txData);
        }

        // Find category by type
        $category = Category::where('tenant_id', $this->message->tenant_id)
            ->where('type', $txData['category_type'])
            ->first();

        // SELF-HEALING: Ensure category has correct metadata (name, icon)
        if ($category) {
            $category = $this->categoryManager->ensureCategoryMetadata($category);
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

        if ($debtRecordOnly || $creditPurchase !== null) {
            // Hanya dicatat / belanja pakai paylater: sengaja tidak ditautkan ke dompet
            // mana pun (saldo tidak berubah)
        } elseif ($accountName) {
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

            // Kandidat dari regex sering membawa kata tambahan ("masuk bca", "qris bca",
            // "ovo ya") atau salah tebak ("makan siang"). Cocokkan ke dompet milik user:
            // dulu di dalam kandidat, lalu di teks pesan.
            $walletResolver = app(\App\Services\Wallet\WalletResolver::class);
            if (! $balance) {
                $balance = $walletResolver->match($this->message->tenant_id, $accountName, true)
                    ?? $this->matchWalletInMessage($txData);
            }

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

                $balance = $this->memberDefaultBalance()
                    ?? $balanceService->getDefaultBalance($this->message->tenant_id);

                // Peringatan hanya bila kandidat memang nama rekening/e-wallet — bukan kata
                // biasa yang salah ditebak sebagai dompet.
                if ($walletResolver->looksLikeWalletName($accountName, (string) ($txData['description'] ?? ''))) {
                    $txData['wallet_not_found_warning'] = ucwords(mb_strtolower($accountName));
                }

                if ($balance) {
                    Log::info('Using default balance as fallback', [
                        'tenant_id' => $this->message->tenant_id,
                        'balance_id' => $balance->id,
                        'account_name' => $balance->account_name,
                    ]);
                }
            }
        } else {
            // Tidak ada kandidat dari regex: dompet user mungkin tetap disebut di pesan
            // ("makan 15rb lewat rek mandiri"). Tidak ada → dompet utama.
            // Lalu dompet bawaan anggota pengirim (diatur di dashboard), baru dompet utama akun.
            $balance = $this->matchWalletInMessage($txData)
                ?? $this->memberDefaultBalance()
                ?? $balanceService->getDefaultBalance($this->message->tenant_id);

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
            $cp = $this->resolveDebtCounterparty($txData);
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
            if ($debtRecordOnly) {
                $txMetadata['kind'] = Transaction::KIND_DEBT_RECORD_ONLY;
            }
            if ($creditRepayment) {
                $txMetadata['kind'] = Transaction::KIND_CREDIT_REPAYMENT;
            }
        }

        if ($creditPurchase !== null) {
            $txMetadata['credit_purchase'] = true;
            $txMetadata['counterparty'] = $creditPurchase;
            $txMetadata['counterparty_normalized'] = CreditFacility::key($creditPurchase);
            $txMetadata['debt_flow'] = 'belanja_kredit';
        }

        if ($isGroup) {
            $txMetadata['group_id'] = $this->message->sender_id;
            $txMetadata['is_group'] = true;
            // Also store the participant (author) who sent it
            $txMetadata['author'] = $metadata['author'] ?? null;
        }

        // Catat + terapkan ke saldo dalam satu DB transaction (TransactionLedger)
        $transaction = app(TransactionLedger::class)->record([
            'tenant_id' => $this->message->tenant_id,
            'category_id' => $category->id,
            'message_id' => $this->message->id,
            'balance_id' => $balance?->id,
            'user_whatsapp_number_id' => $this->resolveSenderWhatsAppNumberId(),
            'type' => $txData['type'],
            'amount' => $txData['amount'],
            'transaction_date' => $txData['transaction_date'],
            'source' => $txData['source'] ?? null,
            'description' => $txData['description'],
            'merchant' => $txData['merchant'] ?? null,
            'confidence_score' => $txData['confidence_score'] ?? 0.5,
            // Fitur "tunggu review" dihilangkan — transaksi selalu langsung dikonfirmasi
            'status' => 'confirmed',
            'metadata' => $txMetadata,
        ]);

        // Dispatch event notifikasi ke anggota grup
        Log::info('Dispatching TransactionCreated event', [
            'transaction_id' => $transaction->id,
            'tenant_id' => $transaction->tenant_id ?? null,
        ]);
        event(new \App\Events\TransactionCreated($transaction));

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
     * Baris nomor WhatsApp "asli" pengirim (alias LID dipetakan ke nomor induknya).
     */
    protected function senderNumber(): ?\App\Models\UserWhatsAppNumber
    {
        $numberId = $this->resolveSenderWhatsAppNumberId();
        if (! $numberId) {
            return null;
        }
        try {
            $map = \App\Models\UserWhatsAppNumber::canonicalIdMap((int) $this->message->tenant_id);
            $numberId = $map[$numberId] ?? $numberId;
        } catch (\Throwable $e) {
            // pakai id apa adanya
        }

        return \App\Models\UserWhatsAppNumber::where('tenant_id', $this->message->tenant_id)->find($numberId);
    }

    /** Dompet bawaan anggota pengirim (kolom default_balance_id), bila diatur & masih aktif. */
    protected function memberDefaultBalance(): ?Balance
    {
        try {
            $balanceId = $this->senderNumber()?->default_balance_id;

            return $balanceId
                ? Balance::where('tenant_id', $this->message->tenant_id)->where('is_active', true)->find($balanceId)
                : null;
        } catch (\Throwable $e) {
            // Kolom belum ada (migrasi belum jalan) → perilaku lama
            return null;
        }
    }

    /**
     * Transaksi berkata kunci $keyword tidak ditemukan di antara milik pengirim:
     * mungkin milik anggota lain di akun yang sama.
     *  - Pengirim pemilik akun (nomor utama) → transaksinya dikembalikan (boleh dikoreksi).
     *  - Anggota biasa → dibalas jujur "itu transaksi <nama>", dikembalikan false.
     *  - Memang tidak ada → null.
     *
     * @return Transaction|false|null
     */
    protected function findOtherMembersTransaction(string $keyword, int $days = 30)
    {
        $keyword = mb_strtolower(trim($keyword));
        if ($keyword === '') {
            return null;
        }

        $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', Transaction::KIND_RECONCILE_BASELINE))
            ->whereRaw('LOWER(description) LIKE ?', ['%'.$keyword.'%'])
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        if (! $transaction) {
            return null;
        }

        if ($this->senderNumber()?->is_primary) {
            return $transaction;
        }

        $owner = $transaction->user_whatsapp_number_id
            ? \App\Models\UserWhatsAppNumber::find($transaction->user_whatsapp_number_id)
            : null;
        $who = $owner?->name ?: 'anggota lain';
        $desc = KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: $keyword;
        $this->sendReply(
            "🔒 *Itu transaksi {$who}*\n\n".
            "📝 {$desc} · Rp ".number_format((float) $transaction->amount, 0, ',', '.')."\n\n".
            "Hanya *{$who}* atau pemilik akun yang bisa mengubah atau menghapusnya."
        );

        return false;
    }

    /**
     * Dompet milik user yang disebut di pesan teks/suara (dengan penanda cara bayar,
     * atau tepat setelah nominal di akhir pesan). Foto struk punya jalurnya sendiri
     * (keterangan foto), jadi dilewati di sini.
     */
    protected function matchWalletInMessage(array $txData): ?Balance
    {
        if (! in_array($this->message->type, ['text', 'audio'], true)) {
            return null;
        }

        $resolver = app(\App\Services\Wallet\WalletResolver::class);
        $tenantId = (int) $this->message->tenant_id;

        // Teks pesan asli hanya bila satu baris (pesan batch berisi banyak transaksi)
        $content = $this->message->type === 'text' ? trim((string) ($this->message->content ?? '')) : '';
        $texts = [(string) ($txData['description'] ?? '')];
        if ($content !== '' && ! str_contains($content, "\n")) {
            $texts[] = $content;
        }

        foreach ($texts as $text) {
            if ($balance = $resolver->match($tenantId, $text)) {
                return $balance;
            }
        }

        return null;
    }

    /**
     * Nama pihak lawan untuk transaksi hutang/piutang; fasilitas kredit diseragamkan
     * ("shopee paylater" → "SPayLater") agar teragregasi ke satu pihak.
     */
    protected function resolveDebtCounterparty(array $txData): string
    {
        $cp = trim((string) ($txData['metadata']['counterparty'] ?? ''));
        if ($cp === '') {
            $cp = trim((string) (CounterpartyExtractor::extract($txData['description'] ?? '') ?? ''));
        }
        // "hutang lama budi 2jt" → pihak "budi", bukan "lama budi"
        $cp = trim((string) preg_replace('/^(?:lama|lalu|dulu)\s+(?:ke\s+|dari\s+|sama\s+)?/iu', '', $cp));

        return CreditFacility::canonical($cp !== '' ? $cp : (string) ($txData['description'] ?? '')) ?? $cp;
    }

    /**
     * Kenali pemakaian paylater/kartu kredit pada sebuah pengeluaran dan sesuaikan $txData.
     *
     * @return array{0: string|null, 1: bool} [nama fasilitas bila ini belanja kredit, apakah pembayaran non-pengeluaran]
     */
    protected function applyCreditFacility(array &$txData): array
    {
        $tenantId = $this->message->tenant_id;
        $debtTypes = ['pendapatan_hutang', 'pengeluaran_bayar_hutang', 'pengeluaran_piutang', 'pendapatan_terima_piutang'];
        $isDebtCategory = in_array($txData['category_type'] ?? null, $debtTypes, true);

        // Teks pesan asli hanya dipakai bila satu baris (pesan batch berisi banyak transaksi)
        $content = (string) ($this->message->content ?? '');
        $text = trim((str_contains($content, PHP_EOL) || str_contains($content, "\n") ? '' : $content).' '.($txData['description'] ?? '').' '.($txData['account_name'] ?? ''));
        $use = CreditFacility::detectUse($text);

        if ($use !== null && $use['mode'] === 'purchase' && ! $isDebtCategory) {
            // Punya dompet bernama fasilitas itu → biarkan jadi transaksi dompet biasa
            if (CreditFacility::hasWallet($tenantId, $use['name'])) {
                return [null, false];
            }
            $txData['account_name'] = null;
            Log::info('Credit purchase detected (expense + debt, wallet untouched)', [
                'message_id' => $this->message->id,
                'facility' => $use['name'],
            ]);

            return [$use['name'], false];
        }

        // "bayar spaylater 500rb" → bayar hutang, bila memang ada hutang ke fasilitas itu
        if ($use !== null && $use['mode'] === 'repayment' && ! $isDebtCategory
            && app(DebtReceivableLedgerService::class)->hutangOutstanding($tenantId, CreditFacility::key($use['name'])) > 0) {
            $txData['category_type'] = 'pengeluaran_bayar_hutang';
            $txData['metadata'] = array_merge($txData['metadata'] ?? [], ['counterparty' => $use['name']]);
        }

        if (($txData['category_type'] ?? null) === 'pengeluaran_bayar_hutang') {
            $cp = $this->resolveDebtCounterparty($txData);
            if ($cp !== '' && CreditFacility::purchaseOutstanding($tenantId, CreditFacility::key($cp)) > 0) {
                // Belanjanya sudah dihitung pengeluaran → pembayaran hanya memindahkan uang keluar dompet
                $txData['type'] = 'debit_internal';

                return [null, true];
            }
        }

        return [null, false];
    }

    /**
     * Apakah pencatatan hutang/piutang baru ini hanya dicatat (tanpa menggerakkan saldo)?
     * Pilihan eksplisit user (jawaban pertanyaan bernomor) menang atas tebakan dari teks.
     */
    protected function isDebtRecordOnly(array $txData): bool
    {
        if (! DebtCashMode::isNewDebtCategory($txData['category_type'] ?? null)) {
            return false;
        }
        if (! in_array($txData['type'] ?? null, ['income', 'expense'], true)) {
            return false;
        }
        if (array_key_exists('debt_record_only', $txData) && $txData['debt_record_only'] !== null) {
            return (bool) $txData['debt_record_only'];
        }

        $text = trim(($this->message->content ?? '').' '.($txData['description'] ?? ''));

        // Sampai di sini tanpa pilihan user (grup / jalur tanpa pertanyaan) → tebakan terbaik
        return DebtCashMode::guess($text) === DebtCashMode::RECORD_ONLY;
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
        // 3. Simpan transaksi + update saldo dalam SATU DB transaction (TransactionLedger)
        $categoryId = Category::where('tenant_id', $this->message->tenant_id)->where('type', $categoryType)->value('id')
            ?? Category::where('tenant_id', $this->message->tenant_id)
                ->where('type', $type === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya')->value('id');
        app(TransactionLedger::class)->record([
            'tenant_id' => $this->message->tenant_id,
            'balance_id' => $balance->id,
            'category_id' => $categoryId,
            'message_id' => $this->message->id,
            'user_whatsapp_number_id' => $this->resolveSenderWhatsAppNumberId(),
            'amount' => $rawAmount,
            'type' => $type,
            'description' => $description,
            'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
            'source' => $data['source'] ?? null,
            'confidence_score' => $data['confidence_score'] ?? 0.9,
        ]);
        $balance->refresh();

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

        // Keterangan tanpa nominal — nominal sudah ada di baris "💵 {$formattedAmount}"
        $replyDesc = KeywordNormalizer::stripAmountFromDescription($description);

        $reply = "✅ Siap, Sudah Masuk! ✅\n\n".
            "{$emoji} {$typeText}\n".
            "💵 {$formattedAmount}\n".
            "{$categoryLabel} • {$replyDesc}".
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
    /**
     * Perintah batal/hapus transaksi terakhir yang EKSPLISIT — seluruh pesan berupa
     * perintah, bukan kalimat yang kebetulan mengandung kata "ga jadi"/"batalkan".
     *   ✓ "batal", "undo", "ga jadi", "hapus yang tadi", "batalin aja", "salah catat"
     *   ✗ "ga jadi beli baju, beli celana 150rb", "batalkan pesanan, kena fee 5rb"
     */
    public static function isUndoCommand(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $t = trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t));
        $t = (string) preg_replace('/\s+/u', ' ', $t);
        if ($t === '') {
            return false;
        }

        // "batal di hapus" / "batal dihapus" = batalkan transaksi tadi (hapus), bukan
        // hapus transaksi berkata kunci "di hapus". ("batal hapus" = pulihkan → isRestoreCommand.)
        $t = (string) preg_replace('/\bdi\s+(hapus|batalkan|batalin|cancel)\b/u', 'di$1', $t);

        $filler = '(?:\s+(?:aja|saja|deh|dong|ya|yah|kak|min|yang|yg|tadi|barusan|terakhir|itu|ini|transaksi|catatan|tsb|tersebut|tolong|dihapus|dibatalkan|dibatalin|dicancel))*';

        return (bool) preg_match(
            '/^(?:(?:tolong|mohon|min|kak)\s+)?(?:undo|batal|batalin|batalkan|cancel|hapus|hapusin|delete|ga\s?jadi|gak\s?jadi|nggak\s?jadi|ngga\s?jadi|engga\s?jadi|enggak\s?jadi|tidak\s?jadi|gajadi)'.$filler.'$/u',
            $t
        ) || (bool) preg_match(
            '/^(?:(?:yang|yg)\s+)?(?:tadi|barusan)\s+salah(?:\s+(?:catat|input|ketik))?'.$filler.'$|^salah\s+(?:catat|input|ketik)'.$filler.'$/u',
            $t
        );
    }

    /**
     * Batasi ke transaksi MILIK PENGIRIM bila akun dipakai beberapa nomor
     * (keluarga / pasangan / tim) — agar "batal" dari istri tidak menghapus
     * transaksi suami. Akun 1 nomor: tanpa batasan (perilaku lama).
     */
    protected function scopeToSender($query)
    {
        // Saldo dasar rekonsiliasi bukan transaksi user — tidak pernah jadi target hapus/edit
        $query->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', Transaction::KIND_RECONCILE_BASELINE));

        $activeNumbers = \App\Models\UserWhatsAppNumber::where('tenant_id', $this->message->tenant_id)
            ->where('is_active', true)
            ->count();
        if ($activeNumbers <= 1) {
            return $query;
        }

        $numberId = $this->resolveSenderWhatsAppNumberId();

        return $numberId ? $query->where('user_whatsapp_number_id', $numberId) : $query;
    }

    /**
     * Target hapus/ubah via keyword: transaksi bertanggal hari ini ATAU dicatat
     * hari ini. "Pengeluaran 22 Oktober …" yang dikirim 26 Oktober bertanggal mundur,
     * tapi tetap harus bisa langsung dihapus/diubah dengan "hapus laundry baju".
     */
    protected function todayOrRecordedToday(): \Closure
    {
        return fn ($q) => $q->whereDate('transaction_date', today())->orWhereDate('created_at', today());
    }

    /**
     * Kunci konfirmasi hapus untuk SEMUA identitas pengirim (LID & nomor HP orang yang
     * sama). Dulu hanya digit sender_id: jawaban "ya" yang datang lewat LID tidak
     * menemukan konfirmasinya.
     *
     * @return list<string>
     */
    protected function pendingDeleteKeys(): array
    {
        return array_map(
            fn (string $identity) => 'pending_delete:'.$this->message->tenant_id.':'.$identity,
            \App\Support\SenderIdentity::keys($this->message, $this->getAttributionSenderId())
        );
    }

    /** Ada konfirmasi hapus yang sedang menunggu jawaban pengirim ini? */
    public function hasPendingDelete(): bool
    {
        foreach ($this->pendingDeleteKeys() as $key) {
            if (Cache::get($key) !== null) {
                return true;
            }
        }

        return false;
    }

    private function forgetPendingDelete(): void
    {
        foreach ($this->pendingDeleteKeys() as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Tampilkan transaksi yang akan dihapus & minta konfirmasi Ya/Tidak (5 menit).
     * Hapus tidak pernah langsung terjadi tanpa persetujuan user.
     */
    protected function requestDeleteConfirmation(Transaction $transaction): void
    {
        foreach ($this->pendingDeleteKeys() as $key) {
            Cache::put($key, ['transaction_id' => $transaction->id], now()->addMinutes(5));
        }

        $transaction->loadMissing('category');
        [$emoji, $label] = $this->transactionTypeLabel($transaction);
        $desc = KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: '-';

        $this->sendReply(
            "🗑️ *Hapus transaksi ini?*\n\n".
            "{$emoji} *{$label}* · Rp ".number_format((float) $transaction->amount, 0, ',', '.')."\n".
            "📝 {$desc}\n".
            '📁 '.($transaction->category->name ?? 'Lainnya').' · 📅 '.$transaction->transaction_date->format('d M Y')."\n\n".
            'Balas *Ya* untuk menghapus, atau *Tidak* untuk membatalkan.'
        );
    }

    /**
     * Jawaban atas konfirmasi hapus. true bila pesan adalah jawaban (sudah ditangani).
     * Pesan lain membatalkan konfirmasi & diproses normal.
     */
    public function handlePendingDeleteReply(string $reply): bool
    {
        $pending = null;
        foreach ($this->pendingDeleteKeys() as $key) {
            $pending = Cache::get($key);
            if (is_array($pending)) {
                break;
            }
        }
        if (! is_array($pending)) {
            return false;
        }

        $answer = trim((string) preg_replace('/[^\p{L}\s]/u', '', mb_strtolower(trim($reply))));

        if (preg_match('/^(?:ya|y|iya|iyaa|yaa|ok|oke|okay|hapus|yakin|lanjut|betul|benar|setuju|yes|gas|sip)(?:\s+(?:hapus|aja|deh|dong|kak|min|yakin))*$/u', $answer)) {
            $this->forgetPendingDelete();
            $transaction = Transaction::where('tenant_id', $this->message->tenant_id)->find($pending['transaction_id'] ?? 0);
            if (! $transaction) {
                $this->sendReply('ℹ️ Transaksi tersebut sudah tidak ada.');

                return true;
            }
            $this->deleteTransactionNow($transaction, 'pending_delete_confirmed');

            return true;
        }

        if (preg_match('/^(?:tidak|tdk|gak|ga|nggak|ngga|enggak|engga|no|n|batal|batalkan|jangan|cancel|nanti|skip)(?:\s+(?:jadi|usah|dulu|aja|deh|dihapus|hapus|kak|min))*$/u', $answer)) {
            $this->forgetPendingDelete();
            $this->sendReply('👍 Oke, transaksi *tidak jadi* dihapus.');

            return true;
        }

        // Pesan lain → konfirmasi kedaluwarsa, lanjutkan alur normal
        $this->forgetPendingDelete();

        return false;
    }

    /** @return array{0: string, 1: string} */
    private function transactionTypeLabel(Transaction $transaction): array
    {
        // Hutang/piutang yang hanya dicatat memakai jenis internal, tapi bukan transfer
        if ($transaction->isDebtRecordOnly()) {
            return $transaction->type === 'kredit_internal'
                ? ['🏦', 'Hutang (hanya dicatat)']
                : ['🤝', 'Piutang (hanya dicatat)'];
        }
        if ($transaction->isCreditRepayment()) {
            return ['🏦', 'Bayar Hutang'];
        }

        return match ($transaction->type) {
            'income' => ['💰', 'Pemasukan'],
            'expense' => ['💸', 'Pengeluaran'],
            'debit_internal', 'kredit_internal' => ['🔄', 'Transfer Internal'],
            default => ['📝', 'Transaksi'],
        };
    }

    /**
     * Hapus transaksi + kembalikan saldo dompetnya, lalu kirim konfirmasi.
     */
    protected function deleteTransactionNow(Transaction $transaction, string $via): void
    {
        $transaction->loadMissing('category');
        [$typeEmoji, $typeLabel] = $this->transactionTypeLabel($transaction);
        $amount = number_format((float) $transaction->amount, 0, ',', '.');
        $category = $transaction->category->name ?? 'Lainnya';
        $desc = KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: '-';
        $date = $transaction->transaction_date->format('d M Y');

        // Soft delete + kembalikan saldo (pasangan transfer ikut) — bisa dipulihkan: "kembalikan"
        app(TransactionLedger::class)->delete($transaction);

        Log::info('Transaction deleted via chat', [
            'message_id' => $this->message->id,
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'via' => $via,
        ]);

        $this->sendReply(
            "🗑️ *Transaksi Dihapus!*\n\n".
            "{$typeEmoji} *{$typeLabel}*\n".
            "💵 Rp {$amount}\n".
            "📁 {$category}\n".
            "📝 {$desc}\n".
            "📅 {$date}\n\n".
            "✅ Transaksi berhasil dihapus dari catatan Anda.\n".
            '↩️ Salah hapus? Ketik *kembalikan* (berlaku 30 hari).'
        );
    }

    /**
     * "kembalikan" / "pulihkan transaksi" / "batal hapus" — seluruh pesan berupa perintah.
     */
    public static function isRestoreCommand(string $text): bool
    {
        $t = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text)));
        $t = (string) preg_replace('/\s+/u', ' ', $t);

        return (bool) preg_match(
            '/^(?:(?:tolong|mohon|min|kak)\s+)?(?:kembalikan|kembaliin|balikin|pulihkan|restore|undo\s+hapus|batal(?:kan|in)?\s+(?:hapus|penghapusan)|(?:ga|gak|nggak|tidak)\s?jadi\s+(?:hapus|dihapus))(?:\s+(?:transaksi|yang|yg|tadi|dihapus|terhapus|kehapus|terakhir|barusan|itu|aja|dong|ya|deh|kak|min|catatan))*$/u',
            $t
        );
    }

    /** Pulihkan transaksi milik pengirim yang terakhir dihapus (≤ 30 hari). */
    public function handleRestoreTransaction(): void
    {
        $transaction = $this->scopeToSender(Transaction::onlyTrashed()->where('tenant_id', $this->message->tenant_id))
            ->where('deleted_at', '>=', now()->subDays(30))
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->first();

        if (! $transaction) {
            $this->sendReply("ℹ️ Tidak ada transaksi terhapus yang bisa dikembalikan (30 hari terakhir).");

            return;
        }

        app(TransactionLedger::class)->restore($transaction);
        $transaction->refresh()->loadMissing(['category', 'balance']);

        [$emoji, $label] = $this->transactionTypeLabel($transaction);
        $desc = KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: '-';
        $balanceLine = $transaction->balance
            ? "\n\n👛 Saldo {$transaction->balance->account_name}: Rp ".number_format((float) $transaction->balance->balance, 0, ',', '.')
            : '';

        $this->sendReply(
            "♻️ *Transaksi Dikembalikan!*\n\n".
            "{$emoji} *{$label}* · Rp ".number_format((float) $transaction->amount, 0, ',', '.')."\n".
            "📝 {$desc}\n".
            '📁 '.($transaction->category->name ?? 'Lainnya').' · 📅 '.$transaction->transaction_date->format('d M Y').
            $balanceLine
        );
    }

    public function handleDeleteTransaction(): void
    {
        try {
            // Get last balance correction from context
            $contextService = new ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId());
            $lastCorrection = $contextService->getLastBalanceCorrection();

            // Transaksi terakhir MILIK PENGIRIM (akun keluarga: bukan milik anggota lain)
            $lastTransaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if ($lastCorrection) {
                $correctionTime = \Carbon\Carbon::parse($lastCorrection['created_at']);
                
                // If there is no last transaction, OR the balance correction is newer than the last transaction's creation time
                if (!$lastTransaction || $correctionTime->greaterThan($lastTransaction->created_at)) {
                    $this->undoBalanceCorrection($lastCorrection, $contextService);
                    return;
                }
            }

            if (! $lastTransaction) {
                $this->sendReply(
                    "⚠️ *Tidak ada transaksi untuk dihapus*\n\n".
                    'Anda belum memiliki transaksi yang tercatat.'
                );

                return;
            }

            $this->requestDeleteConfirmation($lastTransaction);

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
     * Undo a manual balance correction
     */
    protected function undoBalanceCorrection(array $lastCorrection, ConversationContextService $contextService): void
    {
        try {
            $balanceId = $lastCorrection['balance_id'];
            $oldBalance = $lastCorrection['old_balance'];
            $newBalance = $lastCorrection['new_balance'];
            
            $balance = Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->find($balanceId);
                
            if (!$balance) {
                $this->sendReply("⚠️ *Gagal membatalkan update saldo*\n\nDompet tidak ditemukan.");
                return;
            }
            
            // Batalkan SELISIH koreksi (bukan menimpa ke nilai lama), agar
            // transaksi yang tercatat setelah koreksi tidak ikut terhapus dari saldo.
            app(TransactionLedger::class)->setBalanceTo(
                $balance,
                (float) $balance->fresh()->balance + (float) $oldBalance - (float) $newBalance,
                Transaction::KIND_BALANCE_ADJUSTMENT,
                ['message_id' => $this->message->id, 'description' => 'Batal ubah saldo '.$balance->account_name]
            );

            $contextService->clearLastBalanceCorrection();
            
            $oldFormatted = number_format($oldBalance, 0, ',', '.');
            $newFormatted = number_format($newBalance, 0, ',', '.');
            
            $this->sendReply(
                "↩️ *Perubahan Saldo Dibatalkan!* ✅\n\n" .
                "👛 Dompet: *{$balance->account_name}*\n\n" .
                "📊 Saldo Dikembalikan:\n" .
                "   Sebelum pembatalan: Rp {$newFormatted}\n" .
                "   Kini kembali ke: *Rp {$oldFormatted}*\n\n" .
                "📅 Dibatalkan: " . now()->translatedFormat('d F Y H:i')
            );
        } catch (\Exception $e) {
            Log::error('Error undoing balance correction', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
            $this->sendReply("⚠️ *Gagal membatalkan update saldo*\n\nTerjadi kesalahan.");
        }
    }

    /**
     * Handle delete transaction by keyword/description
     * Example: "hapus beli kue", "hapus transaksi makan siang"
     */
    /**
     * "ayam 2 kg di hapus", "kopi dihapus", "bensin batal" — kata kunci DULU, perintah di
     * belakang. Mengembalikan kata kuncinya, atau null bila bukan bentuk itu.
     */
    public static function parseTrailingDeleteKeyword(string $text): ?string
    {
        if (preg_match('/[\r\n]/', trim($text))) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($text))));
        if (! preg_match(
            '/^(.{2,60}?)\s+(?:tolong\s+)?(?:di\s?hapus|hapus|hapusin|di\s?batalkan|di\s?batalin|batalkan|batalin|batal|di\s?cancel|di\s?delete)(?:\s+(?:aja|saja|ya|yah|dong|deh|kak|min))*\s*[.!]*$/u',
            $t, $m
        )) {
            return null;
        }

        $keyword = trim((string) preg_replace('/^(?:transaksi|catatan|yang|yg)\s+/u', '', trim($m[1])));
        // "3 hapus" (nomor daftar), kata perintah saja, atau kalimat tanya → bukan bentuk ini
        if (mb_strlen($keyword) < 3 || preg_match('/^\d+$/', $keyword)
            || ! preg_match('/\p{L}{2,}/u', $keyword)
            || str_contains($keyword, '?')
            || preg_match('/^(?:tolong|mohon|sudah|udah|belum|blm|kenapa|kok|apa|apakah|bisa|gimana|bagaimana|cara|mau|ingin|pengen|jangan|ga|gak|nggak|tidak|ya|iya|oke|ok|semua|semuanya|itu|ini|tadi|barusan|terakhir|salah|batal|hapus)\b/u', $keyword)) {
            return null;
        }

        return $keyword;
    }

    public function handleDeleteTransactionByKeyword(string $messageText, ?string $explicitKeyword = null): void
    {
        try {
            // Handle multi-line messages - only process first line
            $lines = preg_split('/[\r\n]+/', trim($messageText));
            $firstLine = $lines[0] ?? $messageText;

            // Extract keyword from message
            // Pattern: "hapus [transaksi] keyword"
            $keyword = $explicitKeyword
                ?? preg_replace('/^(hapus|delete|batal|batalkan)\s+(transaksi\s+)?/i', '', trim($firstLine));
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

            // Cari transaksi hari ini MILIK PENGIRIM (case-insensitive)
            $transaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
                ->whereRaw('LOWER(description) LIKE ?', ['%'.mb_strtolower($keyword).'%'])
                ->where($this->todayOrRecordedToday())
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            // "ayam 2 kg" harus menemukan "ayam 2kg 30.000" → bandingkan tanpa spasi
            if (! $transaction) {
                $compact = (string) preg_replace('/\s+/u', '', mb_strtolower($keyword));
                $transaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
                    ->whereRaw("REPLACE(LOWER(description), ' ', '') LIKE ?", ['%'.$compact.'%'])
                    ->where($this->todayOrRecordedToday())
                    ->orderBy('created_at', 'desc')
                    ->orderBy('id', 'desc')
                    ->first();
            }

            // Bukan milik pengirim → mungkin milik anggota lain (pemilik akun boleh menghapus)
            if (! $transaction) {
                $other = $this->findOtherMembersTransaction($keyword, 1);
                if ($other === false) {
                    return;
                }
                $transaction = $other;
            }

            if (! $transaction) {
                $this->sendReply(
                    "⚠️ *Transaksi tidak ditemukan*\n\n".
                    "Tidak ada transaksi dengan keyword \"*{$keyword}*\" hari ini.\n\n".
                    '💡 Ketik _lihat transaksi_ untuk melihat daftar transaksi Anda.'
                );

                return;
            }

            $this->requestDeleteConfirmation($transaction);

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

            // Pembaca nominal bersama: "2jt 500rb", "25 rebu", "lima puluh ribu",
            // dan "30" tanpa satuan = 30 ribu (sama seperti jalur pencatatan).
            $line = trim($firstLine);
            $keywordPos = mb_stripos($line, $keyword);
            if ($keywordPos !== false) {
                $tail = trim(mb_substr($line, $keywordPos + mb_strlen($keyword)));
                $shared = \App\Support\AmountParser::composite($tail) ?? \App\Support\AmountParser::parseAnswer($tail);
                if ($shared) {
                    $newAmount = (int) $shared;
                }
            }

            if ($newAmount <= 0) {
                $this->sendReply('⚠️ Nominal tidak valid. Silakan coba lagi.');

                return;
            }

            // Search for matching transaction today only
            $transaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
                ->where('description', 'LIKE', '%'.$keyword.'%')
                ->where($this->todayOrRecordedToday())
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $transaction) {
                $transaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
                    ->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($keyword).'%'])
                    ->where($this->todayOrRecordedToday())
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            // Tidak ada hari ini → transaksi terbaru berkata kunci itu dalam 30 hari terakhir
            // (dulu hanya hari ini: salah nominal kemarin tidak bisa dikoreksi lewat chat).
            if (! $transaction) {
                $transaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
                    ->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($keyword).'%'])
                    ->where('created_at', '>=', now()->subDays(30))
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            // Bukan milik pengirim → mungkin milik anggota lain (pemilik akun boleh mengoreksi)
            if (! $transaction) {
                $other = $this->findOtherMembersTransaction($keyword);
                if ($other === false) {
                    return;
                }
                $transaction = $other;
            }

            if (! $transaction) {
                $this->sendReply(
                    "⚠️ *Transaksi tidak ditemukan*\n\n".
                    "Tidak ada transaksi dengan keyword \"*{$keyword}*\" dalam 30 hari terakhir.\n\n".
                    '💡 Ketik _lihat transaksi_ untuk melihat daftar transaksi Anda.'
                );

                return;
            }

            // Load category
            $transaction->load('category');

            // Store old values
            $oldAmount = $transaction->amount;
            [$typeEmoji, $typeLabel] = $this->transactionTypeLabel($transaction);
            $category = $transaction->category->name ?? 'Lainnya';
            $desc = KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: '-';

            // Ubah nominal + koreksi saldo (TransactionLedger; pasangan transfer ikut)
            app(TransactionLedger::class)->update($transaction, ['amount' => $newAmount]);

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
                "📁 {$category}\n".
                '📅 '.$transaction->transaction_date->format('d M Y')."\n\n".
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
    /** Jumlah baris per halaman "lihat transaksi". */
    public const LIST_PAGE_SIZE = 10;

    /** "yg sebelumnya", "selanjutnya", "lihat lagi" → halaman berikut dari daftar terakhir. */
    public static function isListMoreCommand(string $text): bool
    {
        $t = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text)));
        $t = (string) preg_replace('/\s+/u', ' ', $t);

        return (bool) preg_match(
            '/^(?:(?:lihat|liat|tampilkan|tampilin|tunjukkan)\s+)?(?:(?:yang|yg)\s+)?(?:(?:transaksi\s+)?(?:sebelum\s?nya|selanjutnya|berikutnya|lain\s?nya|sisanya|lebih\s+lama)|lagi|lanjut|next|more)(?:\s+(?:dong|ya|kak|min|lagi|transaksi|nya))*$/u',
            $t
        );
    }

    /** Halaman berikut dari daftar terakhir. false bila tidak ada daftar aktif. */
    public function handleViewMoreTransactions(): bool
    {
        foreach ($this->listIndexKeys() as $key) {
            $payload = Cache::get($key);
            if (is_array($payload) && isset($payload['total'])) {
                $shown = count($payload['ids'] ?? []);
                if ($shown >= (int) $payload['total']) {
                    $this->sendReply('✅ Itu sudah semua transaksi ('.$payload['total'].").\n\n💡 Ketik _lihat transaksi_ untuk mengulang dari yang terbaru.");

                    return true;
                }
                $this->handleViewTransactions($shown, array_values($payload['ids']));

                return true;
            }
        }

        return false;
    }

    /**
     * @param  int  $offset  jumlah baris yang sudah ditampilkan (halaman lanjutan)
     * @param  list<int>  $previousIds  ID yang sudah ditampilkan → nomor urut berlanjut
     */
    public function handleViewTransactions(int $offset = 0, array $previousIds = []): void
    {
        try {
            $today = now()->toDateString();

            // Try today first, then last 7 days
            // Hanya transaksi terkonfirmasi (selaras dengan FinancialQueryService),
            // pending/ditolak tidak boleh ikut dihitung di Masuk/Keluar/Net.
            // Baris saldo dasar rekonsiliasi bukan transaksi user → tidak ditampilkan
            $notBaseline = fn ($q) => $q->whereNull('source')->orWhere('source', '!=', Transaction::KIND_RECONCILE_BASELINE);
            $base = fn () => Transaction::where('tenant_id', $this->message->tenant_id)
                ->where('status', 'confirmed')
                ->where($notBaseline);

            $scope = fn ($q) => $q->whereDate('transaction_date', $today);
            $title = '📋 *Transaksi Hari Ini*';
            if (! $base()->where($scope)->exists()) {
                $weekAgo = now()->subDays(7)->toDateString();
                $scope = fn ($q) => $q->whereDate('transaction_date', '>=', $weekAgo);
                $title = '📋 *Transaksi 7 Hari*';
            }

            $total = $base()->where($scope)->count();
            if ($total === 0) {
                $this->sendReply("📋 Belum ada transaksi 7 hari terakhir.\n\n💡 Catat: _beli makan 25rb_");

                return;
            }

            $transactions = $base()->where($scope)
                ->with('category')
                ->orderBy('transaction_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->skip($offset)
                ->limit(self::LIST_PAGE_SIZE)
                ->get();

            // Total = SELURUH transaksi periode itu, bukan hanya baris yang ditampilkan
            $totalIncome = (float) $base()->where($scope)->where('type', 'income')->sum('amount');
            $totalExpense = (float) $base()->where($scope)->where('type', 'expense')->sum('amount');

            $last = $offset + $transactions->count();
            $reply = $title.($total > self::LIST_PAGE_SIZE ? ' ('.($offset + 1)."–{$last} dari {$total})" : '')."\n";
            $orderedIds = $previousIds;

            foreach ($transactions as $i => $tx) {
                $typeEmoji = match ($tx->type) {
                    'income' => '💰',
                    'expense' => '💸',
                    default => '📝'
                };
                $amount = number_format($tx->amount, 0, ',', '.');
                // Keterangan tanpa nominal — nominal sudah tampil di kolom tersendiri
                $desc = KeywordNormalizer::stripAmountFromDescription($tx->description);

                // Compact: single line per item, bernomor (Ops — balas angka → aksi)
                $reply .= ($offset + $i + 1).". {$typeEmoji} *Rp {$amount}*";
                if ($desc) $reply .= " - {$desc}";
                $reply .= "\n";

                $orderedIds[] = (int) $tx->id;
            }

            $reply .= "\n";
            if ($totalIncome > 0) $reply .= '💰 Masuk: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
            if ($totalExpense > 0) $reply .= '💸 Keluar: Rp '.number_format($totalExpense, 0, ',', '.')."\n";
            $net = $totalIncome - $totalExpense;
            $reply .= "📊 Net: Rp ".number_format($net, 0, ',', '.');
            if ($last < $total) {
                $reply .= "\n\n➡️ Masih ada *".($total - $last).'* transaksi lagi — ketik *sebelumnya* untuk melihatnya.';
            }
            $reply .= "\n\n💡 Ketik *nomor* lalu perubahannya, mis. *3 ubah jadi 50rb* atau *3 hapus*";

            $this->sendReply($reply);

            // Ops — simpan payload daftar:
            // (a) kunci per pengirim → alur "balas nomor" (tanpa syarat flag,
            //     agar tetap berfungsi walau ReplyEditService nonaktif);
            // (b) kunci per messageId pesan keluar → untuk ReplyEditService.
            if ($orderedIds !== []) {
                // Di semua kunci identitas (LID ↔ nomor HP orang yang sama)
                foreach ($this->listIndexKeys() as $listKey) {
                    Cache::put($listKey, ['ids' => $orderedIds, 'total' => $total], now()->addSeconds((int) config('finwa_reply_edit.list_ttl', 3600)));
                }

                $waOut = Cache::get('wa_out_msgid:'.$this->message->message_id);
                if ($waOut) {
                    \App\Services\Transaction\ReplyEditService::storeListPayload(
                        (int) $this->message->tenant_id,
                        (string) $waOut,
                        $orderedIds
                    );
                }
            }

        } catch (\Exception $e) {
            Log::error('Error viewing transactions', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
            $this->sendReply("⚠️ Gagal memuat transaksi. Coba lagi nanti.");
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
            $lastTransaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
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

            // Simpan perubahan + koreksi saldo (TransactionLedger)
            $originalCategoryId = $lastTransaction->getOriginal('category_id');
            app(TransactionLedger::class)->save($lastTransaction);

            if ($newCategory && $newCategory->id != $originalCategoryId) {
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
            Transaction::withTrashed()->where('tenant_id', $tenantId)->forceDelete(); // hapus permanen (reset)

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
     * Resolve transaksi terakhir untuk user/tenant saat ini.
     *
     * @return Transaction|null
     */
    protected function resolveLastTransaction(): ?Transaction
    {
        $transaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
            ->orderBy('created_at', 'desc')
            ->first();

        if ($transaction) {
            $transaction->load('category');
        }

        return $transaction;
    }

    /**
     * Perintah pindah dompet sebuah transaksi (tanpa nominal):
     *   "ubah dompet jadi mandiri"            → transaksi terakhir
     *   "ganti dompet kopi ke gopay"          → transaksi berkata kunci "kopi"
     *   "pindahkan ke dompet bca"             → transaksi terakhir
     *   "salah dompet, harusnya pakai gopay"  → transaksi terakhir (explicit = false)
     *
     * @return array{keyword: ?string, wallet: string, explicit: bool}|null
     *                                                                      explicit: kata "dompet/rekening" disebut, jadi pasti perintah pindah dompet
     */
    public static function parseWalletChange(string $text): ?array
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
        $t = rtrim($t, " .!");
        if ($t === '' || \App\Services\WhatsApp\MessageSignals::hasMoneyAmount($t)) {
            return null;
        }

        $verb = '(?:ubah|ganti|edit|koreksi|pindah(?:kan|in)?)';
        $noun = '(?:dompet|rekening|rek|sumber\s+dana|pembayaran)(?:nya)?';
        $keyword = null;
        $wallet = null;
        $explicit = true;

        if (preg_match('/^'.$verb.'\s+'.$noun.'\s+(?:transaksi\s+)?(?:(.+?)\s+)?(?:jadi|ke|menjadi|pakai|pake)\s+(.+)$/u', $t, $m)) {
            [$keyword, $wallet] = [$m[1] ?? '', $m[2]];
        } elseif (preg_match('/^'.$verb.'\s+(?:transaksi\s+)?(?:(.+?)\s+)?(?:ke|jadi|pakai|pake)\s+(?:dompet|rekening|rek)\s+(.+)$/u', $t, $m)) {
            [$keyword, $wallet] = [$m[1] ?? '', $m[2]];
        } elseif (preg_match('/^(?:salah\s+(?:dompet|rekening|rek)[,.]?\s*)?(?:harusnya|seharusnya|mestinya)\s+(?:pakai|pake|dari|lewat|via|di|ke)\s+(?:dompet\s+|rekening\s+|rek\s+)?(.+)$/u', $t, $m)) {
            $wallet = $m[1];
            $explicit = str_contains($t, 'dompet') || (bool) preg_match('/\b(?:rekening|rek)\b/u', $t);
        }

        $wallet = trim((string) preg_replace('/^(?:dompet|rekening|rek)\s+/u', '', trim((string) $wallet)));
        if ($wallet === '') {
            return null;
        }

        $keyword = trim((string) $keyword);
        if (preg_match('/^(?:nya|terakhir|tadi|itu|ini|yang\s+tadi|yg\s+tadi|barusan)$/u', $keyword)) {
            $keyword = '';
        }

        return ['keyword' => $keyword !== '' ? $keyword : null, 'wallet' => $wallet, 'explicit' => $explicit];
    }

    /**
     * Pindahkan transaksi ke dompet lain. Saldo kedua dompet dikoreksi lewat ledger.
     * $target diisi bila transaksinya sudah diketahui (mis. dari balasan pesan).
     *
     * @return bool false bila ini ternyata bukan perintah pindah dompet (lanjut jalur lain)
     */
    public function handleWalletChange(array $command, ?Transaction $target = null): bool
    {
        $tenantId = (int) $this->message->tenant_id;
        $wallet = app(\App\Services\Wallet\WalletResolver::class)->match($tenantId, $command['wallet'], true)
            ?? app(BalanceService::class)->findOrCreateBalance($tenantId, $command['wallet'], null);

        if (! $wallet) {
            if (! $command['explicit']) {
                return false; // "harusnya pakai sendok" — bukan soal dompet
            }
            $names = Balance::where('tenant_id', $tenantId)->where('is_active', true)->pluck('account_name')
                ->map(fn ($n) => "• {$n}")->implode("\n");
            $this->sendReply(
                '⚠️ *Dompet "'.ucwords($command['wallet'])."\" belum terdaftar*\n\n".
                "Dompet Anda:\n{$names}\n\n".
                '💡 Buat dulu dengan: _tambah dompet '.ucwords($command['wallet']).'_'
            );

            return true;
        }

        if ($target === null) {
            if ($command['keyword'] !== null) {
                $target = $this->scopeToSender(Transaction::where('tenant_id', $tenantId))
                    ->whereRaw('LOWER(description) LIKE ?', ['%'.mb_strtolower($command['keyword']).'%'])
                    ->where('created_at', '>=', now()->subDays(30))
                    ->orderBy('created_at', 'desc')
                    ->first();
                if (! $target) {
                    $other = $this->findOtherMembersTransaction($command['keyword']);
                    if ($other === false) {
                        return true;
                    }
                    $target = $other;
                }
                if (! $target) {
                    $this->sendReply(
                        "⚠️ *Transaksi tidak ditemukan*\n\n".
                        "Tidak ada transaksi \"*{$command['keyword']}*\" dalam 30 hari terakhir.\n\n".
                        '💡 Ketik _lihat transaksi_ untuk melihat daftar.'
                    );

                    return true;
                }
            } else {
                $target = $this->resolveLastTransaction();
            }
        }

        if (! $target) {
            $this->sendReply("⚠️ *Tidak ada transaksi untuk diubah*\n\nAnda belum memiliki transaksi yang tercatat.");

            return true;
        }

        $target->loadMissing(['category', 'balance']);
        $desc = KeywordNormalizer::stripAmountFromDescription($target->description) ?: '-';
        $rp = 'Rp '.number_format((float) $target->amount, 0, ',', '.');

        // Hanya pemasukan/pengeluaran yang memang terikat ke satu dompet. Pindah saldo
        // antar dompet, hutang yang hanya dicatat, dan belanja paylater tidak punya
        // "dompet" untuk diganti.
        if (! in_array($target->type, ['income', 'expense'], true) || ! $target->balance_id) {
            $this->sendReply(
                "⚠️ *Dompet transaksi ini tidak bisa diubah*\n\n".
                "📝 {$desc} · {$rp}\n\n".
                'Transaksi ini tidak terikat ke satu dompet (pindah saldo, hutang yang hanya dicatat, atau belanja paylater).'
            );

            return true;
        }

        if ((int) $target->balance_id === (int) $wallet->id) {
            $this->sendReply("ℹ️ Transaksi _{$desc}_ ({$rp}) memang sudah di dompet *{$wallet->account_name}*.");

            return true;
        }

        $old = $target->balance;
        app(TransactionLedger::class)->update($target, ['balance_id' => $wallet->id]);

        Log::info('Transaction wallet changed via chat', [
            'message_id' => $this->message->id,
            'transaction_id' => $target->id,
            'from_balance_id' => $old?->id,
            'to_balance_id' => $wallet->id,
        ]);

        [$emoji, $label] = $this->transactionTypeLabel($target);
        $reply = "✏️ *Dompet Transaksi Diubah!*\n\n".
            "{$emoji} *{$label}* · {$rp}\n".
            "📝 {$desc}\n".
            '📅 '.$target->transaction_date->format('d M Y')."\n\n".
            '👛 '.($old->account_name ?? '-')." → *{$wallet->account_name}*\n\n";
        if ($old) {
            $reply .= "Saldo {$old->account_name}: Rp ".number_format((float) $old->fresh()->balance, 0, ',', '.')."\n";
        }
        $reply .= "Saldo {$wallet->account_name}: Rp ".number_format((float) $wallet->fresh()->balance, 0, ',', '.');

        $this->sendReply($reply);

        return true;
    }

    /**
     * "hanya dicatat" / "hutang lama": ubah hutang/piutang yang BARU SAJA dicatat
     * (dan terlanjur menggerakkan saldo) menjadi hanya dicatat. Saldo dikembalikan.
     */
    public function handleDebtRecordOnlyCommand(): void
    {
        $transaction = $this->resolveLastTransaction();
        $categoryType = $transaction?->category?->type;

        if (! $transaction || ! DebtCashMode::isNewDebtCategory($categoryType)) {
            $this->sendReply(
                "⚠️ Transaksi terakhir bukan pencatatan hutang/piutang.\n\n".
                "Untuk mencatat hutang lama tanpa mengubah saldo, tulis misalnya:\n".
                '• *hutang lama ke Budi 2jt*'."\n".
                '• *punya hutang SpayLater 2jt*'
            );

            return;
        }

        $noun = $categoryType === 'pendapatan_hutang' ? 'Hutang' : 'Piutang';
        $amount = 'Rp '.number_format((float) $transaction->amount, 0, ',', '.');

        if ($transaction->isDebtRecordOnly()) {
            $this->sendReply("👍 {$noun} {$amount} memang sudah *hanya dicatat* — saldo dompet tidak berubah.");

            return;
        }

        $balance = $transaction->balance;
        DebtCashMode::convert($transaction, false, null, ['via' => 'whatsapp', 'message_id' => $this->message->id]);
        $balance?->refresh();

        $reply = "✅ *{$noun} {$amount} sekarang hanya dicatat*\n\n".
            "{$noun} tetap ada di daftar hutang/piutang, tapi tidak dihitung sebagai uang ".
            ($categoryType === 'pendapatan_hutang' ? 'masuk' : 'keluar').'.';
        if ($balance) {
            $reply .= "\n👛 Saldo {$balance->account_name} dikembalikan: Rp ".number_format((float) $balance->balance, 0, ',', '.');
        }
        $this->sendReply($reply);
    }

    /**
     * Handle AMBIGUOUS edit command — ask user for clarification.
     *
     * Dipanggil oleh fast-path 1.6af2.6 saat user mengirim perintah
     * seperti "Ubah kategori", "Ganti nominal", dll tanpa nilai spesifik.
     *
     * Menyimpan state pending_edit dan mengirim pertanyaan klarifikasi.
     */
    public function askBackForEdit(string $field): void
    {
        try {
            $transaction = $this->resolveLastTransaction();

            if (! $transaction) {
                $this->sendReply("⚠️ Tidak ada transaksi terakhir yang bisa diubah.");
                return;
            }

            // Simpan state pending_edit
            $contextService = new ConversationContextService(
                $this->message->tenant_id,
                $this->getAttributionSenderId()
            );
            $contextService->storePendingEdit($transaction->id, $field);

            // Format info transaksi terakhir
            $amount = number_format($transaction->amount, 0, ',', '.');
            $category = 'Lainnya'; // Default aman
            try {
                if ($transaction->category) {
                    $category = $transaction->category->name;
                }
            } catch (\Exception $catEx) {
                Log::warning('Gagal load kategori', ['error' => $catEx->getMessage()]);
            }
            $typeLabel = $transaction->type === 'income' ? 'Pemasukan' : 'Pengeluaran';

            // Template pertanyaan per field
            $labels = [
                'category' => "Mau diubah ke kategori apa?\n\nContoh: _Hiburan_, _Transport_, _Makanan_",
                'amount'   => "Mau diubah jadi berapa?\n\nContoh: _50rb_, _1,5jt_",
                'date'     => "Mau diubah ke tanggal berapa?\n\nContoh: _kemarin_, _11 des 2025_",
                'type'     => "Mau diubah jadi *Pemasukan* atau *Pengeluaran*?",
            ];

            $this->sendReply(
                "✏️ *Ubah Transaksi*\n\n".
                "Transaksi terakhir:\n".
                "• 📁 {$category}\n".
                "• 💰 Rp {$amount}\n".
                "• 🔄 {$typeLabel}\n\n".
                "━━━━━━━━━━━━━━━\n\n".
                ($labels[$field] ?? 'Silakan sebutkan perubahan yang diinginkan.')
            );

            Log::info('Ask-back for ambiguous edit', [
                'transaction_id' => $transaction->id,
                'field'          => $field,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in askBackForEdit', [
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'field'   => $field,
            ]);

            $this->sendReply(
                "⚠️ *Gagal memproses permintaan ubah*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
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

            // CEK: Apakah ini jawaban dari pertanyaan ask-back?
            $pendingEdit = $contextService->getPendingEdit();
            if ($pendingEdit && $this->isAnswerOnly($messageText)) {
                $this->handlePendingEditAnswer($pendingEdit, $messageText, $contextService);
                return;
            }

            // Ops — "balas nomor daftar": "2 ubah jadi 35rb".
            // Nomor baris menunjuk transaksi target, BUKAN nominal.
            $transaction = $this->resolveListIndexTarget($messageText);

            if ($transaction) {
                // Buang indeks dari teks → "35rb" jadi satu-satunya nominal
                $messageText = ltrim(
                    preg_replace('/^\s*\d{1,2}\s*[.)]?\s*/u', '', $messageText),
                    ' ,'
                );
            }

            $lastTransactionId = $transaction?->id;

            if (! $transaction) {
                $lastTransactionId = $contextService->getLastTransactionId();
            }

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

            // Find the transaction (sudah diresolusi dari daftar bila ada)
            $transaction = $transaction ?: Transaction::find($lastTransactionId);

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
            if (preg_match('/(\d+(?:[.,]\d+)*)\s*(rb|ribu|k|jt|juta|rbu)?/i', $textLower, $matches)) {
                // "1.500.000" → 1500000 (dulu terbaca 1,5); "1,5" → 1.5 tetap
                $number = preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $matches[1])
                    ? (float) \App\Support\Rupiah::parse($matches[1])
                    : (float) str_replace(',', '.', $matches[1]);
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
            // \b wajib: tanpa itu "termasuk pengeluaran" ikut tertangkap lewat
            // potongan "masuk" dan dibaca sebagai kategori bernama "pengeluaran".
            if (preg_match('/\b(?:kategori|masuk|pindah(?:in)?|ubah|ganti)\s+(?:ke\s+|jadi\s+)?([a-zA-Z\s]+)/i', $textLower, $catMatches)) {
                // Ignore "jadi 50rb" or "jadi rp"
                $candidate = trim($catMatches[1]);
                // Ops — tolak kata sambung/frasa umum: "jadi", "ke jadi", "menjadi",
                // agar "35rb" tidak dianggap gagal-ubah-kategori jadi "jadi".
                if (! preg_match('/^(\d+|rp|rupiah|tanggal|tgl|harga|nominal|kategori|tipe|type|transaksi|jumlah)/i', $candidate)
                    && ! preg_match('/^(jadi|ke|menjadi|sama|dengan|yang|nominal|rupiah|rp|ke\b)/i', $candidate)
                    && strlen($candidate) > 2) {
                    $newCategoryName = $candidate;
                }
            }

            // Detect type change request: "ganti jadi pemasukan", "ubah ke pengeluaran"
            $newType = null;
            $typeChangeKeywords = config('finwa_category_rules.type_change_keywords', []);
            if (preg_match(
                '/^(?:ganti|ubah|edit|koreksi|jadikan)\s+(?:jadi|ke|menjadi)?\s*[\'"]?([a-z\s]+?)[\'"]?\s*$/i',
                $textLower,
                $typeMatch
            )) {
                $typeCandidate = trim($typeMatch[1] ?? '');
                $newType = $typeChangeKeywords[$typeCandidate] ?? null;
            }
            // Juga cek pola: "... jadi 'pemasukan'" atau "... ke income"
            if (! $newType && preg_match(
                '/(?:jadi|ke|menjadi)\s+[\'"]?(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)[\'"]?/i',
                $textLower,
                $typeMatch2
            )) {
                $typeCandidate2 = trim($typeMatch2[1] ?? '');
                $newType = $typeChangeKeywords[$typeCandidate2] ?? null;
            }
            // Koreksi arah tanpa kata sambung: "termasuk pengeluaran", "itu pemasukan".
            // Hanya bila pesan tidak membawa nominal/kategori baru, supaya kalimat
            // seperti "koreksi pengeluaran makan jadi 50rb" tidak ikut ganti tipe.
            if (! $newType && ! $newAmount && ! $newCategoryName) {
                $newType = self::detectBareTypeWord($textLower);
            }

            if (! $newAmount && ! $newCategoryName && ! $newType) {
                $this->sendReply(
                    "⚠️ *Koreksi tidak jelas*\n\n".
                    "Silakan sebutkan nominal atau kategori yang benar.\n".
                    "Contoh:\n".
                    "• _salah harusnya 50rb_\n".
                    '• _ganti kategori jadi hiburan_'
                );

                return;
            }

            // Koreksi tipe saja padahal arahnya sudah benar → beri tahu, jangan
            // balas "Dikoreksi" tanpa satu pun perubahan.
            if ($newType && ! $newAmount && ! $newCategoryName && $newType === $transaction->type) {
                $this->sendReply(
                    '👍 Transaksi ini memang sudah tercatat sebagai *'
                    .($newType === 'income' ? 'Pemasukan' : 'Pengeluaran')."*.\n\n"
                    .'📝 '.($transaction->description ?? '-')
                );

                return;
            }

            $replyMsg = "✅ *Transaksi Dikoreksi*\n\n";

            // Handle Amount Update
            if ($newAmount) {
                $oldAmount = $transaction->amount;

                // Nominal + saldo + pasangan transfer (TransactionLedger)
                app(TransactionLedger::class)->update($transaction, ['amount' => $newAmount]);

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

            // Handle Type Change (income ↔ expense)
            if ($newType && $newType !== $transaction->type) {
                $oldType = $transaction->type;

                // Ubah tipe + remap kategori; saldo dikoreksi oleh TransactionLedger
                app(TransactionLedger::class)->update($transaction, [
                    'type' => $newType,
                    'category_id' => $this->remapCategoryForType($transaction, $newType),
                ]);

                $typeLabel = fn (string $t) => $t === 'income' ? 'Pemasukan' : 'Pengeluaran';
                $replyMsg .= "🔄 Tipe: ~{$typeLabel($oldType)}~ ➝ *{$typeLabel($newType)}*\n";
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

    // ==========================================
    // HELPER: Konsumsi jawaban ask-back
    // ==========================================

    /**
     * Cek apakah pesan adalah jawaban sederhana (bukan perintah baru).
     *
     * Kriteria: pesan pendek (< 60 karakter) tanpa kata kunci perintah
     * seperti "hapus", "ubah", "tambah", "baru", dll.
     */
    protected function isAnswerOnly(string $text): bool
    {
        $textLower = strtolower(trim($text));

        // Terlalu panjang → kemungkinan bukan jawaban singkat
        if (strlen($textLower) > 60) {
            return false;
        }

        // Ada kata kunci perintah → ini perintah baru, bukan jawaban
        $commandKeywords = [
            'hapus', 'delete', 'batal', 'tambah', 'baru', 'buat',
            'transfer', 'kirim', 'bayar', 'beli', 'cek', 'lihat',
            'laporan', 'ringkasan', 'saldo', 'budget', 'anggaran',
        ];

        foreach ($commandKeywords as $keyword) {
            if (str_starts_with($textLower, $keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ops — baca pola "balas nomor": "2 ubah jadi 35rb", "2.", "2)".
     *
     * Hanya pola yang diawali angka 1-2 digit + kata perintah ("ubah/ganti/
     * edit/jadi/ke/koreksi") yang dianggap indeks. "50rb" atau "20000"
     * TANPA kata perintah tidak dianggap indeks.
     */
    public static function parseListIndex(string $text): ?int
    {
        if (! preg_match('/^\s*(\d{1,2})\s*[.)]?\s*(?=\S)/u', $text, $m)) {
            return null;
        }

        $rest = trim(substr($text, strlen($m[0])));

        // Sisa pesan harus memuat kata perintah perubahan
        if (! preg_match('/\b(ubah|ganti|edit|koreksi|jadi|ke|menjadi|rubah|tolong)\b/i', $rest)) {
            return null;
        }

        $n = (int) $m[1];

        return $n >= 1 ? $n : null;
    }

    /**
     * "1 hapus", "1. hapus aja", "hapus 1", "hapus no 1", "hapus nomor 1" → 1.
     * Nomor merujuk daftar terakhir dari "lihat transaksi".
     */
    public static function parseListDeleteIndex(string $text): ?int
    {
        $t = mb_strtolower(trim($text));
        $verb = '(?:hapus|hapusin|delete|del|buang|remove|dihapus)';
        if (preg_match('/^(\d{1,2})\s*[.)]?\s*'.$verb.'(?:\s+(?:aja|saja|ya|dong|deh|transaksi|itu|ini|yang|yg))*\s*[.!]*$/u', $t, $m)
            || preg_match('/^'.$verb.'\s+(?:transaksi\s+)?(?:no\.?|nomor|nomer|nmr|#)?\s*(\d{1,2})\s*[.!]*$/u', $t, $m)) {
            $n = (int) $m[1];

            return $n >= 1 ? $n : null;
        }

        return null;
    }

    /**
     * Kunci daftar bernomor terakhir untuk SEMUA identitas pengirim (LID ↔ nomor HP).
     *
     * @return list<string>
     */
    protected function listIndexKeys(): array
    {
        return array_map(
            fn (string $identity) => 'finwa_reply_list_idx:'.$this->message->tenant_id.':'.$identity,
            \App\Support\SenderIdentity::keys($this->message, $this->getAttributionSenderId())
        );
    }

    /** @return list<int> urutan ID transaksi pada daftar terakhir yang dilihat pengirim */
    protected function listIndexIds(): array
    {
        foreach ($this->listIndexKeys() as $key) {
            $payload = Cache::get($key);
            if (is_array($payload) && ! empty($payload['ids'])) {
                return array_values($payload['ids']);
            }
        }

        return [];
    }

    /**
     * "1 hapus" setelah "lihat transaksi" → konfirmasi hapus transaksi nomor 1 (Ya/Tidak).
     */
    public function handleListIndexDelete(int $n): void
    {
        $ids = $this->listIndexIds();
        if ($ids === []) {
            $this->sendReply("ℹ️ Daftar transaksi belum dibuka atau sudah kedaluwarsa.\n\nKetik *lihat transaksi* dulu, lalu *{$n} hapus*.");

            return;
        }
        if (! isset($ids[$n - 1])) {
            $this->sendReply("⚠️ Nomor *{$n}* tidak ada di daftar (1–".count($ids).').');

            return;
        }

        $transaction = Transaction::withTrashed()->where('tenant_id', $this->message->tenant_id)->find($ids[$n - 1]);
        if (! $transaction || $transaction->trashed()) {
            $this->sendReply("ℹ️ Transaksi nomor *{$n}* sudah dihapus.\n\n↩️ Ketik *kembalikan* untuk memulihkannya.");

            return;
        }
        if ($transaction->source === Transaction::KIND_RECONCILE_BASELINE) {
            $this->sendReply('ℹ️ Baris saldo dasar (rekonsiliasi) tidak bisa dihapus.');

            return;
        }

        $this->requestDeleteConfirmation($transaction);
    }

    /**
     * Ops — resolusi target dari pesan "balas nomor" (daftar riwayat bernomor).
     *
     * @return Transaction|null null bila bukan balasan nomor / payload kedaluwarsa
     */
    protected function resolveListIndexTarget(string $messageText): ?Transaction
    {
        $n = self::parseListIndex($messageText);

        if ($n === null) {
            return null;
        }

        $ids = $this->listIndexIds();

        if ($ids === [] || ! isset($ids[$n - 1])) {
            return null; // nomor di luar daftar → biarkan flow lama memproses
        }

        $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
            ->where('id', $ids[$n - 1])
            ->first();

        if (! $transaction) {
            return null;
        }

        Log::info('Balas nomor daftar → target transaksi', [
            'message_id' => $this->message->id,
            'index' => $n,
            'transaction_id' => $transaction->id,
        ]);

        return $transaction;
    }

    /**
     * Proses jawaban user dari pertanyaan ask-back.
     *
     * @param array $pendingEdit Data pending_edit dari context
     * @param string $answer Jawaban user (misal: "Hiburan", "50rb", "pemasukan")
     * @param ConversationContextService $contextService Service untuk clear state
     */
    protected function handlePendingEditAnswer(
        array $pendingEdit,
        string $answer,
        ConversationContextService $contextService
    ): void {
        try {
            $transaction = Transaction::find($pendingEdit['transaction_id']);
            $field = $pendingEdit['awaiting_field'];

            if (! $transaction) {
                $contextService->clearPendingEdit();
                $this->sendReply("⚠️ Transaksi tidak ditemukan atau sudah dihapus.");
                return;
            }

            // Proses berdasarkan field yang ditanya
            switch ($field) {
                case 'category':
                    $this->applyCategoryChange($transaction, $answer);
                    break;

                case 'amount':
                    $this->applyAmountChange($transaction, $answer);
                    break;

                case 'type':
                    $this->applyTypeChange($transaction, $answer);
                    break;

                case 'date':
                    $this->sendReply("📅 Perubahan tanggal akan segera hadir!");
                    break;

                default:
                    $this->sendReply("⚠️ Field '{$field}' belum didukung untuk edit cepat.");
            }

            // Hapus state pending_edit setelah diproses
            $contextService->clearPendingEdit();
        } catch (\Exception $e) {
            Log::error('Error handling pending edit answer', [
                'error'   => $e->getMessage(),
                'pending' => $pendingEdit,
                'answer'  => $answer,
            ]);

            $contextService->clearPendingEdit();
            $this->sendReply("⚠️ Gagal memproses jawaban. Silakan coba lagi.");
        }
    }

    /**
     * Terapkan perubahan kategori dari jawaban ask-back.
     */
    protected function applyCategoryChange(Transaction $transaction, string $categoryName): void
    {
        $oldCategoryName = $transaction->category->name ?? 'Lainnya';

        // Cari kategori berdasarkan nama
        $category = \App\Models\Category::where('tenant_id', $transaction->tenant_id)
            ->where(function ($query) use ($categoryName) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($categoryName).'%']);
            })
            ->first();

        if (! $category) {
            $this->sendReply("⚠️ Kategori '{$categoryName}' tidak ditemukan.\n\nCoba sebutkan nama lain, misal: _Hiburan_, _Transport_, _Makanan_.");
            return;
        }

        // Pastikan tipe kategori konsisten dengan transaksi
        $expectedPrefix = $transaction->type === 'income' ? 'pendapatan_' : 'pengeluaran_';
        if (! str_starts_with($category->type, $expectedPrefix)) {
            $this->sendReply(
                "⚠️ Kategori '{$category->name}' tidak cocok untuk tipe *".
                ($transaction->type === 'income' ? 'Pemasukan' : 'Pengeluaran')."*.\n\n".
                'Silakan pilih kategori dengan tipe yang sesuai.'
            );
            return;
        }

        // Update kategori
        $transaction->category_id = $category->id;
        $transaction->save();

        $this->sendReply(
            "✅ *Kategori Diperbarui*\n\n".
            "📁 ~{$oldCategoryName}~ ➝ *{$category->name}*\n\n".
            "_Data berhasil diperbarui_"
        );

        Log::info('Category updated via ask-back', [
            'transaction_id' => $transaction->id,
            'old_category'   => $oldCategoryName,
            'new_category'   => $category->name,
        ]);
    }

    /**
     * Terapkan perubahan nominal dari jawaban ask-back.
     */
    /**
     * Nominal dari jawaban singkat user: "50rb" | "1,5jt" | "150.000" | "150000" | "50".
     * Angka polos < 1000 tanpa satuan dianggap ribuan ("50" → 50.000);
     * angka ≥ 1000 dipakai apa adanya ("150000" → 150.000, bukan 150 juta).
     */
    public static function parseAnswerAmount(string $text): ?int
    {
        return \App\Support\AmountParser::parseAnswer($text);
    }

    /**
     * Koreksi nominal transaksi milik pengirim yang dicatat ≤ 24 jam lalu dengan nominal $old.
     * true bila ada yang dikoreksi.
     */
    public function correctRecentAmount(int $old, int $new): bool
    {
        $transaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
            ->where('amount', $old)
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        if (! $transaction) {
            return false;
        }

        $this->applyAmountChange($transaction, (string) $new);

        return true;
    }

    protected function applyAmountChange(Transaction $transaction, string $amountText): void
    {
        // Extract number dari teks (sama seperti di handleEditWithContext)
        $newAmount = self::parseAnswerAmount($amountText);

        if (! $newAmount || $newAmount <= 0) {
            $this->sendReply("⚠️ Tidak bisa membaca nominal '{$amountText}'.\n\nContoh: _50rb_, _1,5jt_, _100000_.");
            return;
        }

        $oldAmount = $transaction->amount;

        // Nominal + saldo (TransactionLedger)
        app(TransactionLedger::class)->update($transaction, ['amount' => $newAmount]);

        $this->sendReply(
            "✅ *Nominal Diperbarui*\n\n".
            '💰 ~'.number_format($oldAmount, 0, ',', '.').'~ ➝ *'.number_format($newAmount, 0, ',', '.')."*\n\n".
            '_Data berhasil diperbarui_'
        );

        Log::info('Amount updated via ask-back', [
            'transaction_id' => $transaction->id,
            'old_amount'     => $oldAmount,
            'new_amount'     => $newAmount,
        ]);
    }

    /**
     * Terapkan perubahan tipe dari jawaban ask-back.
     */
    protected function applyTypeChange(Transaction $transaction, string $typeAnswer): void
    {
        $typeChangeKeywords = config('finwa_category_rules.type_change_keywords', []);
        $textLower = strtolower(trim($typeAnswer));

        // Cek apakah jawaban mengandung kata kunci tipe
        $newType = null;
        foreach ($typeChangeKeywords as $keyword => $type) {
            if (str_contains($textLower, $keyword)) {
                $newType = $type;
                break;
            }
        }

        if (! $newType || $newType === $transaction->type) {
            $this->sendReply(
                "⚠️ Tidak bisa mengenali tipe '{$typeAnswer}'.\n\n".
                'Jawab dengan: *pemasukan* atau *pengeluaran*.'
            );
            return;
        }

        // Terapkan perubahan tipe (saldo dikoreksi oleh TransactionLedger)
        $oldType = $transaction->type;
        app(TransactionLedger::class)->update($transaction, [
            'type' => $newType,
            'category_id' => $this->remapCategoryForType($transaction, $newType),
        ]);

        $typeLabel = fn (string $t) => $t === 'income' ? 'Pemasukan' : 'Pengeluaran';
        $this->sendReply(
            "✅ *Tipe Diperbarui*\n\n".
            "🔄 ~{$typeLabel($oldType)}~ ➝ *{$typeLabel($newType)}*\n\n".
            '_Data berhasil diperbarui_'
        );

        Log::info('Type updated via ask-back', [
            'transaction_id' => $transaction->id,
            'old_type'       => $oldType,
            'new_type'       => $newType,
        ]);
    }

    /**
     * Remap kategori saat tipe transaksi berubah (income ↔ expense).
     *
     * Kategori harus ikut berpindah prefix agar konsisten:
     * - pengeluaran_bahan_makanan → pendapatan_bahan_makanan (jika ada)
     * - Jika padanan tidak ada di enum, fallback ke *_lainnya
     */
    protected function remapCategoryForType(Transaction $transaction, string $newType): int
    {
        $category = $transaction->category;
        if (! $category) {
            return $this->defaultCategoryIdForType($newType);
        }

        $oppositePrefix = $newType === 'income' ? 'pengeluaran_' : 'pendapatan_';
        $targetPrefix   = $newType === 'income' ? 'pendapatan_'    : 'pengeluaran_';

        // Jika kategori sudah punya prefix yang benar, tidak perlu diubah
        if (! str_starts_with($category->type, $oppositePrefix)) {
            return $category->id;
        }

        // Coba cari padanan dengan prefix baru
        $candidateType = $targetPrefix . substr($category->type, strlen($oppositePrefix));

        $target = Category::where('tenant_id', $this->message->tenant_id)
            ->where('type', $candidateType)
            ->first();

        // Fallback wajib: bila padanan tidak ada, gunakan kategori "lainnya"
        return $target?->id ?? $this->defaultCategoryIdForType($newType);
    }

    /**
     * Ambil ID kategori default untuk tipe tertentu.
     */
    protected function defaultCategoryIdForType(string $type): int
    {
        $defaultType = $type === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';

        $category = Category::where('tenant_id', $this->message->tenant_id)
            ->where('type', $defaultType)
            ->first();

        // Fallback terakhir: ambil kategori pertama dengan tipe yang sesuai
        if (! $category) {
            $category = Category::where('tenant_id', $this->message->tenant_id)
                ->where('type', 'LIKE', $type === 'income' ? 'pendapatan_%' : 'pengeluaran_%')
                ->first();
        }

        return $category?->id ?? 0;
    }

    /**
     * Ops — apakah ini hanya kata perintah (bukan deskripsi transaksi)?
     * "ubah kategori ..." → keyword "ubah" → target = transaksi terakhir.
     */
    public static function isCommandOnlyKeyword(string $keyword): bool
    {
        $keyword = mb_strtolower(trim($keyword));

        if ($keyword === '') {
            return true;
        }

        $commands = [
            'ubah', 'ganti', 'edit', 'rubah', 'revisi', 'koreksi', 'perbaiki',
            'pindah', 'masuk', 'masukin', 'kategori', 'tolong', 'mohon', 'plis',
            'ini', 'itu', 'yang', 'terakhir', 'baru', 'tadi', 'nomor',
        ];

        return in_array($keyword, $commands, true);
    }

    /**
     * Koreksi arah transaksi yang SELURUH pesannya hanya menyebut tipe:
     * "termasuk pengeluaran", "itu pemasukan", "harusnya pengeluaran ya".
     *
     * Dipakai ProcessIncomingMessage untuk merutekan pesan semacam ini ke
     * handleEditWithContext; tanpa itu pesan jatuh ke "Format Tidak Dikenali".
     * Kata pembuka wajib ada supaya "pengeluaran" polos tetap dibaca sebagai
     * query ("pengeluaran" = minta daftar pengeluaran).
     */
    public static function isTypeOnlyCorrection(string $text): bool
    {
        $textLower = mb_strtolower(trim($text));

        return (bool) preg_match(
            '/^(?:itu|ini|yang\s+itu|yg\s+itu|termasuk|masuk(?:in|kan)?|dicatat|jadikan|'
            .'harusnya|seharusnya|mestinya|semestinya|bukan|kategorinya|tipenya|jenisnya)'
            .'\b[\s,.:-]*(?:ke|jadi|menjadi|sebagai)?\s*'
            .'(?:pemasukan|pendapatan|income|uang\s+masuk|pengeluaran|expense|uang\s+keluar)'
            .'\b[\s,.!]*(?:ya|yah|dong|deh|kok|aja|saja|sih|nya)?[\s.!?]*$/iu',
            $textLower
        );
    }

    /**
     * Ambil arah transaksi dari kata tipe yang berdiri sendiri di dalam pesan
     * koreksi ("termasuk pengeluaran" → expense). Kata sambung tidak wajib,
     * jadi hanya dipakai saat pesan tidak membawa nominal/kategori baru.
     */
    protected static function detectBareTypeWord(string $textLower): ?string
    {
        if (! preg_match(
            '/\b(pemasukan|pendapatan|income|uang\s+masuk|pengeluaran|expense|uang\s+keluar)\b/iu',
            $textLower,
            $m
        )) {
            return null;
        }

        $word = preg_replace('/\s+/', ' ', mb_strtolower(trim($m[1])));

        return config('finwa_category_rules.type_change_keywords', [])[$word] ?? null;
    }

    /**
     * Ops — bersihkan kandidat kategori dari partikel & kata penghubung.
     * "nya jadi makanan" → "makanan"; "ke kesehatan" → "kesehatan".
     */
    public static function cleanCategoryCandidate(string $candidate): string
    {
        $candidate = preg_replace('/\b(nya|nyaa|aja|saja|dong|yaa|ya|lah|kah)\b/iu', ' ', $candidate);
        $candidate = preg_replace('/^\s*(?:jadi|ke|menjadi|pindah(?:in)?|ganti|ubah)\s+/iu', ' ', $candidate);

        return trim(preg_replace('/\s+/u', ' ', (string) $candidate));
    }

    /**
     * Terapkan koreksi kategori ke transaksi (lookup nama/slug → pola alias →
     * update + rekam koreksi + balasan sukses).
     *
     * Dipakai alur WhatsApp biasa maupun alur reply-edit (target sudah ditentukan).
     */
    public function applyCategoryCorrection(Transaction $transaction, string $catCandidate): void
    {
        $transaction->load('category');

        $newCategory = Category::where('tenant_id', $this->message->tenant_id)
            ->where(function ($q) use ($catCandidate) {
                $q->where('name', 'LIKE', '%'.$catCandidate.'%')
                    ->orWhere('slug', 'LIKE', '%'.$catCandidate.'%');
            })
            ->first();

        if (!$newCategory) {
            $categoryPatterns = [
                'makan' => 'Makanan', 'minum' => 'Makanan', 'makanan' => 'Makanan',
                'transport' => 'Transport', 'belanja' => 'Belanja',
                'kesehatan' => 'Kesehatan', 'hiburan' => 'Hiburan',
                'tagihan' => 'Utilitas', 'utilitas' => 'Utilitas',
                'gaji' => 'Gaji', 'bonus' => 'Bonus',
                'pendidikan' => 'Pendidikan', 'sekolah' => 'Pendidikan',
                'otomotif' => 'Otomotif', 'kendaraan' => 'Otomotif',
                'baby' => 'Baby', 'bayi' => 'Baby',
                'hewan' => 'Hewan', 'kucing' => 'Hewan', 'anjing' => 'Hewan', 'pet' => 'Hewan',
                'gadget' => 'Gadget', 'elektronik' => 'Gadget', 'hp' => 'Gadget', 'laptop' => 'Gadget',
            ];

            foreach ($categoryPatterns as $keyword => $catNamePartial) {
                if (str_contains($catCandidate, $keyword)) {
                    $newCategory = Category::where('tenant_id', $this->message->tenant_id)
                        ->where('name', 'LIKE', '%'.$catNamePartial.'%')
                        ->first();
                    if ($newCategory) break;
                }
            }
        }

        if (!$newCategory) {
            $this->sendReply(
                "⚠️ *Kategori tidak ditemukan*\n\n".
                "Kategori \"{$catCandidate}\" tidak ditemukan.\n\n".
                "💡 Ketik _help_ untuk melihat daftar kategori yang tersedia."
            );
            return;
        }

        $oldCategoryName = $transaction->category->name ?? 'Lainnya';
        $oldCategoryType = $transaction->category->type ?? 'pengeluaran_lainnya';
        $transaction->category_id = $newCategory->id;
        $transaction->save();

        $correctionService = app(CategoryCorrectionService::class);
        $correctionService->recordCorrection(
            $this->message->tenant_id,
            $transaction->description ?? '',
            $oldCategoryType,
            $newCategory->type,
            $transaction->source,
            (float) $transaction->amount
        );

        $amount = number_format($transaction->amount, 0, ',', '.');
        $typeEmoji = $transaction->type === 'income' ? '💰' : '💸';

        $this->sendReply(
            "✅ *Kategori Berhasil Diubah!*\n\n".
            "{$typeEmoji} Rp {$amount}\n".
            "📝 {$transaction->description}\n\n".
            "📁 Kategori:\n".
            "   ~{$oldCategoryName}~ ➝ *{$newCategory->name}*\n\n".
            "💡 Perubahan ini akan dipelajari oleh sistem."
        );

        Log::info('Category corrected via WhatsApp pattern', [
            'transaction_id' => $transaction->id,
            'description' => $transaction->description,
            'old_category' => $oldCategoryName,
            'new_category' => $newCategory->name,
            'candidate' => $catCandidate,
        ]);
    }

    /**
     * Handle category correction by matching transaction keyword + category name.
     * Example: "air mineral kategori makanan & minuman"
     *          "beli susu ganti ke kesehatan"
     *          "ubah kategori nya jadi makanan" (keyword '' → transaksi terakhir)
     */
    public function handleEditTransactionWithCorrection(?string $txKeyword, string $catCandidate): void
    {
        try {
            $normalizedKeyword = mb_strtolower(trim((string) $txKeyword));

            $transaction = null;

            if ($normalizedKeyword !== '') {
                $transaction = $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))
                    ->whereRaw('LOWER(description) LIKE ?', ['%'.$normalizedKeyword.'%'])
                    ->orderByDesc('created_at')
                    ->first();
            }

            // Ops — tanpa deskripsi ("ubah kategori nya jadi makanan"):
            // koreksi ditujukan ke transaksi terakhir yang dicatat.
            // Keyword ADA tapi tidak ditemukan → jangan fallback diam-diam.
            if (! $transaction && $normalizedKeyword === '') {
                $contextService = new ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId());
                $lastId = $contextService->getLastTransactionId();

                $transaction = $lastId
                    ? Transaction::where('tenant_id', $this->message->tenant_id)->find($lastId)
                    : $this->scopeToSender(Transaction::where('tenant_id', $this->message->tenant_id))->orderByDesc('created_at')->first();
            }

            if (!$transaction) {
                $this->sendReply(
                    "⚠️ *Transaksi tidak ditemukan*\n\n".
                    ($normalizedKeyword !== ''
                        ? "Tidak ada transaksi dengan deskripsi \"{$txKeyword}\".\n\n"
                        : "Belum ada transaksi yang bisa dikoreksi.\n\n").
                    "💡 Ketik _lihat transaksi_ untuk melihat daftar transaksi."
                );
                return;
            }

            $this->applyCategoryCorrection($transaction, $catCandidate);

        } catch (\Exception $e) {
            Log::error('Error in handleEditTransactionWithCorrection', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
            $this->sendReply(
                "⚠️ *Gagal mengubah kategori*\n\n".
                "Terjadi kesalahan. Silakan coba lagi."
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

            $transaction = $this->scopeToSender(\App\Models\Transaction::where('tenant_id', $this->message->tenant_id))
                ->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($keyword).'%'])
                ->where($this->todayOrRecordedToday())
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $transaction) {
                return ['success' => false, 'error' => "'{$keyword}' tidak ditemukan"];
            }

            $description = $transaction->description;
            $amount = number_format($transaction->amount, 0, ',', '.');

            // Soft delete + kembalikan saldo (TransactionLedger)
            app(TransactionLedger::class)->delete($transaction);

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

            $transaction = $this->scopeToSender(\App\Models\Transaction::where('tenant_id', $this->message->tenant_id))
                ->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($keyword).'%'])
                ->where($this->todayOrRecordedToday())
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $transaction) {
                return ['success' => false, 'error' => "'{$keyword}' tidak ditemukan"];
            }

            $oldAmount = $transaction->amount;

            // Nominal + saldo (TransactionLedger)
            app(TransactionLedger::class)->update($transaction, ['amount' => $newAmount]);

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
