<?php

namespace App\Jobs;

use App\Models\LlmExtractionLog;
use App\Models\Message;
use App\Models\OcrJob;
use App\Services\AIProcessorService;
use App\Services\FinWaAIService;
use App\Services\OCR\OcrProcessorService;
use App\Services\OCR\ReceiptParserService;
use App\Services\Transaction\TransactionConfirmationService;
use App\Services\Transaction\TransactionExtractorService;
use App\Services\Transaction\TransactionService;
use App\Services\Transaction\BatchTransactionService;
use App\Services\Category\CategoryManagerService;
use App\Services\Category\CategoryMappingService;
use App\Services\Wallet\WalletCommandService;
use App\Services\Budget\BudgetAlertService;
use App\Services\FAQ\FAQService;
use App\Services\MessageReplyService;
use App\Helpers\WhatsAppRegistrationHelper as RegHelper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\Reminder\ReminderCommandService;
use App\Services\Report\ReportCommandService;
use App\Services\Budget\BudgetCommandHandler;
use App\Services\Account\AccountCommandService;
use App\Services\Analysis\AnalysisCommandService;
use App\Services\Query\FinancialQueryHandler;
use App\Services\STT\SttProcessorService;
use App\Services\DebtReceivable\DebtReceivableLedgerService;

class ProcessIncomingMessage implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    /**
     * Jangan pernah mengulang job ini: handler tidak idempoten (mencatat
     * transaksi, mengubah saldo, mengirim balasan). Retry = transaksi dobel.
     * Properti ini mengalahkan flag --tries=3 pada queue:work.
     */
    public $tries = 1;

    /** Batas waktu proses satu pesan (detik). Harus < retry_after queue. */
    public $timeout = 280;

    protected TransactionService $transactionService;
    protected OcrProcessorService $ocrProcessor;
    protected ReceiptParserService $receiptParser; // Added property
    protected MessageReplyService $replyService;
    protected TransactionConfirmationService $confirmationService;
    protected TransactionExtractorService $transactionExtractor;
    protected BatchTransactionService $batchTransaction;
    protected CategoryManagerService $categoryManager;
    protected CategoryMappingService $categoryMapping;
    protected WalletCommandService $walletCommand;
    protected BudgetAlertService $budgetAlert;
    protected FAQService $faqService;
    protected ReminderCommandService $reminderService;
    protected ReportCommandService $reportService;
    protected BudgetCommandHandler $budgetCommand;
    protected AccountCommandService $accountCommandService;
    protected AnalysisCommandService $analysisCommandService;
    protected FinancialQueryHandler $financialQueryHandler;
    protected SttProcessorService $sttProcessor;
    protected \App\Services\WhatsApp\CommandHandlerService $commandHandler;
    protected \App\Services\Savings\SavingsGoalService $savingsGoalService;
    protected \App\Services\WhatsApp\GreetingService $greetingService;
    protected DebtReceivableLedgerService $debtLedger;

    // Hybrid (Hybrid.md Fase 1–2): lazily diinisialisasi oleh hybridAvailable().
    protected ?\App\Services\HybridLLMConfigService $hybridConfig = null;
    protected ?\App\Services\Transaction\HybridTransactionExtractor $hybridExtractor = null;
    protected ?\App\Services\Transaction\ExtractionValidator $hybridValidator = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Message $message
    ) {
        //
    }

    protected function initializeServices(): void
    {
        $this->replyService = new MessageReplyService($this->message);
        $sendReply = fn($msg) => $this->replyService->sendReply($msg);

        $this->transactionExtractor = new TransactionExtractorService();
        $this->categoryMapping = new CategoryMappingService();
        $this->categoryManager = new CategoryManagerService($this->message);
        $this->budgetAlert = new BudgetAlertService($this->message, $sendReply);
        $this->confirmationService = new TransactionConfirmationService($this->message, $sendReply);
        
        $extractLocal = fn($txt) => $this->transactionExtractor->extractTransactionLocally($txt);
        $extractAcc = fn($txt) => $this->transactionExtractor->extractAccountNameFromMessage($txt);
        $mapCat = fn($cat, $inc) => $this->categoryMapping->mapFinwaKategoriToCategoryType($cat, $inc);
        $createCat = fn($tId) => $this->categoryManager->createCategoriesForTenant($tId);
        $checkBudget = fn($tx) => $this->budgetAlert->checkBudgetAlert($tx);
        $sendConfirm = fn($txs, $rev) => $this->confirmationService->sendConfirmation($txs, $rev);
        $parseDate = fn($h) => $this->batchTransaction->parseDateFromHeader($h);
        
        $categoryInference = new \App\Services\Transaction\CategoryInferenceService();

        $this->transactionService = new TransactionService(
            $this->message,
            $categoryInference,
            $this->categoryManager,
            $sendReply,
            $extractLocal,
            $extractAcc,
            $mapCat,
            $createCat,
            $checkBudget,
            $sendConfirm,
            $parseDate
        );
        
        $this->batchTransaction = new BatchTransactionService(
            $this->message, 
            $sendReply,
            fn($txt) => $this->transactionExtractor->extractAmountFromText($txt),
            fn($txt) => $this->transactionExtractor->extractDescriptionFromLine($txt),
            function ($desc, $inc) use ($categoryInference) {
                $inference = $categoryInference->infer($desc, $inc, $this->message->tenant_id);
                $result = $inference['category_type'] ?? ($inc ? 'pendapatan_lainnya' : 'pengeluaran_lainnya');
                Log::info('BatchCategoryInference', [
                    'description' => $desc,
                    'result' => $result,
                    'confidence' => $inference['confidence'] ?? 0,
                    'source' => $inference['source'] ?? 'unknown',
                ]);
                return $result;
            },
            fn($data, $rev) => $this->transactionService->createTransaction($data, $rev)
        );
        
        $this->receiptParser = new ReceiptParserService();
        
        $this->ocrProcessor = new OcrProcessorService(
            $this->message,
            $this->transactionService,
            $this->confirmationService,
            $this->receiptParser,
            $sendReply,
            $mapCat
        );
        
        $this->walletCommand = new WalletCommandService(
            $this->message, 
            $sendReply,
            fn($txt) => $this->transactionExtractor->extractAmountFromText($txt),
            fn($data) => $this->transactionService->createTransaction($data, false),
            null,
            fn() => $this->transactionService->handleViewTransactions()
        );
        $this->faqService = new FAQService($this->message, $sendReply);
        $this->reminderService = new ReminderCommandService($this->message, $sendReply);
        $this->reportService = new ReportCommandService($this->message, $sendReply);
        $this->budgetCommand = new BudgetCommandHandler($this->message, $sendReply);
        $this->accountCommandService = new AccountCommandService($this->message, $sendReply);
        $this->analysisCommandService = new AnalysisCommandService($this->message, $sendReply);
        
        $this->financialQueryHandler = new FinancialQueryHandler(
            $this->message, 
            $sendReply,
            fn($txt = null) => $this->walletCommand->handleCheckBalance($txt)
        );
        
        $this->sttProcessor = new SttProcessorService($this->message, $sendReply);

        $this->commandHandler = new \App\Services\WhatsApp\CommandHandlerService(
            $this->transactionService,
            $this->batchTransaction,
            $this->walletCommand,
            $this->financialQueryHandler,
            new \App\Services\FinancialQueryService,
            new \App\Services\ConversationContextService($this->message->tenant_id, $this->message->sender_id),
            new \App\Services\AIProcessorService,
            new \App\Services\Category\CategoryCorrectionService
        );
        $this->commandHandler->setJob($this);

        $this->savingsGoalService = new \App\Services\Savings\SavingsGoalService($this->message, $sendReply);
        $this->greetingService = new \App\Services\WhatsApp\GreetingService($this->message, $sendReply);
        $this->debtLedger = new DebtReceivableLedgerService();
    }

    // Temporary storage for AI insights
    protected ?array $currentSentiment = null;
    protected ?string $currentSuggestion = null;


    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Semua perubahan transaksi selama memproses pesan ini tercatat "via whatsapp"
        \App\Services\Ledger\AuditContext::run(
            ['via' => 'whatsapp', 'message_id' => $this->message->id],
            fn () => $this->handleMessage()
        );
    }

    protected function handleMessage(): void
    {
        $this->initializeServices();
        $startAt = microtime(true);
        try {
            $this->safeLog('debug', 'ProcessIncomingMessage: start', [
                'message_id' => $this->message->id,
                'tenant_id' => $this->message->tenant_id,
                'channel_id' => $this->message->channel_id,
                'channel_account' => $this->message->channel_account,
                'sender_id' => $this->message->sender_id,
                'type' => $this->message->type,
                'content_len' => is_string($this->message->content) ? strlen($this->message->content) : null,
                'content_preview' => is_string($this->message->content) ? mb_substr($this->message->content, 0, 120) : null,
                'attempts' => $this->attempts(),
                'job_id' => $this->job?->getJobId(),
            ]);

            $regService = new \App\Services\WhatsApp\RegistrationFlowService($this->message);
            $regResult = $regService->resolve();
            if ($regResult['handled'] && !$regResult['shouldContinue']) {
                return;
            }

            // Fase 1 reply-edit (docs/RENCANA-IMPLEMENTASI-REPLY-EDIT.md):
            // tangani reply pesan transaksi & konfirmasi Ya/Batal sebelum flow lama.
            // Ops — tambah callback ekstraksi & pembuatan (create via reply).
            // Ops — callback koreksi kategori: balasan "Ubah kategori ... jadi X"
            // diterapkan ke transaksi yang dibalas, bukan jatuh ke prompt template.
            if ($this->message->type === 'text' && config('finwa_reply_edit.enabled', false)) {
                $replyEdit = new \App\Services\Transaction\ReplyEditService(
                    $this->message,
                    fn($msg) => $this->replyService->sendReply($msg),
                    fn($txt) => $this->transactionExtractor->extractTransactionLocally($txt),
                    fn($data, $rev) => $this->transactionService->createTransaction($data, $rev),
                    fn($catCandidate, $tx) => $this->transactionService->applyCategoryCorrection($tx, $catCandidate)
                );
                if ($replyEdit->handle()) {
                    return;
                }
            }

            switch ($this->message->type) {
                case 'text':
                    $this->processTextMessage();
                    break;
                
                case 'image':
                    $limitService = app(\App\Services\SubscriptionLimitService::class);
                    if (! $limitService->canTenantUseOcr($this->message->tenant_id)) {
                        $this->replyService->sendReply(
                            "⚠️ *Fitur Scan Struk Tidak Tersedia*\n\n".
                            "Maaf, fitur membaca struk belanja otomatis (OCR) hanya tersedia untuk paket Premium (Grow/Pro).\n\n".
                            "🚀 *Upgrade Paket Anda* untuk menikmati kemudahan catat otomatis lewat foto struk!\n".
                            "👉 " . config('app.url') . "/subscriptions"
                        );
                        break;
                    }

                    // Batas scan (per menit / hari / bulan, per user) — dulu tidak pernah
                    // dicek sehingga pemakaian (dan biaya) Gemini tidak terbatas.
                    $scanUserId = $this->resolveSenderUserId();
                    $scanCheck = $limitService->canScanReceipt($this->message->tenant_id, $scanUserId);
                    if (! $scanCheck['can_scan']) {
                        $this->replyService->sendReply(match ($scanCheck['reason']) {
                            'rate_limit' => "⏳ *Terlalu cepat*\n\nTunggu sekitar 1 menit sebelum mengirim foto struk berikutnya ya.",
                            'daily_limit' => "⚠️ *Batas scan harian tercapai*\n\nKamu sudah scan ".($scanCheck['daily'] ?? '').' struk hari ini (maks '.($scanCheck['limit_daily'] ?? '-').").\nCoba lagi besok, atau ketik manual: _belanja 150rb di alfamart_.",
                            default => "⚠️ *Batas scan bulanan tercapai*\n\nKuota scan struk bulan ini sudah habis (maks ".($scanCheck['limit_monthly'] ?? '-').").\nKetik manual dulu ya: _belanja 150rb di alfamart_.\n\n👉 ".config('app.url').'/subscriptions',
                        });
                        break;
                    }
                    $limitService->incrementMinuteScanCount($this->message->tenant_id, $scanUserId);

                    $ocrJob = $this->ocrProcessor->createOcrJob($scanUserId);
                    if ($ocrJob) {
                        $this->ocrProcessor->dispatchToOcrWorker($ocrJob);
                    } else {
                        $this->replyService->sendReply("⚠️ Gagal memproses gambar. Pastikan gambar valid.");
                    }
                    break;
                
                case 'audio':
                    $sttJob = $this->sttProcessor->createSttJob();
                    if ($sttJob) {
                        $this->sttProcessor->processSttJob($sttJob);
                        $sttJob->refresh();
                        if ($sttJob->status === 'completed' && !empty($sttJob->transcribed_text)) {
                            $this->processTextMessage($sttJob->transcribed_text);
                        }
                    } else {
                        $this->replyService->sendReply(
                            "⚠️ *Gagal memproses pesan suara*\n\n" .
                            "Pastikan voice note valid.\n" .
                            "Atau ketik pesan Anda secara manual."
                        );
                    }
                    break;
                
                case 'doc':
                case 'csv':
                    // TODO: Process document

                    break;
            }

        } catch (\Throwable $e) {
            $this->safeLog('error', 'Error processing incoming message', [
                'message_id' => $this->message->id,
                'tenant_id' => $this->message->tenant_id,
                'type' => $this->message->type,
                'attempts' => $this->attempts(),
                'job_id' => $this->job?->getJobId(),
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        } finally {
            $elapsedMs = (int) round((microtime(true) - $startAt) * 1000);
            $this->safeLog('debug', 'ProcessIncomingMessage: finish', [
                'message_id' => $this->message->id,
                'tenant_id' => $this->message->tenant_id,
                'type' => $this->message->type,
                'elapsed_ms' => $elapsedMs,
                'attempts' => $this->attempts(),
                'job_id' => $this->job?->getJobId(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $user = null;
        if ($uid !== null && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid($uid);
            if (is_array($pw)) {
                $user = $pw['name'] ?? null;
            }
        }

        $this->safeLog('error', 'ProcessIncomingMessage: failed', [
            'message_id' => $this->message->id,
            'tenant_id' => $this->message->tenant_id,
            'type' => $this->message->type,
            'attempts' => $this->attempts(),
            'job_id' => $this->job?->getJobId(),
            'worker_uid' => $uid,
            'worker_user' => $user,
            'exception_class' => get_class($e),
            'error' => $e->getMessage(),
        ]);
    }

    protected function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            $allowed = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
            if (!in_array($level, $allowed, true)) {
                $level = 'info';
            }
            Log::{$level}($message, $context);
        } catch (\Throwable $e) {
            $payload = [
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'logger_exception_class' => get_class($e),
                'logger_error' => $e->getMessage(),
            ];
            @error_log('[finwa][safeLog] ' . json_encode($payload));
        }
    }

    /**
     * Ambil ID pengirim sebenarnya (participant jika pesan dari grup).
     * Dipakai agar context/pending_edit ter-scope ke pengirim yang tepat.
     */
    protected function getAttributionSenderId(): string
    {
        $metadata = is_array($this->message->metadata)
            ? $this->message->metadata
            : json_decode($this->message->metadata ?? '{}', true);

        if (($metadata['is_group'] ?? false) && ! empty($metadata['author'])) {
            return $metadata['author'];
        }

        return $this->message->sender_id;
    }

    /**
     * Hybrid (Hybrid.md Fase 2 §7-2d): cek ketersediaan + siapkan extractor
     * & validator. Pre-check murah TANPA network — bila tidak siap, seluruh
     * blok injeksi dilewati dan jalur lama 100% tidak berubah.
     */
    protected function hybridAvailable(): bool
    {
        if ($this->hybridConfig !== null) {
            // Sudah pernah diperiksa & siap pada panggilan pertama.
            return true;
        }

        $config = new \App\Services\HybridLLMConfigService();
        if (! $config->isReady()) {
            return false;
        }

        $this->hybridConfig = $config;
        $this->hybridExtractor = new \App\Services\Transaction\HybridTransactionExtractor(
            new \App\Services\AI\OpenAIChatClient($config),
            $config,
        );
        $this->hybridValidator = new \App\Services\Transaction\ExtractionValidator();

        return true;
    }

    /**
     * Catat panggilan extractor hybrid ke llm_extraction_logs (Hybrid.md Fase 4).
     *
     * Append-only, non-blocking (gagal menulis log TIDAK boleh menggagalkan
     * pemrosesan pesan). Menyimpan pesan mentah, hasil LLM, hasil validator,
     * keputusan akhir, dan alasan override untuk audit + dataset perbaikan.
     */
    protected function logHybridExtraction(
        string $rawMessage,
        \App\Services\Transaction\HybridExtractionResult $result,
        ?array $validated,
        string $decision,
        int $latencyMs,
    ): void {
        try {
            LlmExtractionLog::create([
                'tenant_id' => $this->message->tenant_id,
                'message_id' => $this->message->id,
                'raw_message' => $rawMessage,
                'llm_result' => [
                    'status' => $result->status,
                    'note' => $result->note,
                    'intent' => $result->intent,
                    'type' => $result->type,
                    'amount' => $result->amount,
                    'items' => $result->items,
                    'category' => $result->category,
                    'confidence' => $result->confidence,
                ],
                'validator_result' => $validated,
                'decision' => $decision,
                'override_reasons' => $validated['reasons'] ?? null,
                'model' => null,
                'latency_ms' => $latencyMs,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Hybrid: gagal menulis llm_extraction_logs', [
                'error' => $e->getMessage(),
                'message_id' => $this->message->id,
            ]);
        }
    }

    /**
     * ══ Opsi A (Hybrid.md §6A) — hambatan selektif di FAST PATH 2 ══
     *
     * Dipanggil HANYA saat inferensi lokal jatuh ke *_lainnya (kasus yang tidak
     * dikenal keyword/koreksi). Pesan normal tidak pernah menyentuh LLM →
     * tetap instan. Kasus langka bayar ~10 detik demi kategori yang benar.
     *
     * Intercept hanya jika LLM benar-benar menghasilkan kategori LEBIH BAIK
     * dari *_lainnya; kalau tidak → lepas ke jalur lama (jatuh-when-in-doubt).
     *
     * @return array|null entitas '_source=hybrid' untuk handleTransaction, atau null = jalur lama
     */
    protected function tryHybridForFastPath(string $messageText, string $localCat, bool $hasDebtKeyword): ?array
    {
        // Aliran hutang/piutang sensitif polaritas — serahkan ke detektor khusus.
        if ($hasDebtKeyword || ! $this->hybridAvailable()) {
            return null;
        }

        $meta = is_array($this->message->metadata)
            ? $this->message->metadata
            : json_decode($this->message->metadata ?? '{}', true);
        if ((bool) ($meta['is_group'] ?? false)) {
            return null;
        }

        $start = hrtime(true);
        $result = $this->hybridExtractor->extract($messageText);
        $latency = (int) ((hrtime(true) - $start) / 1_000_000);

        $decision = LlmExtractionLog::DECISION_UNAVAILABLE;
        $validated = null;

        if ($this->hybridValidator->shouldIntercept($result)) {
            $validated = $this->hybridValidator->validate($messageText, $result);

            if ($validated['passed']) {
                $categoryType = (string) $validated['category_type'];
                $kategori = $validated['kategori'];
                $rescuedFrom = null;

                // RESCUE: label LLM di luar whitelist config ("kebutuhan bayi")
                // dibuang validator → *_lainnya. Coba petakan label LLM ke
                // kategori nyata sebelum menyerah — inilah inti manfaat Opsi A.
                if (str_ends_with($categoryType, '_lainnya')) {
                    $llmLabel = is_string($result->category) ? trim($result->category) : '';
                    if ($llmLabel !== '') {
                        $mapped = app(\App\Services\Transaction\CategoryInferenceService::class)
                            ->infer($llmLabel, $validated['is_income'], $this->message->tenant_id)['category_type'] ?? '';

                        if (str_ends_with((string) $mapped, '_lainnya')) {
                            $mapped = (new \App\Services\Category\CategoryMappingService)
                                ->mapFinwaKategoriToCategoryType($llmLabel, $validated['is_income']);
                        }

                        if (! str_ends_with((string) $mapped, '_lainnya')) {
                            $categoryType = (string) $mapped;
                            $kategori = $llmLabel;
                            $rescuedFrom = $llmLabel;
                        }
                    }
                }

                // LLM tak memberi kategori lebih baik → jalur lama 100%
                // (perilaku identik dengan sebelum Opsi A ada).
                if (str_ends_with($categoryType, '_lainnya')) {
                    $decision = LlmExtractionLog::DECISION_NOT_INTERCEPTED;
                    $this->logHybridExtraction($messageText, $result, $validated, $decision, $latency);

                    return null;
                }

                Log::info('Hybrid (Opsi A FastPath): lolos validator → intercept', [
                    'message_id' => $this->message->id,
                    'local_category' => $localCat,
                    'rescued_from' => $rescuedFrom,
                    'final_category' => $categoryType,
                    'amount' => $validated['amount'],
                    'confidence' => $result->confidence,
                    'latency_ms' => $latency,
                ]);

                $this->logHybridExtraction($messageText, $result, $validated,
                    LlmExtractionLog::DECISION_INTERCEPTED, $latency);

                return [
                    '_source' => 'hybrid',
                    'nominal' => $validated['amount'],
                    'kategori' => $kategori,
                    'category_type' => $categoryType,
                    'is_income' => $validated['is_income'],
                    'catatan' => $messageText,
                    'merchant' => null,
                ];
            }

            $decision = LlmExtractionLog::DECISION_REJECTED;
        } elseif (! $result->isUsable()) {
            $decision = LlmExtractionLog::DECISION_UNAVAILABLE;
        } else {
            $decision = LlmExtractionLog::DECISION_NOT_INTERCEPTED;
        }

        $this->logHybridExtraction($messageText, $result, $validated, $decision, $latency);

        return null;
    }

    /**
     * Process text message - classify intent and handle accordingly
     * 
     * @param string|null $overrideText Optional text to process (used for STT transcribed text)
     */
    protected function processTextMessage(?string $overrideText = null): void
    {
        // Use override text if provided (from STT), otherwise use message content
        $messageText = $overrideText ?? $this->message->content ?? '';

        // Handle LINK commands (token-based or phone-based) first
        $trimmedText = trim($messageText);

        // Aturan khusus pesan GRUP (blokir perintah berbahaya, info sensitif → chat pribadi)
        if ($this->applyGroupPolicy($trimmedText)) {
            return;
        }

        // 1. Token-based linking (LINK ABC-123)
        if (preg_match('/^link\s+([A-Z0-9]{3}-[A-Z0-9]{3})$/i', $trimmedText, $tokenMatches)) {
            $tokenValue = strtoupper($tokenMatches[1]);
            $linkToken = \App\Models\DeviceLinkToken::where('token', $tokenValue)
                ->where('expires_at', '>', now())
                ->first();

            if ($linkToken) {
                $existingUser = $linkToken->user;
                $cleanPhone = $existingUser->whatsapp_number;

                // Link LID
                $metadata = is_array($this->message->metadata) ? $this->message->metadata : json_decode($this->message->metadata ?? '{}', true);
                $originalFrom = $metadata['original_sender_id'] ?? $this->message->sender_id;
                $lidToSave = preg_replace('/[^0-9]/', '', $originalFrom);
                \App\Models\UserLidMapping::linkLidToUser($lidToSave, $existingUser->id, $existingUser->tenant_id, $cleanPhone);

                // Also create UserWhatsAppNumber entry for consistency
                \App\Models\UserWhatsAppNumber::updateOrCreate(
                    ['whatsapp_number' => $this->message->sender_id, 'tenant_id' => $existingUser->tenant_id],
                    [
                        'user_id' => $existingUser->id,
                        'name' => 'LID - Device Link',
                        'is_primary' => false,
                        'is_active' => true,
                        'is_lid' => true
                    ]
                );

                // Cleanup token
                $linkToken->delete();

                $successMessage = "✅ *Perangkat Terhubung!*\n\n".
                    "Halo {$existingUser->name},\n".
                    "Perangkat ini berhasil dihubungkan via kode verifikasi.\n\n".
                    "Silakan kirim ulang transaksi Anda.";

                $this->replyService->sendReply($successMessage);

                return;
            } else {
                $this->replyService->sendReply("❌ *Kode Tidak Valid*\n\nKode verifikasi salah atau sudah kedaluwarsa. Silakan ambil kode baru di Dashboard Web.");
                return;
            }
        }

        // 2. Phone-based linking (LINK 08123456789)
        if (preg_match('/^link\s+(\d{10,15})$/i', $trimmedText, $linkMatches)) {
            $phoneToLink = $linkMatches[1];
            $mappingService = new \App\Services\WhatsAppUserMappingService();
            $cleanPhone = $mappingService->cleanPhoneNumber($phoneToLink);

            // Use helper to find user
            $existingUser = \App\Models\User::where('whatsapp_number', $cleanPhone)->first();
            if (!$existingUser) {
                $waNum = \App\Models\UserWhatsAppNumber::where('whatsapp_number', $cleanPhone)->where('is_active', true)->first();
                if ($waNum) {
                    $existingUser = $waNum->user;
                }
            }

            if ($existingUser) {
                // CHECK SUBSCRIPTION BEFORE LINKING (replicate checkSubscriptionStatus logic)
                $tenant = \App\Models\Tenant::find($existingUser->tenant_id);
                $subscriptionValid = false;
                if ($tenant) {
                    // Check for active subscription
                    $hasActiveSubscription = \App\Models\Subscription::where('tenant_id', $existingUser->tenant_id)
                        ->where('status', 'active')
                        ->where(function ($query) {
                            $query->whereNull('ends_at')
                                ->orWhere('ends_at', '>', now());
                        })
                        ->exists();

                    // Check if in trial period
                    $isInTrial = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture();
                    $subscriptionValid = $hasActiveSubscription || $isInTrial;
                }

                if (!$subscriptionValid) {
                    $expiredMessage = "⚠️ *Langganan Tidak Aktif*\n\n".
                        "Akun untuk nomor *$cleanPhone* memiliki langganan yang sudah tidak aktif.\n\n".
                        "Anda tidak dapat menghubungkan perangkat baru hingga langganan diperpanjang.\n\n".
                        "🔒 *Untuk mengaktifkan kembali:*\n".
                        "1️⃣ Perpanjang di: ".config('app.url')."/subscriptions\n".
                        "2️⃣ Hubungi Admin: ".config('services.finwa.super_admin_number')."\n\n".
                        "_Terima kasih telah menggunakan FinWa!_ 💙";

                    $this->replyService->sendReply($expiredMessage);
                    return;
                }

                // Link LID
                $metadata = is_array($this->message->metadata) ? $this->message->metadata : json_decode($this->message->metadata ?? '{}', true);
                $originalFrom = $metadata['original_sender_id'] ?? $this->message->sender_id;
                $lidToSave = preg_replace('/[^0-9]/', '', $originalFrom);

                \App\Models\UserLidMapping::linkLidToUser(
                    $lidToSave,
                    $existingUser->id,
                    $existingUser->tenant_id,
                    $cleanPhone
                );

                // Also create UserWhatsAppNumber entry
                \App\Models\UserWhatsAppNumber::updateOrCreate(
                    ['whatsapp_number' => $this->message->sender_id, 'tenant_id' => $existingUser->tenant_id],
                    [
                        'user_id' => $existingUser->id,
                        'name' => 'LID - Device Link',
                        'is_primary' => false,
                        'is_active' => true,
                        'is_lid' => true
                    ]
                );

                $successMessage = "✅ *Perangkat Terhubung!*\n\n".
                    "Halo {$existingUser->name},\n".
                    "Perangkat ini berhasil dihubungkan ke akun Anda ({$cleanPhone}).\n\n".
                    "Silakan kirim ulang transaksi Anda.";

                $this->replyService->sendReply($successMessage);
                return;
            } else {
                $errorMessage = "❌ *Nomor Tidak Ditemukan*\n\n".
                    "Kami mencari nomor: *$cleanPhone* (dan variasinya)\n".
                    "namun tidak menemukan data yang cocok.\n\n".
                    "Ketik *DAFTAR* untuk buat akun baru.";

                $this->replyService->sendReply($errorMessage);
                return;
            }
        }
        
        if (empty($messageText)) {

            return;
        }
        
        // Normalize keywords (map variations to standard commands)
        $originalText = $messageText;
        $messageText = \App\Helpers\KeywordNormalizer::normalize($messageText);
        
        
        // CONTEXT MEMORY: Check for follow-up questions and enrich with context
        // IMPORTANT: Check BEFORE saving new context, so getLastContext returns previous message
        // Dibatasi per PENGIRIM (bukan per tenant): di grup, "ya"/"15rb" dari
        // anggota A tidak boleh menyelesaikan transaksi tertunda milik anggota B.
        // Harus sama dengan TransactionService::storePendingTransaction (sender-scoped).
        $contextService = new \App\Services\ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId());
        
        // PRIORITY CHECK: Pending transaction follow-up (user replies with just amount)
        // If user previously sent "naik ojek" and now sends "15rb", combine them
        $msgTrimmed = trim($messageText);

        // PRIORITY CHECK (PALING AWAL): jawaban pertanyaan bernomor ("1", "2", "masuk", "ya").
        // Pertanyaan bernomor selalu yang TERBARU — pesan lain apa pun membatalkannya —
        // jadi wajib dicek sebelum follow-up nominal: dulu "2" bisa dibaca sebagai Rp 2
        // untuk transaksi tertunda lama ("beli bensin" → "Berapa biayanya?").
        if ($this->handlePendingClarification($msgTrimmed)) {
            return;
        }

        $isOnlyAmount = preg_match('/^(Rp\s?)?(\d{1,3}([.,]\d{3})*|\d+)([.,]\d+)?\s*(rb|ribu|k|jt|juta|m)?$/i', $msgTrimmed);
        
        if ($isOnlyAmount) {
            $pending = $contextService->getPendingTransaction();
            
            if ($pending) {
                Log::info('Processing pending transaction follow-up in processTextMessage', [
                    'message_id' => $this->message->id,
                    'pending' => $pending,
                    'amount_text' => $msgTrimmed
                ]);
                
                // Combine description with amount and process as normal transaction
                $combinedMessage = $pending['description'] . ' ' . $msgTrimmed;
                
                // Clear pending transaction
                $contextService->clearPendingTransaction();
                
                // Process as transaction
                $this->transactionService->handleTransaction($combinedMessage, null);
                return;
            }
        }
        
        // PRIORITY CHECK: Pending konfirmasi saldo dompet ("Ya" / "Tambah" / "Batal")
        // Harus SEBELUM cek konfirmasi transaksi agar jawaban "Ya" tidak salah sasaran.
        if ($this->walletCommand->handlePendingWalletBalanceConfirmation($msgTrimmed)) {
            return;
        }

        // PRIORITY CHECK: jawaban konfirmasi hapus transaksi ("Ya" / "Tidak")
        if ($this->transactionService->handlePendingDeleteReply($msgTrimmed)) {
            return;
        }

        // "kembalikan" / "batal hapus" — pulihkan transaksi terakhir yang dihapus (soft delete)
        // Sebelum rute "batal <kata>" agar "batal hapus" tidak dibaca sebagai hapus keyword "hapus".
        if (TransactionService::isRestoreCommand($messageText)) {
            $this->transactionService->handleRestoreTransaction();
            return;
        }

        // "1 hapus" / "hapus nomor 1" setelah "lihat transaksi" → konfirmasi hapus nomor itu.
        // Dulu tidak dikenali (pola nomor hanya untuk "N ubah jadi X").
        if (($listNo = TransactionService::parseListDeleteIndex($messageText)) !== null) {
            $this->transactionService->handleListIndexDelete($listNo);
            return;
        }

        // PRIORITY CHECK: Pending ambiguous confirmation ("1", "2", "pemasukan", "pengeluaran")
        $pendingAmbiguous = $contextService->getPendingConfirmation();
        if ($pendingAmbiguous && ($pendingAmbiguous['type'] ?? null) === 'ambiguous') {
            $this->handleAmbiguousReply($msgTrimmed, $pendingAmbiguous, $contextService);
            return;
        }

        // PRIORITY CHECK: Pending confirmation (user replies "ya", "iya", "ok", "y", "yes")
        $confirmPatterns = '/^(ya|iya|yaa|iyaa|ok|oke|y|yes|iy|yup|betul|benar|proceed|lanjut|catat|ya dong|iya dong|confirmed)$/i';
        if (preg_match($confirmPatterns, $msgTrimmed)) {
            $pendingConfirmation = $contextService->getPendingConfirmation();

            if ($pendingConfirmation) {
                Log::info('Processing pending confirmation', [
                    'message_id' => $this->message->id,
                    'pending' => $pendingConfirmation,
                ]);

                $contextService->clearPendingConfirmation();

                $this->transactionService->handleTransaction(
                    $pendingConfirmation['original_message'],
                    null
                );
                return;
            }
        }

        $enrichedMessage = $messageText;
        $contextUsed = false;
        
        if ($contextService->isFollowUpQuestion($messageText)) {
            $lastContext = $contextService->getLastContext();
            
            if ($lastContext) {
                $entities = $lastContext['entities'] ?? [];
                $lastCategory = $entities['category'] ?? null;
                $lastIntent = $lastContext['intent'] ?? null;
                

                
                // Enrich short follow-up questions with context
                $textLower = strtolower($messageText);
                
                // Time-based follow-ups: "minggu lalu?", "kemarin berapa?"
                // Hanya pertanyaan pendek TANPA nominal & tanpa kata kerja transaksi;
                // "beli bensin kemarin 50rb" adalah transaksi baru, bukan follow-up query.
                $isShortFollowUp = mb_strlen(trim($textLower)) <= 40
                    && ! preg_match('/\d+\s*(rb|ribu|k|jt|juta)\b|\d{3,}/iu', $textLower)
                    && ! preg_match('/\b(beli|bayar|catat|dapat|dapet|terima|transfer|kirim|isi|top\s*up)\b/iu', $textLower);
                if ($isShortFollowUp && preg_match('/(minggu lalu|bulan lalu|kemarin|tadi|yang lalu)/i', $textLower)) {
                    if ($lastCategory && $lastIntent === 'cek_pengeluaran') {
                        $enrichedMessage = "pengeluaran {$lastCategory} " . $messageText;
                        $contextUsed = true;
                    } elseif ($lastIntent === 'cek_pemasukan') {
                        $enrichedMessage = "pemasukan " . $messageText;
                        $contextUsed = true;
                    }
                }
                
                // "berapa?" follow-up
                if (preg_match('/^berapa\??$/i', trim($textLower))) {
                    if ($lastCategory) {
                        $enrichedMessage = "berapa pengeluaran {$lastCategory} bulan ini";
                        $contextUsed = true;
                    }
                }
                
                if ($contextUsed) {

                    $messageText = $enrichedMessage;
                }
            }
        }
        
        // TYPO CORRECTION: Fix common typos in command words
        $typoCorrections = [
            // Command typos
            'hapud' => 'hapus', 'hapua' => 'hapus', 'hpus' => 'hapus', 'apus' => 'hapus',
            'edif' => 'edit', 'efit' => 'edit', 'ediit' => 'edit',
            'ubsh' => 'ubah', 'ubha' => 'ubah', 'uabh' => 'ubah',
            'lihaf' => 'lihat', 'lihar' => 'lihat', 'liat' => 'lihat',
            'dompwt' => 'dompet', 'domprt' => 'dompet', 'donpet' => 'dompet',
            'saldp' => 'saldo', 'salfo' => 'saldo', 'salso' => 'saldo',
            // Transaction typos
            'bensib' => 'bensin', 'bnesin' => 'bensin', 'bebsin' => 'bensin',
            'maakn' => 'makan', 'makab' => 'makan', 'makn' => 'makan',
            'transper' => 'transfer', 'tranfer' => 'transfer', 'trnasfer' => 'transfer',
            'belanaj' => 'belanja', 'blanja' => 'belanja', 'belajna' => 'belanja',
            'bayae' => 'bayar', 'bauar' => 'bayar', 'bayer' => 'bayar',
        ];
        
        $correctedText = $messageText;
        foreach ($typoCorrections as $typo => $correct) {
            $correctedText = preg_replace('/\b' . preg_quote($typo, '/') . '\b/i', $correct, $correctedText);
        }
        
        if ($correctedText !== $messageText) {
            Log::info('Typo corrected', [
                'original' => $messageText,
                'corrected' => $correctedText
            ]);
            $messageText = $correctedText;
            $textLower = strtolower($messageText);
        }
        
        // NOW save conversation context for future follow-up questions
        try {
            $contextService->addContext($originalText);
        } catch (\Exception $e) {
            Log::warning('Failed to save conversation context', [
                'error' => $e->getMessage()
            ]);
        }

        // COMMAND PREFIX NORMALIZATION
        // "Revisi: hapus X" / "Koreksi: ganti jadi Y" → strip label agar regex ber-anchor tetap match
        $prefixLabels = config('finwa_category_rules.command_prefix_labels', []);
        foreach ($prefixLabels as $label) {
            $stripped = preg_replace(
                '/^\s*'.preg_quote($label, '/').'\s*[:\-]\s*/iu',
                '',
                $messageText
            );
            if ($stripped !== null && $stripped !== '' && $stripped !== $messageText) {
                Log::info('Command prefix normalized', [
                    'original'   => $messageText,
                    'normalized' => $stripped,
                    'label'      => $label,
                ]);
                $messageText = $stripped;
                $textLower = strtolower($messageText);
                break;
            }
        }


        // BATCH TRANSACTION: Check for multiple transactions in list format
        // Handles formats like:
        // "Biaya tanggal 10 Desember 2025
        // 1. Makan malam 17.000
        // 2. Grab pulang 13.500"
        // Lewati kalau ini perintah saldo awal ("Saldo awal saya 3jt 400 rb")
        // agar tidak salah dipecah jadi transaksi biasa.
        // Lewati juga kalau ini perintah edit eksplisit dengan dua nominal
        // ("ganti makan siang 30rb jadi 50rb") — 2 angka terdeteksi sebagai
        // batch, padahal maksud user mengedit, bukan mencatat 2 transaksi.
        $isEditCommandWithTwoAmounts = (bool) preg_match(
            '/^(?:ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?[a-zA-Z].*\b(?:jadi|ke|menjadi)\s+\d+/iu',
            $messageText
        );
        if (! $isEditCommandWithTwoAmounts
            && ! preg_match('/saldo\s+awal/iu', $messageText)
            && $this->batchTransaction->isBatchTransactionFormat($messageText)) {
            $this->commandHandler->handleBatchTransactions($messageText);
            return;
        }
        
        $textLower = strtolower($messageText);

        // FAST PATH 0: Help & Greeting (Priority 1)
        if (trim($textLower) === 'help' || trim($textLower) === 'panduan' || trim($textLower) === 'menu') {
            $this->greetingService->handleSpecialIntent('help');
            return;
        }
        if (trim($textLower) === 'halo' || trim($textLower) === 'hai' || trim($textLower) === 'p') {
            $this->greetingService->handleSpecialIntent('sapa');
            return;
        }

        // Sinyal deterministik pesan (kata query/periode/transaksi, nominal, hutang,
        // statistik). Aturan & daftar kata: App\Services\WhatsApp\MessageSignals
        // + config/finwa_query.php — satu sumber, bisa diuji langsung.
        $signals = \App\Services\WhatsApp\MessageSignals::analyze($textLower);
        $hasQueryKeyword = $signals->hasQueryKeyword;
        $hasPeriodKeyword = $signals->hasPeriodKeyword;
        $hasTransactionKeyword = $signals->hasTransactionKeyword;
        $hasAmount = $signals->hasAmount;
        $hasMoneyAmount = $signals->hasMoneyAmount;
        $looksLikeQuery = $signals->looksLikeQuery;
        $hasDebtKeyword = $signals->hasDebtKeyword;
        $hasStatisticsKeyword = $signals->hasStatisticsKeyword;

        // FAST PATH 1.19: "hanya dicatat" / "hutang lama" → hutang/piutang yang baru saja
        // dicatat (dan terlanjur mengubah saldo) dijadikan hanya dicatat; saldo dikembalikan.
        if (preg_match('/^(?:(?:itu|tadi|yang\s+tadi|barusan)\s+)?(?:(?:hanya|cuma|cuman)\s+(?:di\s?)?catat(?:\s+(?:saja|aja))?|(?:di\s?)?catat\s+(?:saja|aja)|(?:itu\s+)?(?:hutang|utang|piutang)\s+lama|(?:jangan|tidak|ga|gak|nggak)\s+(?:usah\s+)?(?:masuk|tambah|nambah|potong|kurangi)\w*\s+(?:ke\s+|dari\s+)?(?:saldo|dompet))[\s.!]*$/u', trim($textLower))) {
            $this->transactionService->handleDebtRecordOnlyCommand();

            return;
        }

        // FAST PATH 1.2: Query hutang/piutang ("cek hutang", "piutang noki", "hutang saya berapa").
        // Tanpa nominal → bukan pencatatan, melainkan ringkasan outstanding.
        if ($hasDebtKeyword && !$hasAmount) {
            $isDebtQuery = $hasQueryKeyword
                || preg_match('/\b(saya|aku|ku|gue|gw|gua|siapa|masih)\b/u', $textLower) === 1
                || preg_match('/\b(hutang|utang|piutang)\s+\S+/u', $textLower) === 1;

            if ($isDebtQuery) {
                $this->handleDebtQuery($messageText);
                return;
            }
        }

        // FAST PATH 1.25: kalimat yang ARAH-nya tidak bisa ditebak dengan aman → tanya bernomor
        //   "pinjam budi 100rb"  → saya pinjam dari Budi, atau Budi pinjam dari saya?
        //   "tarik tunai 500rb"  → pindah antar dompet sendiri (bukan pemasukan)
        //   "bensin 50"          → maksudnya Rp 50.000?
        if (! $this->isGroupMessage() && $this->askIfAmbiguous($messageText, $textLower, $hasTransactionKeyword || $hasDebtKeyword)) {
            return;
        }

        // FAST PATH 1: Query (query keyword + period keyword + NO amount + NO statistics keyword)
        // e.g., "pengeluaran bulan ini", "pemasukan hari ini"
        // But NOT "pengeluaran terbesar bulan ini" - that needs cek_statistik
        // Periode sudah mencakup nama bulan & "2 bulan lalu" ($hasPeriodKeyword).
        // Pakai $hasMoneyAmount (bukan angka apa saja): "2 bulan lalu",
        // "oktober 2025", "tanggal 5" bukan nominal.

        // EXCLUDE: daftar transaksi HARI INI tetap ke handleViewTransactions
        // (punya fitur "balas nomor" untuk edit). Daftar transaksi periode lain
        // ("lihat transaksi september", "transaksi kemarin") → FinancialQuery mode daftar,
        // karena handleViewTransactions hanya menampilkan hari ini / 7 hari terakhir.
        $isTodayTransactionList = str_contains($textLower, 'transaksi')
            && ! preg_match('/\b(berapa|total|ringkasan|rekap|jumlah)\b/u', $textLower)
            && (str_contains($textLower, 'hari ini') || str_contains($textLower, 'hr ini') || str_contains($textLower, 'hari ni'));

        if ($hasQueryKeyword && $hasPeriodKeyword && ! $hasMoneyAmount && !$hasStatisticsKeyword && !$isTodayTransactionList) {

            $this->financialQueryHandler->handleQuery($messageText);
            return;
        }
        
        // FAST PATH 1.1: Simple informal questions (default to today)
        // e.g., "habis berapa?", "masuk berapa?", "udah habis berapa?"
        $simpleQueryPatterns = [
            '/^habis\s*berapa\??$/i',              // "habis berapa?"
            '/^udah\s*habis\s*berapa\??$/i',       // "udah habis berapa?"
            '/^sudah\s*habis\s*berapa\??$/i',      // "sudah habis berapa?"
            '/^keluar\s*berapa\??$/i',             // "keluar berapa?"
            '/^masuk\s*berapa\??$/i',              // "masuk berapa?"
            '/^udah\s*masuk\s*berapa\??$/i',       // "udah masuk berapa?"
            '/^pengeluaran\s*berapa\??$/i',        // "pengeluaran berapa?"
            '/^pemasukan\s*berapa\??$/i',          // "pemasukan berapa?"
            '/^total\s*hari\s*ini\??$/i',          // "total hari ini?"
            '/^ringkasan\??$/i',                    // "ringkasan?"
        ];
        
        foreach ($simpleQueryPatterns as $pattern) {
            if (preg_match($pattern, trim($messageText))) {
                // Default to today's query
                $this->financialQueryHandler->handleQuery($messageText . ' hari ini');
                return;
            }
        }
        

        // FAST PATH 1.5: Reminder Management (MUST BE BEFORE transaction detection!)
        // Handles: create, delete, and list reminders
        // PRIORITY: Reminder keywords take precedence over transaction keywords like "pdam"
        
        // 1.5a: Delete reminder - "hapus pengingat", "hapus reminder", "delete reminder"
        $deleteReminderKeywords = [
            'hapus pengingat', 'hapus reminder', 'delete reminder', 
            'batalkan pengingat', 'batalkan reminder', 'cancel reminder'
        ];
        foreach ($deleteReminderKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->reminderService->handleDeleteReminder($messageText);
                return;
            }
        }
        
        // 1.5b: List reminders - "lihat pengingat", "daftar pengingat", "list reminder"
        $listReminderKeywords = [
            'lihat pengingat', 'lihat reminder', 'daftar pengingat', 'daftar reminder',
            'list reminder', 'cek pengingat', 'cek reminder', 'reminder saya'
        ];
        foreach ($listReminderKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->reminderService->handleListReminders();
                return;
            }
        }
        
        // 1.5c-pre: Daily Reminder toggle - must come BEFORE bill reminder handler!
        // "aktifkan reminder", "matikan reminder" (not bill reminder like "reminder bayar listrik")
        $enableDailyReminderKeywords = [
            'aktifkan reminder', 'nyalakan reminder', 'hidupkan reminder',
            'enable reminder', 'on reminder', 'reminder on'
        ];
        foreach ($enableDailyReminderKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->reminderService->handleEnableDailyReminder();
                return;
            }
        }
        
        $disableDailyReminderKeywords = [
            'matikan reminder', 'nonaktifkan reminder', 'disable reminder',
            'off reminder', 'reminder off', 'stop reminder'
        ];
        foreach ($disableDailyReminderKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->reminderService->handleDisableDailyReminder();
                return;
            }
        }
        
        // 1.5c: Create reminder - "ingatkan bayar pdam tgl 15", "reminder bayar listrik"
        $createReminderKeywords = [
            'ingatkan', 'reminder', 'pengingat', 'ingetin', 
            'jangan lupa', 'remind', 'set reminder', 'buat pengingat', 'buat reminder'
        ];
        foreach ($createReminderKeywords as $reminderKeyword) {
            if (str_starts_with($textLower, $reminderKeyword) || str_contains($textLower, ' ' . $reminderKeyword)) {

                $this->reminderService->handleSetReminder($messageText, null);
                return;
            }
        }
        
        // FAST PATH 1.9: Transfer/Top Up to specific wallet
        // e.g., "dapet tf 25rb ke BCA", "terima transfer 100rb ke gopay", "tambah saldo dana 50rb"
        $transferToWalletPatterns = [
            '/(?:dapet|dapat|terima|masuk)\s+(?:tf|transfer|kiriman)\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+(?:ke|di)\s+(.+)/i',
            '/(?:tf|transfer)\s+masuk\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+(?:ke|di)\s+(.+)/i',
            '/(?:terima|dapat)\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+(?:ke|di)\s+(.+)/i',
            // Pattern: masuk uang ke bank jago 3.430.000
            '/masuk\s+uang\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // Pattern: Tambah saldo dana 5jt (Top Up)
            '/(?:isi|tambah|top\s*up)\s+saldo\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // Pattern: Tambah uang ke Jago Hadi 600rb
            '/(?:isi|tambah|top\s*up)\s+uang\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // Pattern: Tambah uang 600rb ke Jago Hadi
            '/(?:isi|tambah|top\s*up)\s+uang\s+([\d\.,]+(?:\s*(?:rb|ribu|rebu|k|jt|juta)\b)?)\s+(?!(?:rb|ribu|rebu|k|jt|juta)\b)(?:ke|di)\s+(.+)/i',
            // Pattern: tambah uang masuk dari bos 700.000
            '/(?:isi|tambah|top\s*up)\s+uang\s+masuk\s+(?:dari\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // Pattern: tambah transfer masuk 1.400.000 (tidak ada nama dompet, tapi harus ditangkap sebagai pemasukan / transfer masuk)
            // Namun karena method ini ditujukan untuk "To Wallet", kita tangkap jika ada pattern mirip
            '/(?:isi|tambah|top\s*up)\s+transfer\s+masuk\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i'
        ];
        
        foreach ($transferToWalletPatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                // "terima 2jt di kantor" / "tambah uang masuk dari bos" — "kantor"/"bos" bukan dompet
                // → catat sebagai transaksi biasa, jangan buat dompet baru.
                if (! $this->walletCandidateIsWallet($matches, $messageText)) {
                    continue;
                }
                $this->walletCommand->handleTransferToWallet($messageText);
                return;
            }
        }

        // FAST PATH 1.91: Setor / tambah TABUNGAN ke dompet — saldo masuk, BUKAN pengeluaran.
        // "Tambahan tabungan brimo 1 juta", "isi tabungan BCA 500rb", "nabung di jago 200rb".
        // Dulu tercatat sebagai Pengeluaran Lainnya & memotong Dompet Utama.
        $savingsWallet = \App\Services\Wallet\WalletCommandService::matchSavingsDeposit($messageText);
        if ($savingsWallet !== null) {
            $this->walletCommand->handleTransferToWallet($messageText);
            return;
        }

        // FAST PATH 1.92: Transfer between wallets (internal transfer)
        // e.g., "transfer saldo Jago Hadi ke BRI 300rb", "transfer ke Jago 200rb dari BCA Hadi"
        // NOTE: "kirim uang ke [orang] [nominal]" should be expense, NOT transfer.
        // Only treat as transfer when "dari" is present or "uang" is consumed as optional keyword.
        $transferBetweenWalletPatterns = [
            // "transfer 100rb dari BCA ke Mandiri"
            '/(?:trans[pf]er|tf|trf|pindah(?:kan)?)\s+(?:dana|saldo|uang\s+)?[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+(?:dari\s+)?[a-zA-Z0-9\s]+?\s+ke\s+[a-zA-Z0-9\s]+/i',
            // "kirim 100rb dari BCA ke Mandiri" (kirim requires "dari")
            '/kirim\s+(?:dana|saldo|uang\s+)?[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+dari\s+[a-zA-Z0-9\s]+?\s+ke\s+[a-zA-Z0-9\s]+/i',
            // "transfer dari BCA ke Mandiri 100rb"
            '/(?:trans[pf]er|tf|trf|pindah(?:kan)?)\s+(?:dana|saldo|uang\s+)?(?:dari\s+)?[a-zA-Z0-9\s]+?\s+ke\s+[a-zA-Z0-9\s]+?\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?/i',
            // "kirim dari BCA ke Mandiri 100rb" (kirim requires "dari")
            '/kirim\s+(?:dana|saldo|uang\s+)?dari\s+[a-zA-Z0-9\s]+?\s+ke\s+[a-zA-Z0-9\s]+?\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?/i',
            // "transfer ke Jago 200rb dari BCA Hadi"
            '/(?:trans[pf]er|tf|trf|pindah(?:kan)?|kirim)\s+(?:dana|saldo|uang\s+)?ke\s+[a-zA-Z0-9\s]+?\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+dari\s+[a-zA-Z0-9\s]+/i',
            // "transfer ke Jago dari BCA Hadi 200rb"
            '/(?:trans[pf]er|tf|trf|pindah(?:kan)?|kirim)\s+(?:dana|saldo|uang\s+)?ke\s+[a-zA-Z0-9\s]+?\s+dari\s+[a-zA-Z0-9\s]+?\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?/i',
        ];

        foreach ($transferBetweenWalletPatterns as $pattern) {
            if (preg_match($pattern, $messageText)) {
                $this->walletCommand->handleTransferBetweenWallets();
                return;
            }
        }
        
        // FAST PATH 1.9b: Catch INCOMPLETE "tambah saldo" commands (missing amount)
        // e.g., "tambah saldo BCA" without nominal - give helpful error message
        if (preg_match('/^(?:isi|tambah|top\s*up)\s+saldo\s+([a-zA-Z0-9\s]+)$/i', trim($messageText), $incompleteMatch)) {
            $walletName = trim($incompleteMatch[1]);
            $this->replyService->sendReply(
                "⚠️ *Nominal tidak terdeteksi*\n\n" .
                "Untuk menambah saldo ke *{$walletName}*, sertakan nominal:\n\n" .
                "Contoh:\n" .
                "• _tambah saldo {$walletName} 100rb_\n" .
                "• _isi saldo {$walletName} 1jt_\n" .
                "• _top up {$walletName} 500.000_"
            );
            return;
        }

        if (preg_match('/^(?:isi|tambah|top\s*up)\s+uang\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+)$/i', trim($messageText), $incompleteMatchUang)) {
            $walletName = trim($incompleteMatchUang[1]);
            $this->replyService->sendReply(
                "⚠️ *Nominal tidak terdeteksi*\n\n" .
                "Untuk menambah uang ke *{$walletName}*, sertakan nominal:\n\n" .
                "Contoh:\n" .
                "• _tambah uang ke {$walletName} 100rb_\n" .
                "• _isi uang {$walletName} 1jt_\n" .
                "• _top up uang {$walletName} 500.000_"
            );
            return;
        }
        
        // FAST PATH 1.95: Expense from specific wallet
        // e.g., "Pengeluaran dompet kas kepri 98k beli mata kunci sok 1 set"
        //       "keluar dari dompet BCA 50rb beli kopi"
        //       "bayar dari gopay 25rb grab"
        //       "dompet dana 100rb belanja"
        $expenseFromWalletPatterns = [
            // Pattern 1: "Pengeluaran dompet [nama] [nominal] [deskripsi]"
            '/(?:pengeluaran|keluar(?:an)?)\s+(?:dari\s+)?dompet\s+([a-zA-Z0-9\s]+?)\s+([\d\.,]+(?:\s*(?:rb|ribu|rebu|k|jt|juta)\b)?)\s+(?!(?:rb|ribu|rebu|k|jt|juta)\b)(.+)/i',
            // Pattern 2: "keluar dari dompet [nama] [nominal] [deskripsi]"
            '/keluar\s+dari\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+(?:\s*(?:rb|ribu|rebu|k|jt|juta)\b)?)\s+(?!(?:rb|ribu|rebu|k|jt|juta)\b)(.+)/i',
            // Pattern 3: "bayar dari [nama] [nominal] [deskripsi]"
            '/bayar\s+dari\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+(?:\s*(?:rb|ribu|rebu|k|jt|juta)\b)?)\s+(?!(?:rb|ribu|rebu|k|jt|juta)\b)(.+)/i',
            // Pattern 4: "dompet [nama] [nominal] [deskripsi]"
            '/^dompet\s+([a-zA-Z0-9\s]+?)\s+([\d\.,]+(?:\s*(?:rb|ribu|rebu|k|jt|juta)\b)?)\s+(?!(?:rb|ribu|rebu|k|jt|juta)\b)(.+)/i',
            // Pattern 5: "dari [nama] [nominal] untuk [deskripsi]"
            '/dari\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+(?:\s*(?:rb|ribu|rebu|k|jt|juta)\b)?)\s+(?!(?:rb|ribu|rebu|k|jt|juta)\b)(?:untuk|buat|beli)\s+(.+)/i',
            // Pattern 6: "Pengeluaran dompet [nama] . harga [nominal] . Keterangan [deskripsi]"
            '/(?:pengeluaran|keluar(?:an)?)\s+(?:dari\s+)?dompet\s+([a-zA-Z0-9\s]+?)\s*\.\s*(?:harga|nominal)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s*\.\s*(?:keterangan|ket|desc)?\s*(.+)/i',
            // Pattern 7: "Pengeluaran dompet [nama] . [nominal] . [deskripsi]" (without keywords)
            '/(?:pengeluaran|keluar(?:an)?)\s+(?:dari\s+)?dompet\s+([a-zA-Z0-9\s]+?)\s*\.\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s*\.\s*(.+)/i',
        ];
        
        foreach ($expenseFromWalletPatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                // "dapat uang dari ibu 500rb buat jajan" — "ibu" bukan dompet
                if (! $this->walletCandidateIsWallet($matches, $messageText)) {
                    continue;
                }

                $this->walletCommand->handleExpenseFromWallet($messageText);
                return;
            }
        }

        // FAST PATH 1.96: "dompet utama 100 rb" — nama dompet + nominal TANPA keterangan.
        // Maksudnya ambigu (set saldo / tambah / pengeluaran) → tanya balik.
        if (preg_match('/^\s*(?:saldo\s+)?(?:dompet|rekening|wallet)\s+([a-zA-Z0-9][a-zA-Z0-9\s]*?)\s+((?:rp\.?\s*)?[\d\.,]+(?:\s*(?:rb|ribu|rebu|k|jt|juta)\b)?)\s*[.!?]*\s*$/iu', $messageText, $walletAmountMatch)
            && ! \App\Services\Wallet\WalletCommandService::isGenericWalletName($walletAmountMatch[1])) {
            $walletAmount = (float) ($this->transactionExtractor->extractAmountFromText($walletAmountMatch[2]) ?? 0);
            if ($walletAmount > 0) {
                $this->walletCommand->promptWalletAmountClarification(trim($walletAmountMatch[1]), $walletAmount, $messageText);
                return;
            }
        }
        
        // FAST PATH 1.6: Budget Management (MUST BE BEFORE transaction detection!)
        // Handles: set budget, check budget
        // PRIORITY: Budget keywords take precedence over transaction keywords
        
        // 1.6a: Check budget - "cek budget", "lihat budget", "status budget"
        $checkBudgetKeywords = [
            'cek budget', 'lihat budget', 'status budget', 'budget saya',
            'daftar budget', 'list budget', 'budget apa aja',
            'cek anggaran', 'lihat anggaran', 'anggaran saya', 'daftar anggaran'
        ];
        foreach ($checkBudgetKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->budgetCommand->handleCheckBudget();
                return;
            }
        }
        
        // 1.6aa: Check insight - "cek insight", "analisis spending", "pola pengeluaran"
        $checkInsightKeywords = [
            'cek insight', 'lihat insight', 'analisis spending', 'pola pengeluaran',
            'insight keuangan', 'analisis keuangan', 'spending analysis'
        ];
        foreach ($checkInsightKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {
                try {
                    $insightService = new \App\Services\SpendingInsightService($this->message->tenant_id);
                    $this->replyService->sendReply($insightService->generateInsightReport());
                } catch (\Exception $e) {
                    Log::error('Error generating insight report', ['message_id' => $this->message->id, 'error' => $e->getMessage()]);
                    $this->replyService->sendReply("⚠️ *Gagal memuat insight*\n\nTerjadi kesalahan. Silakan coba lagi nanti.");
                }
                return;
            }
        }
        
        // 1.6ab: Check achievements - "lihat achievement", "badge saya", "cek badge"
        $checkAchievementKeywords = [
            'lihat achievement', 'cek achievement', 'badge saya', 'cek badge',
            'lihat badge', 'achievement saya', 'pencapaian saya'
        ];
        foreach ($checkAchievementKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {
                try {
                    $achievementService = new \App\Services\AchievementService($this->message->tenant_id);
                    $this->replyService->sendReply($achievementService->generateSummaryMessage());
                } catch (\Exception $e) {
                    Log::error('Error generating achievement report', ['error' => $e->getMessage()]);
                    $this->replyService->sendReply("⚠️ *Gagal memuat achievement*\n\nTerjadi kesalahan. Silakan coba lagi nanti.");
                }
                return;
            }
        }
        
        // 1.6ac0: Delete savings target - "hapus target menikah", "hapus tabungan nikah", "hapus target tabungan menikah"
        // MUST be checked BEFORE set target to prevent "hapus target tabungan" from matching "target tabungan"
        $deleteTargetKeywords = [
            'hapus target tabungan', 'hapus target', 'hapus tabungan', 'delete target', 'batalkan target',
            'hapus saving', 'remove target', 'hilangkan target'
        ];
        foreach ($deleteTargetKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {
                $this->savingsGoalService->handleDeleteSavingsTarget($messageText);
                return;
            }
        }
        
        // 1.6ac: Set target tabungan - "set target 10jt", "target nabung 5jt"
        $setTargetKeywords = [
            'set target', 'target tabungan', 'target nabung', 'mau nabung',
            'target saving', 'buat target'
        ];
        foreach ($setTargetKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->savingsGoalService->handleSetSavingsTarget($messageText);
                return;
            }
        }
        
        // 1.6ac2: Add to savings - "tabung 500rb", "nabung 1jt"
        // BUT if there's "untuk [purpose]" or "buat [purpose]", it's a new target!
        if (preg_match('/^(?:tabung|nabung|tambah\s+tabungan|isi\s+tabungan|ambah\s+tabungan)\s+(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?/i', $textLower)) {
            // Check if message contains "untuk [purpose]" or "buat [purpose]" - this should create a NEW target
            if (preg_match('/(?:tabung|nabung)\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+(?:untuk|buat)\s+(.+)/i', $messageText)) {
                // User wants to create a new savings target with a purpose
                // e.g., "tabung 1jt untuk menikah", "nabung 50jt buat umroh"
                $this->savingsGoalService->handleSetSavingsTarget($messageText);
                return;
            }

            $this->savingsGoalService->handleAddSavings($messageText);
            return;
        }
        
        // 1.6ac2b: Add to SPECIFIC savings target - "masuk 600rb ke tabung menikah", "setor 1jt ke tabungan nikah"
        // Patterns: "masuk [amount] ke tabung/tabungan [target name]"
        //           "setor [amount] ke target [name]"
        //           "tambah [amount] ke tabungan [name]"
        if (preg_match('/(?:masuk|setor|tambah|isi)\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+(?:ke\s+)?(?:tabung|tabungan|target)\s+/i', $textLower)) {
            $this->savingsGoalService->handleAddSavingsToTarget($messageText);
            return;
        }
        
        // 1.6ad: Check target tabungan - "cek target", "lihat target", "progress target"
        $checkTargetKeywords = [
            'cek target', 'lihat target', 'progress target', 'target saya',
            'daftar target', 'list target', 'cek terget', 'lihat terget'
        ];
        foreach ($checkTargetKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->savingsGoalService->handleCheckSavingsTarget();
                return;
            }
        }
        // "lihat semua tabungan", "cek tabungan", "daftar tabungan saya", "tabungan saya"
        if (preg_match('/^(?:(?:cek|lihat|daftar|list|tampilkan)\s+(?:semua\s+|seluruh\s+)?(?:target\s+)?tabungan|tabungan\s+(?:saya|ku|gw|gue))\b/i', trim($textLower))) {
            $this->savingsGoalService->handleCheckSavingsTarget();
            return;
        }
        
        // 1.6ae: Check subscriptions - "cek langganan", "subscription saya"
        $checkSubsKeywords = [
            'cek langganan', 'lihat langganan', 'subscription saya', 'cek subscription',
            'pengeluaran rutin', 'bayaran bulanan', 'tagihan bulanan', 'recurring'
        ];
        foreach ($checkSubsKeywords as $keyword) {
            // "bayar tagihan bulanan wifi 350rb" = transaksi, bukan lihat daftar langganan
            if (str_contains($textLower, $keyword) && ! \App\Services\WhatsApp\MessageSignals::hasMoneyAmount($textLower)) {

                try {
                    $trackerService = new \App\Services\SubscriptionTrackerService($this->message->tenant_id);
                    $this->replyService->sendReply($trackerService->generateSummaryMessage());
                } catch (\Exception $e) {
                    Log::error('Error viewing subscriptions', ['message_id' => $this->message->id, 'error' => $e->getMessage()]);
                    $this->replyService->sendReply("⚠️ *Gagal memuat langganan*\n\nTerjadi kesalahan. Silakan coba lagi.");
                }
                return;
            }
        }
        
        // 1.6af: Export PDF - "export pdf", "laporan pdf", "download laporan"
        $exportPdfKeywords = [
            'export pdf', 'laporan pdf', 'download laporan', 'download pdf',
            'kirim laporan', 'buat pdf', 'cetak laporan', 'unduh laporan',
            'generate pdf', 'print laporan', 'ekspor pdf'
        ];
        foreach ($exportPdfKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->reportService->handleExportPdf($messageText);
                return;
            }
        }
        
        // ==========================================
        // FAST-PATH: PRIORITY ORDER (HIGHEST FIRST)
        // ==========================================
        
        // 1.6af0: JAWABAN ASK-BACK — Cek pending_edit SEBELUM fast-path lain
        // Ini harus diposisikan PALING ATAS karena jawaban user ("Hiburan", "50rb", "pemasukan")
        // tidak memiliki format perintah yang jelas dan akan tertangkap oleh AI fallback
        // jika tidak dicek di sini.
        if (! $hasAmount || strlen($messageText) < 60) {
            $contextServiceForPending = new \App\Services\ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId());
            $pendingEdit = $contextServiceForPending->getPendingEdit();

            if ($pendingEdit) {
                // Validasi: pastikan pesan memang berbentuk jawaban untuk field yang ditanya.
                // Mencegah transaksi baru (mis. "makan siang 50rb") salah dianggap jawaban ask-back.
                $awaitingField = $pendingEdit['awaiting_field'] ?? '';
                $trimmedLower = trim($textLower);
                $isValidAnswer = match ($awaitingField) {
                    'amount' => (bool) preg_match('/\d/', $messageText),
                    'type'   => (bool) preg_match('/^(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)$/i', $trimmedLower),
                    'date'   => (bool) preg_match('/\d/', $messageText),
                    'category' => ! $hasAmount,
                    default  => ! $hasAmount,
                };

                if ($isValidAnswer) {
                    Log::info('Fast-path 1.6af0: Jawaban ask-back terdeteksi', [
                        'message' => $messageText,
                        'field'   => $awaitingField ?: 'unknown',
                    ]);

                    $this->transactionService->handleEditWithContext($messageText);
                    return;
                }
            }
        }
        
        // 1.6af1: Multi-line hapus/edit commands - process each line
        // Check if message has multiple lines with hapus/edit commands
        $lines = preg_split('/[\r\n]+/', trim($messageText));
        if (count($lines) > 1) {
            $commandPattern = '/^(hapus|delete|batal|ubah|edit|ganti|koreksi)\s+/i';
            $commandLines = array_filter($lines, fn($line) => preg_match($commandPattern, trim($line)));

            if (count($commandLines) >= 2) {
                // Multiple commands detected - process each
                $this->transactionService->handleMultipleTransactionCommands($lines);
                return;
            }
        }

        // 1.6af1.5: Delete wallet command - must run BEFORE transaction delete-by-keyword
        // e.g., "hapus dompet jago", "hapus dompet BCA Hadi"
        if (preg_match('/^(hapus|delete)\s+(dompet|rekening|akun|wallet|bank)\b/i', $textLower)) {
            $this->walletCommand->handleDeleteWallet($messageText);
            return;
        }
        
        // 1.6af2: Delete specific transaction by keyword - "hapus beli kue", "hapus makan siang"
        // Must have keyword after "hapus" but NOT "hapus transaksi terakhir" or "hapus semua"
        // EXCLUDE: "hapus target" and "hapus tabungan" - these are handled by delete savings target
        if (preg_match('/^(hapus|delete|batal)\s+(?!transaksi\s*$)(?!semua)(?!terakhir)(?!target)(?!tabungan)(?!saving)(?!dompet\b)(?!rekening\b)(?!akun\b)(?!wallet\b)(?!bank\b)/i', $textLower)) {
            // Check if it's "hapus transaksi [keyword]" or "hapus [keyword]"
            $isSpecificDelete = preg_match('/^(hapus|delete|batal)\s+(transaksi\s+)?[a-zA-Z]/i', $textLower);
            // "batal beli baju, beli celana 150rb" = transaksi pengganti, bukan perintah hapus
            $looksLikeReplacementTx = str_contains($textLower, ',') && preg_match('/\d/', $textLower);
            if ($isSpecificDelete && ! $looksLikeReplacementTx && !str_contains($textLower, 'terakhir') && !str_contains($textLower, 'semua')
                && !str_contains($textLower, 'target') && !str_contains($textLower, 'tabungan')) {
                $this->transactionService->handleDeleteTransactionByKeyword($messageText);
                return;
            }
        }
        
        // 1.6af2.6: Perintah edit AMBIGU → tanya balik, jangan kirim template
        // Menangkap: "Ubah kategori", "Ganti nominal", "Edit tanggal", "Ubah tipe"
        // Ini adalah kasus 4 dari chat log: user bilang "Ubah kategori" tapi bot kirim template
        $ambiguousEditPatterns = [
            '/^(ubah|ganti|edit|pindah(?:in)?)\s+(?:ke\s+)?kategori\s*$/i'   => 'category',
            '/^(ubah|ganti|edit)\s+(?:ke\s+)?(?:nominal|jumlah|harga)\s*$/i'  => 'amount',
            '/^(ubah|ganti|edit)\s+(?:ke\s+)?(?:tanggal|tgl)\s*$/i'           => 'date',
            '/^(ubah|ganti|edit)\s+(?:ke\s+)?tipe\s*$/i'                      => 'type',
        ];
        foreach ($ambiguousEditPatterns as $pattern => $field) {
            if (preg_match($pattern, $textLower)) {
                Log::info('Fast-path 1.6af2.6: Perintah edit ambigu terdeteksi', [
                    'message' => $messageText,
                    'field'   => $field,
                ]);
                $this->transactionService->askBackForEdit($field);
                return;
            }
        }
        
        // 1.6af2.4: Ubah TIPE transaksi terakhir — "ganti jadi pemasukan", "ubah ke pengeluaran"
        // Menangkap perintah ubah tipe TANPA nominal (misalnya dari chat log: "Ganti jadi 'pemasukan'")
        if (preg_match('/^(?:ganti|ubah|edit|koreksi)\s+(?:jadi|ke|menjadi)\s*[\'"]?(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)[\'"]?\s*$/i', $textLower)) {
            Log::info('Fast-path 1.6af2.4: Perintah ubah tipe transaksi terakhir', ['message' => $messageText]);
            $this->transactionService->handleEditWithContext($messageText);
            return;
        }
        
        // 1.6af3: Set opening balance / saldo awal - "saldo awal ubah jadi 1 jt",
        // "saldo awal set 500rb", "atur saldo awal jadi 2jt", "saldo awal saya 2 jt",
        // "saldo awal 3jt 400 rb"
        // Ditangani SEBELUM dengan handleEditWithContext supaya tidak dianggap
        // "edit transaksi terakhir" yang akan menjawab "tidak ada transaksi".
        // Wajib ada nominal & bukan pertanyaan: "berapa saldo awal saya?" → bukan set saldo.
        if (preg_match('/(?:saldo\s+awal|saldoawal)(?:\s+(?:saya|milik\s+kami|kami|rekening\s+saya))?(?:\s+(?:ubah|set|atur|edit|ganti|koreksi))?(?:\s+(?:jadi|menjadi|ke|sebesar))?\s*(.+?)\s*$/iu', $messageText, $saldoMatch)
            && preg_match('/\d/', $saldoMatch[1])
            && ! preg_match('/^\s*(?:berapa|brp|cek|lihat|apa|kapan|gimana|bagaimana)\b/iu', $textLower)
            && ! str_contains($messageText, '?')) {
            Log::info('Fast-path: Set saldo awal (opening balance)', ['message' => $messageText, 'amount_text' => $saldoMatch[1]]);
            // Gunakan dompet default user; $saldoMatch[1] = sisa teks nominal
            // (mis. "2 jt", "3jt 400 rb", "500rb")
            $this->handleOpeningBalance($saldoMatch[1]);
            return;
        }

        // 1.6af2.5: Edit last transaction by context - "edit jadi 45rb", "edit terakhir jadi 45rb"
        if ($hasAmount && preg_match('/^(ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?(?:terakhir\s+)?(?:jadi|ke)\b/i', $textLower)) {
            $this->transactionService->handleEditWithContext($messageText);
            return;
        }
        
        // 1.6af3: Edit specific transaction by keyword - "ubah beli kue jadi 25rb", "edit makan siang 30rb"
        // Must have keyword + amount
        // CATATAN: "ganti" ambigu — "ganti ban motor 44rb" = transaksi BARU
        // (beli jasa service), bukan edit. Karena itu "ganti" hanya dianggap
        // edit bila ada penanda niat edit ("jadi", "ke 50rb", "nominal", dst);
        // tanpa penanda → pesan jatuh ke FAST PATH 2 & dicatat sebagai transaksi baru.
        $gantiIsEdit = str_starts_with($textLower, 'ganti')
            && (bool) preg_match('/\b(?:jadi|menjadi|nominal|jumlah|harga|ke\s+\d)/i', $textLower);
        if (($gantiIsEdit || preg_match('/^(?:ubah|edit|koreksi)\s+(?!transaksi\s*$)(?!jadi\s)(?!ke\s)/i', $textLower)) && $hasAmount) {
            // Check if pattern matches: "ubah [keyword] [jadi/ke]? [amount]"
            $isSpecificEdit = preg_match('/^(ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?[a-zA-Z].+\d+\s*(rb|ribu|k|jt|juta)?/i', $textLower);
            if ($isSpecificEdit && !str_contains($textLower, 'terakhir')) {
                $this->transactionService->handleEditTransactionByKeyword($messageText);
                return;
            }
        }
        

        // 1.6ag0: "beli sepatu 300rb harusnya 400rb" — dua nominal + kata koreksi.
        // Ada transaksi baru-baru ini senilai 300rb → koreksi transaksi itu;
        // tidak ada → catat SATU transaksi dengan nominal yang benar (400rb).
        $inlineCorrection = \App\Support\AmountParser::correctionPair($messageText);
        if ($inlineCorrection && $this->transactionService->correctRecentAmount($inlineCorrection[0], $inlineCorrection[1])) {
            return;
        }

        // 1.6ag: Context-based Edit/Correction - "salah harusnya 50rb", "koreksi jadi 30rb"
        // Kata koreksi TEGAS → boleh di mana saja dalam kalimat.
        $editContextKeywords = $inlineCorrection ? [] : [
            'koreksi', 'harusnya', 'seharusnya', 'ubah jadi', 'ganti jadi',
            'yang bener', 'yang benar', 'ralat',
        ];
        $isEditContext = false;
        foreach ($editContextKeywords as $keyword) {
            // Use word boundary to avoid matching "peralatan" with "ralat"
            // Lepaskan syarat $hasAmount untuk niat koreksi tegas (mis. "Ganti jadi pemasukan")
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $textLower)) {
                $isEditContext = true;
                break;
            }
        }
        // Kata koreksi LEMAH ("salah", "bukan", "revisi") sering muncul di kalimat
        // transaksi biasa: "salah satu temen traktir 20rb", "makan siang bukan
        // sarapan 30rb", "revisi skripsi 100rb", "perbaikan motor 200rb".
        // Hanya dianggap koreksi bila DI AWAL pesan & langsung diikuti nominal /
        // kata penegas, atau berpola "bukan X tapi Y".
        if (! $isEditContext && ! $inlineCorrection) {
            $isEditContext = (bool) preg_match(
                '/^\s*(?:salah(?!\s+satu)|bukan|revisi)\b[\s,.:!-]*(?:$|\d|rp\b|jadi\b|nya\b|tadi\b|yang\b|nominal|kategori|itu\b|deh\b|dong\b)/iu',
                $textLower
            ) || (bool) preg_match('/^\s*bukan\s+.+\s+tapi\s+/iu', $textLower)
                // Tanpa nominal uang (mis. "yang tadi salah", "itu bukan makan") → tetap
                // koreksi seperti perilaku lama; yang berubah hanya kalimat bernominal.
                || (! $hasMoneyAmount && (bool) preg_match('/\b(?:salah(?!\s+satu)|bukan|revisi)\b/iu', $textLower));
        }
        if ($isEditContext) {
            $this->transactionService->handleEditWithContext($messageText);
            return;
        }

        // 1.6ag1: Koreksi arah transaksi tanpa nominal — "termasuk pengeluaran",
        // "itu pemasukan". Dulu tidak dikenali kata koreksi mana pun sehingga
        // user hanya dapat balasan "Format Tidak Dikenali".
        if (! $hasMoneyAmount && TransactionService::isTypeOnlyCorrection($messageText)) {
            $this->transactionService->handleEditWithContext($messageText);
            return;
        }

        // 1.6ag2: Quick Undo/Cancel - "undo", "ga jadi", "batalin", "yang tadi salah"
        // These should delete the last transaction without needing amount
        // Hanya bila SELURUH pesan adalah perintah batal — "ga jadi beli baju, beli celana 150rb"
        // atau "batalkan pesanan, kena fee 5rb" adalah transaksi, bukan perintah hapus.
        // Hapus tetap meminta konfirmasi Ya/Tidak (TransactionService::requestDeleteConfirmation).
        if (TransactionService::isUndoCommand($messageText)) {
            $this->transactionService->handleDeleteTransaction();
            return;
        }

        
        // 1.6ag3: Category correction by pattern - "air mineral kategori makanan", "beli susu ganti ke kesehatan"
        // Recognizes: [description] [kategori/ganti/ubah/ke] [category name]
        // Does NOT require prefix like "edit/ubah/ganti"
        // EXCEPTION: Skip if the phrase is "uang masuk", "uang keluar", "masuk uang", "keluar uang", "duit masuk", "duit keluar" — those are transactions, not corrections!
        if (preg_match('/^(.+?)\s+(?:kategori|ganti(?:in)?|ubah|pindah(?:in)?|masuk(?:in)?)\s+(?:ke\s+|jadi\s+)?(.+)$/i', $textLower, $corrMatches)) {
            $txKeyword = trim($corrMatches[1]);
            $catCandidate = \App\Services\Transaction\TransactionService::cleanCategoryCandidate(trim($corrMatches[2]));

            // Ops — "ubah kategori nya jadi makanan" → tanpa kata kunci
            // deskripsi (hanya kata perintah) → koreksi ke transaksi terakhir.
            if (\App\Services\Transaction\TransactionService::isCommandOnlyKeyword($txKeyword)) {
                $txKeyword = '';
            }

            // Validate: txKeyword should look like a transaction description (not empty, not just a number)
            // catCandidate should not be just a number
            // AND the phrase is NOT a transaction phrase like "uang masuk ke [dompet]"!
            $isTransactionPhrase = preg_match('/\b(uang|duit)\s+(masuk|keluar)\b/i', $textLower) || preg_match('/\b(masuk|keluar)\s+(uang|duit)\b/i', $textLower);
            $keywordValid = $txKeyword === '' || (strlen($txKeyword) >= 2 && !preg_match('/^\d+$/', $txKeyword));
            // Koreksi kategori tidak membawa nominal. Ada nominal → transaksi baru:
            // "service motor ganti oli 50rb", "gaji masuk rekening bca 5jt".
            if (!$isTransactionPhrase && ! $hasMoneyAmount && $keywordValid && $catCandidate !== '' && strlen($catCandidate) >= 2 && !preg_match('/^\d+(\s*(rb|ribu|k|jt|juta))?$/i', $catCandidate)) {
                $this->transactionService->handleEditTransactionWithCorrection($txKeyword, $catCandidate);
                return;
            }
        }

        // (1.6ah Daily Reminder dihapus: duplikat persis 1.5c-pre di atas,
        // sehingga tidak pernah tercapai.)

        // 1.6aj: Natural Language Stats/Analysis
        if (preg_match('/(gimana|bagaimana)\s+(?:kondisi|status|kabar)\s+keuangan/i', $textLower) ||
            str_contains($textLower, 'pengeluaran terbesar') ||
            str_contains($textLower, 'keuanganku') ||
            str_contains($textLower, 'analisis keuangan')) {
                
            $this->analysisCommandService->handleCheckStatisticsWithAI();
            return;
        }
        
        // 1.6ai: Delete budget - "hapus budget makan", "delete budget transport"
        $deleteBudgetKeywords = [
            'hapus budget', 'delete budget', 'hilangkan budget', 'buang budget',
            'hapus anggaran', 'delete anggaran', 'hilangkan anggaran'
        ];
        foreach ($deleteBudgetKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->budgetCommand->handleDeleteBudget($messageText);
                return;
            }
        }
        
        // 1.6b: Add to budget - "tambah budget makan 100rb", "nambah budget transport 50rb"
        $addBudgetKeywords = [
            'tambah budget', 'nambah budget', 'tambahin budget', 'add budget',
            'tambah anggaran', 'nambah anggaran', 'tambahin anggaran'
        ];
        foreach ($addBudgetKeywords as $keyword) {
            if (str_contains($textLower, $keyword) && $hasAmount) {

                $this->budgetCommand->handleAddBudget($messageText, null);
                return;
            }
        }
        
        // 1.6c: Set budget - "set budget makan 500rb", "budget transport 300rb"
        // Skip if this is a QUESTION about budgeting (e.g., "cara budgeting", "gimana buat budget")
        $budgetQuestionPrefixes = ['cara ', 'gimana ', 'bagaimana ', 'caranya ', 'gmn ', 'gmana ', 'how to '];
        $isBudgetQuestion = false;
        foreach ($budgetQuestionPrefixes as $prefix) {
            if (str_starts_with($textLower, $prefix)) {
                $isBudgetQuestion = true;
                break;
            }
        }
        
        if (!$isBudgetQuestion) {
            $setBudgetKeywords = [
                'set budget', 'atur budget', 'buat budget', 'budget ',
                'set anggaran', 'atur anggaran', 'buat anggaran', 'anggaran '
            ];
            foreach ($setBudgetKeywords as $budgetKeyword) {
                if (str_contains($textLower, $budgetKeyword) && $hasAmount) {

                    $this->budgetCommand->handleSetBudget($messageText, null);
                    return;
                }
            }
        }
        
        // FAST PATH 3: Wallet/Payment Method Management
        // e.g., "tambah dompet BCA", "tambah metode pembayaran Gopay", "lihat dompet", "daftar saldo"
        // REMOVED: "tambah saldo" from this list to prevent conflict with Top Up logic
        $walletKeywords = [
            'tambah dompet', 'buat dompet', 'add wallet',
            'tambah metode pembayaran', 'tambah rekening', 'tambah bank',
            'tambah akun', 'daftar dompet', 'lihat dompet', 'cek dompet',
            'daftar rekening', 'lihat rekening', 'daftar akun', 'list wallet',
            'total dompet', 'ringkasan dompet', 'total saldo', 'ringkasan saldo',
            'hapus dompet', 'hapus rekening', 'delete wallet',
            // Keyword untuk "cek semua dompet", "lihat semua dompet", dll.
            'semua dompet', 'seluruh dompet', 'semua rekening', 'seluruh rekening',
            'semua akun', 'seluruh akun', 'semua saldo', 'seluruh saldo',
            // Informal balance queries
            'sisa saldo', 'saldo berapa', 'saldo gw', 'saldo gue', 'saldo ku',
            'duit gw', 'duit gue', 'duit ku', 'uang gw', 'uang gue', 'uang ku',
            'sisa duit', 'sisa uang', 'berapa saldo', 'berapa sisa',
            'cek saldo', 'lihat saldo', 'saldo saya'
        ];

        // Question prefixes - if message starts with these, it's asking HOW to do something, not an actual command
        $questionPrefixes = [
            'cara ', 'gimana ', 'bagaimana ', 'gimana caranya', 'bagaimana caranya',
            'caranya ', 'gmn ', 'gmana ', 'how to ', 'how do i '
        ];
        $isQuestionAboutWallet = false;
        foreach ($questionPrefixes as $prefix) {
            if (str_starts_with($textLower, $prefix)) {
                $isQuestionAboutWallet = true;
                break;
            }
        }
        
        // FAST PATH 3.1a: Total financial query
        // e.g., "berapa total pengeluaran", "berapa total pemasukan bulan ini",
        // "total pengeluaran?", "total pemasukan bulan ini", "jumlah pengeluaran"
        // Pola ini HARUS diuji sebelum specific wallet query, karena kata "total"
        // tidak boleh ditangkap sebagai nama dompet.
        $totalQueryPatterns = [
            '/^(?:berapa|brp|total|jumlah|rekapan|rekap)\s+(?:total\s+)?(?:pengeluaran|pemasukan|pendapatan|penghasilan|omset|omzet|spending|income|expense|cash\s*flow|arus\s+kas)\b/i',
        ];

        foreach ($totalQueryPatterns as $pattern) {
            if (preg_match($pattern, $messageText)) {
                $this->financialQueryHandler->handleQuery($messageText);
                return;
            }
        }

        // FAST PATH 3.1: Specific wallet balance query
        // e.g., "saldo BCA berapa?", "uang di gopay berapa?", "berapa di dana?"
        $specificWalletPatterns = [
            '/(?:saldo|uang|duit)\s+(?:di\s+)?(\w+)\s*(?:berapa|brp)\??/i',  // "saldo BCA berapa?"
            // "berapa di dana?", "berapa saldo bca?" — wajib ada "saldo"/"di" agar
            // "berapa lama trial finwa?" tidak dianggap dompet bernama "lama".
            '/berapa\s+(?:saldo\s+(?:di\s+)?|(?:uang|duit)\s+di\s+|di\s+)(\w+)\??/i',
            '/(?:cek|lihat)\s+saldo\s+(\w+)/i',                               // "cek saldo gopay"
        ];
        
        // Kata-kata yang MENUNJUKKAN query keuangan, bukan nama dompet.
        // Jika kata tertangkap adalah salah satu dari ini, jangan jalankan
        // handleViewSpecificWallet - biarkan pesan jatuh ke jalur lain.
        $queryTermDenylist = [
            'total', 'semua', 'seluruh', 'sisa', 'uang', 'duit', 'dompet',
            'rekening', 'akun', 'pengeluaran', 'pemasukan', 'pendapatan',
            'penghasilan', 'omset', 'omzet', 'spending', 'income', 'expense',
            'cashflow', 'cash', 'arus', 'kas',
            'berapa', 'brp',
        ];

        foreach ($specificWalletPatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $walletName = $matches[1] ?? null;
                if ($walletName && strlen($walletName) >= 2) {
                    // Skip jika captured term adalah kata query (bukan nama dompet)
                    $isQueryTerm = false;
                    foreach ($queryTermDenylist as $term) {
                        if (stripos($walletName, $term) === 0) {
                            $isQueryTerm = true;
                            break;
                        }
                    }
                    if ($isQueryTerm) {
                        continue;
                    }
                    // Hanya nama yang memang dompet: dompet milik tenant ATAU nama
                    // bank/e-wallet umum. Kata acak ("lama", "banyak") dilewati.
                    $knownWalletNames = ['bca', 'bri', 'bni', 'mandiri', 'bsi', 'btn', 'cimb', 'permata', 'danamon',
                        'jenius', 'jago', 'blu', 'seabank', 'gopay', 'ovo', 'dana', 'shopeepay', 'linkaja', 'cash', 'tunai'];
                    $isWallet = in_array(strtolower($walletName), $knownWalletNames, true)
                        || \App\Models\Balance::where('tenant_id', $this->message->tenant_id)
                            ->where('is_active', true)
                            ->whereRaw('LOWER(account_name) LIKE ?', ['%'.strtolower($walletName).'%'])
                            ->exists();
                    if (! $isWallet) {
                        continue;
                    }
                    $this->walletCommand->handleViewSpecificWallet($walletName);
                    return;
                }
            }
        }
        
        // Only process wallet management if NOT a question
        if (!$isQuestionAboutWallet) {
            // SPECIAL CHECK: Handle "cek semua dompet", "lihat seluruh rekening", etc.
            // Pattern: [cek/lihat/daftar] + [semua/seluruh] + [dompet/rekening/akun/saldo]
            $listAllWalletsPattern = '/^(?:cek|lihat|daftar|tampilkan)\s+(?:semua|seluruh)\s+(?:dompet|rekening|akun|saldo|(?:uang|duit)(?!\s+(?:keluar|masuk)))/i';
            if (preg_match($listAllWalletsPattern, $textLower) && strlen($messageText) <= 100) {
                $this->walletCommand->handleListWallets();
                return;
            }

            foreach ($walletKeywords as $walletKeyword) {
                // Ignore long messages (likely tutorials or forwarded info) to prevent false positives
                if (strlen($messageText) > 100) {
                    continue;
                }

                // Use str_starts_with to ensure command is at the START of message
                // This prevents "hapus dompet Tambah Saldo Dana" from matching "tambah dompet"
                if (str_starts_with($textLower, $walletKeyword)) {
                    
                    // Determine action type based on THE MATCHED KEYWORD (not the whole text)
                    if (str_contains($walletKeyword, 'tambah') || str_contains($walletKeyword, 'buat') || str_contains($walletKeyword, 'add')) {
                        $this->walletCommand->handleAddWallet($messageText);
                    } elseif (str_contains($walletKeyword, 'hapus') || str_contains($walletKeyword, 'delete')) {
                        $this->walletCommand->handleDeleteWallet($messageText);
                    } else {
                        $this->walletCommand->handleListWallets();
                    }
                    return;
                }
            }
        }

        // FAST PATH 3.1: Set/Edit Wallet Balance (SUPPORTS MULTI-LINE)
        // e.g., "set saldo O menjadi 49.000", "ubah saldo BCA jadi 100rb", "ganti saldo Dana ke 1jt"
        // NEW: "saldo dompet utama 1 juta", "saldo BCA 500rb"
        // Multi-line: "Update saldo BRI 1.598.059\nUpdate saldo BJB 5.165.856\nUpdate saldo BSI 31.163.373"
        $setBalancePatterns = [
            '/(?:set|atur|ubah|edit|ganti)\s+saldo\s+([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke|sebesar|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            '/saldo\s+([a-zA-Z0-9\s]+?)\s*=\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            '/(?:update|koreksi)\s+saldo\s+([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke|sebesar)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            '/saldo\s+([a-zA-Z0-9\s]+?)\s+(?:sekarang|jadi|menjadi|sebesar)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // Simple format: "update saldo BCA 500rb" or "set saldo Dana 1jt"
            '/(?:update|set|atur|ubah|edit|ganti)\s+saldo\s+([a-zA-Z0-9]+)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // NEW: Direct format: "saldo dompet utama 1 juta", "saldo BCA 500rb"
            // Pattern: saldo [nama dompet] [nominal] - matches when there's a wallet name followed by amount
            // "saldo keluar/masuk 500k ..." = transaksi, bukan dompet bernama "keluar"
            '/^saldo\s+(?!(?:keluar|masuk|berkurang|bertambah|kepake|kepakai|terpakai)\b)(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?)/i',
        ];
        
        // Check if this is a multi-line message with multiple balance updates
        $lines = preg_split('/[\r\n]+/', $messageText);
        $lines = array_filter($lines, fn($line) => !empty(trim($line)));
        $matchCount = 0;
        
        foreach ($lines as $line) {
            foreach ($setBalancePatterns as $pattern) {
                if (preg_match($pattern, trim($line))) {
                    $matchCount++;
                    break; // One match per line is enough
                }
            }
        }
        
        // If multiple lines match balance update patterns, use batch handler
        if ($matchCount > 1) {
            $this->walletCommand->handleMultipleSetWalletBalance($messageText);
            return;
        }
        
        // Single line match - use regular handler
        foreach ($setBalancePatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $this->walletCommand->handleSetWalletBalance($messageText);
                return;
            }
        }

        // FAST PATH 2: Transaction (transaction keyword + amount + NO query keyword with period)
        // e.g., "Makan Pagi Hara Chicken 60rb", "beli kopi 25rb", "gaji 5jt"
        // EXCEPTION: Patterns that need AI for proper intent classification
        $needsAIPatterns = [
            '/(?:punya|ada)\s+(?:uang|duit)\s+(?:di|ke)/i',  // "punya uang di Dana 128k" = income
            '/(?:saya|aku)\s+(?:punya|ada)\s+/i',  // "Saya punya 128k" = income
            '/^transfer\s+\d/i',  // "transfer 500rb" = ambiguous (income or expense?)
        ];
        
        $needsAIForIntent = false;
        foreach ($needsAIPatterns as $aiPattern) {
            if (preg_match($aiPattern, $messageText)) {
                $needsAIForIntent = true;
                break;
            }
        }
        
        if ($hasTransactionKeyword && $hasMoneyAmount && ! $looksLikeQuery && !$needsAIForIntent) {
            // ══ Opsi A (Hybrid.md §6A: "hybrid untuk kasus ambigu/tak dikenal") ══
            // Kategori diuji dulu oleh inferensi lokal (keyword + koreksi belajar).
            // Pesan yang SUDAH dikenal → jalur tetap instan, TANPA panggilan LLM.
            // LLM hanya dibayar saat inferensi lokal jatuh ke *_lainnya inilah
            // gerbang "hambatan selektif": biaya ~10 detik hanya untuk kasus langka.
            $localCandidate = $this->transactionExtractor->extractTransactionLocally($messageText);
            $localInference = null;

            if (is_array($localCandidate)) {
                $localInference = app(\App\Services\Transaction\CategoryInferenceService::class)->infer(
                    $localCandidate['description'] ?? $messageText,
                    ($localCandidate['type'] ?? 'expense') === 'income',
                    $this->message->tenant_id,
                );
            }

            $localCat = is_array($localInference) ? (string) ($localInference['category_type'] ?? '') : '';
            $needsHybridHelp = $localCat === '' || str_ends_with($localCat, '_lainnya');

            // null = jalur lama penuh (ekstraksi lokal oleh handleTransaction).
            $hybridFallbackEntities = null;
            if ($needsHybridHelp) {
                $hybridFallbackEntities = $this->tryHybridForFastPath($messageText, $localCat, $hasDebtKeyword);
            }

            // Proceed to handleTransaction with null finwaEntities - it will use local extraction
            $this->transactionService->handleTransaction($messageText, $hybridFallbackEntities);

            // Check if handleTransaction detected an ambiguous pattern
            if ($this->transactionService->lastAmbiguousResult !== null) {
                $this->handleAmbiguousTransaction($this->transactionService->lastAmbiguousResult, $contextService);
            }
            return;
        }
        
        // FAST PATH 3.5a: Delete All Transactions Only (keeps wallets)
        // e.g., "hapus transaksi semuanya", "hapus semua transaksi", "konfirmasi hapus transaksi"
        $deleteTransactionsKeywords = [
            'hapus transaksi semuanya',
            'hapus transaksi semua',
            'hapus semua transaksi',
            'delete all transactions',
            'clear all transactions',
            'hapus riwayat transaksi',
            'konfirmasi hapus transaksi',  // Confirmation keyword
            'ya hapus semua transaksi',    // Alternative confirmation
        ];
        
        // Perintah DESTRUKTIF: hanya bila kata kunci ada di AWAL pesan (boleh
        // diawali "tolong/mau/saya mau"), bukan sekadar terkandung di kalimat.
        $destructivePrefix = '/^\s*(?:(?:tolong|mohon|saya|aku|gue|gw)\s+)?(?:(?:mau|ingin|pengen|minta)\s+)?';
        $startsWithCommand = fn (string $kw) => (bool) preg_match($destructivePrefix.preg_quote($kw, '/').'\b/u', $textLower);

        foreach ($deleteTransactionsKeywords as $keyword) {
            if ($startsWithCommand($keyword)) {

                $this->accountCommandService->handleDeleteAllTransactions($messageText);
                return;
            }
        }
        
        // FAST PATH 3.5b: Full Reset Account - destructive operation (includes wallets, transactions, reminders)
        // e.g., "reset akun", "reset semua data", "reset transaksi"
        $resetKeywords = [
            'reset akun',
            'reset transaksi',
            'reset semua data',
            'reset data',
            'reset dompet',
            'hapus semua data',
            'reset rekening',
            'reset keuangan',
            'clear all data',
            'reset akun reset transaksi',
            'kembali baru',
            'hapus akun dan mulai ulang',
            'restart akun',
            'mulai dari awal',
            'konfirmasi reset',
            'reset sekarang',
            'lanjut reset',
            'ya reset',
            'oke reset'
        ];
        
        foreach ($resetKeywords as $resetKeyword) {
            if ($startsWithCommand($resetKeyword)) {

                $this->accountCommandService->handleResetAccount($messageText);
                return;
            }
        }
        
        // FAST PATH 3.6: View Transaction List
        // e.g., "daftar transaksi hari ini", "lihat transaksi", "list transaksi"
        $viewTransactionKeywords = [
            'daftar transaksi',
            'lihat transaksi',
            'list transaksi',
            'cek transaksi',
            'transaksi hari ini',
            'transaksi kemarin',
            'riwayat transaksi',
            'history transaksi',
            'belanjaan hari ini',
            'daftar belanjaan',
            'pengeluaran hari ini',
        ];
        
        foreach ($viewTransactionKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {
                $this->transactionService->handleViewTransactions();
                return;
            }
        }

        // FAST PATH 4: FAQ/General Questions about the app
        // e.g., "bagaimana cara pakai?", "bisa untuk grup?", "apa itu finwa?"
        $faqResult = $this->faqService->checkAndHandleFAQ($messageText);
        if ($faqResult) {
            return;
        }

        // ══ HYBRID (Hybrid.md Fase 2, §7-2a OPSI C) ══
        // Titik injeksi TUNGGAL: setelah FAQ & semua fast-path/pending-state,
        // sebelum classifyIntent. Guard murah (deterministik) dulu sebelum
        // memanggil LLM (network). `$hasQueryKeyword && $hasPeriodKeyword`
        // → pesan dwimakna ("pengeluaran 500rb bulan ini") TIDAK disela
        // (matriks 2e no.1). Setiap syarat gagal → LEPAS SARINGAN,
        // jalur lama di bawah 100% tidak berubah (jatuh-when-in-doubt).
        $hybridMeta = is_array($this->message->metadata)
            ? $this->message->metadata
            : json_decode($this->message->metadata ?? '{}', true);
        $isGroupMessage = (bool) ($hybridMeta['is_group'] ?? false);

        // ══ PERTANYAAN NASIHAT KEUANGAN → Chat LLM (sebelum classifier) ══
        // "cara agar bisa menabung ?", "tips hemat bulan ini", "apa itu dana darurat".
        // FAQ sudah melewatkan topik edukasi keuangan; tanpa aturan ini classifier
        // FinWa-AI sering memberi label help/query lalu pertanyaan tidak terjawab.
        // LLM gagal/tidak aktif → lanjut ke jalur lama (tidak ada perubahan).
        if (! $isGroupMessage && $signals->isAdviceQuestion() && $this->answerAdviceQuestion($messageText)) {
            return;
        }

        // Ambigu transfer ("transfer 500rb") → wajib klarifikasi tipe (matriks 2e no.6).
        $isAmbiguousTransfer = preg_match('/^transfer\s+\d/i', $messageText)
            && ! preg_match('/transfer\s+(masuk|keluar|ke|dari|terima)/i', $textLower);

        if ($hasAmount
            && ! $hasDebtKeyword
            && ! $isGroupMessage
            && ! $isAmbiguousTransfer
            && ! $looksLikeQuery
            && $this->hybridAvailable()
        ) {
            $hybridStart = hrtime(true);
            $hybridResult = $this->hybridExtractor->extract($messageText);
            $hybridLatency = (int) ((hrtime(true) - $hybridStart) / 1_000_000);

            $hybridDecision = LlmExtractionLog::DECISION_UNAVAILABLE;
            $hybridValidated = null;

            if ($this->hybridValidator->shouldIntercept($hybridResult)) {
                $hybridValidated = $this->hybridValidator->validate($messageText, $hybridResult);

                if ($hybridValidated['passed']) {
                    $hybridEntities = [
                        '_source' => 'hybrid',
                        'nominal' => $hybridValidated['amount'],
                        'kategori' => $hybridValidated['kategori'],
                        'category_type' => $hybridValidated['category_type'],
                        'is_income' => $hybridValidated['is_income'],
                        'catatan' => $messageText,
                        'merchant' => null,
                    ];

                    Log::info('Hybrid: hasil LLM lolos validator → intercept', [
                        'message_id' => $this->message->id,
                        'amount' => $hybridEntities['nominal'],
                        'type' => $hybridValidated['is_income'] ? 'income' : 'expense',
                        'kategori' => $hybridEntities['kategori'],
                        'confidence' => $hybridResult->confidence,
                    ]);

                    $this->logHybridExtraction($messageText, $hybridResult, $hybridValidated,
                        LlmExtractionLog::DECISION_INTERCEPTED, $hybridLatency);

                    $this->transactionService->handleTransaction($messageText, $hybridEntities);

                    if ($this->transactionService->lastAmbiguousResult !== null) {
                        $this->handleAmbiguousTransaction($this->transactionService->lastAmbiguousResult, $contextService);
                    }

                    return;
                }

                $hybridDecision = LlmExtractionLog::DECISION_REJECTED;
            } elseif (! $hybridResult->isUsable()) {
                $hybridDecision = LlmExtractionLog::DECISION_UNAVAILABLE;
            } else {
                $hybridDecision = LlmExtractionLog::DECISION_NOT_INTERCEPTED;
            }

            // Lolos saringan (confidence rendah, intent query, dll.) atau
            // ditolak validator → jalur lama di bawah yang menentukan.
            $this->logHybridExtraction($messageText, $hybridResult, $hybridValidated,
                $hybridDecision, $hybridLatency);
        }
        // ══ AKHIR HYBRID — jalur lama di bawah tidak disentuh ══

        // Classify intent (query, transaction, or irrelevant) for regular text messages
        // Try FinWa-AI first (faster, deterministic), fallback to AIProcessorService
        $finwaService = new FinWaAIService();
        $intentResult = null;
        $finwaEntities = null;
        
        if ($finwaService->isEnabled()) {

            
            $intentResult = $finwaService->classifyIntent($messageText, $this->message->sender_id);
            
            if ($intentResult['success']) {
                $finwaEntities = $intentResult['data']['entities'] ?? [];
                $finwaIntent = $intentResult['data']['finwa_intent'] ?? 'unknown';
                
                // Store AI insights (sentiment & suggestion)
                $this->currentSentiment = $intentResult['data']['sentiment'] ?? null;
                $this->currentSuggestion = $intentResult['data']['suggestion'] ?? null;
                
                // BACKUP: Save to Cache to prevent state loss
                if ($this->currentSentiment) {
                    Cache::put('finwa_sentiment_' . $this->message->id, $this->currentSentiment, 300);
                }
                if ($this->currentSuggestion) {
                    Cache::put('finwa_suggestion_' . $this->message->id, $this->currentSuggestion, 300);
                }
                
                // ALWAYS add finwa_intent to entities so handleTransaction can use it
                // Initialize as array if null
                if ($finwaEntities === null) {
                    $finwaEntities = [];
                }
                $finwaEntities['_finwa_intent'] = $finwaIntent;

                // Koreksi intent hutang/piutang dari teks bila FinWa-AI salah klasifikasi
                // (mis. "Piutang Noki 20jt" → catat_pengeluaran). Hanya bila ada nominal,
                // agar query seperti "cek piutang" tidak ikut di-override.
                if ($hasDebtKeyword && $hasAmount && ! in_array($finwaIntent, ['catat_hutang', 'catat_piutang', 'bayar_hutang', 'terima_piutang'], true)) {
                    $localDebtIntent = $this->transactionExtractor->detectDebtIntent($messageText);
                    if ($localDebtIntent !== null) {
                        Log::info('Debt intent overridden locally', [
                            'message_id' => $this->message->id,
                            'finwa_intent' => $finwaIntent,
                            'local_debt_intent' => $localDebtIntent,
                        ]);
                        $finwaIntent = $localDebtIntent;
                        $finwaEntities['_finwa_intent'] = $localDebtIntent;
                    }
                }

                // Koreksi arah "X bayar hutang" → terima piutang (mis. "Rodi bayar hutang 600rb").
                if ($finwaIntent === 'bayar_hutang') {
                    $payDirection = $this->transactionExtractor->detectDebtPayDirection($messageText);
                    if ($payDirection !== null) {
                        Log::info('Debt intent direction overridden', [
                            'message_id' => $this->message->id,
                            'original_intent' => 'bayar_hutang',
                            'new_intent' => $payDirection,
                        ]);
                        $finwaIntent = $payDirection;
                        $finwaEntities['_finwa_intent'] = $payDirection;
                    }
                }

                // Handle special intents directly from FinWa-AI
                if ($finwaIntent === 'sapa') {
                    $this->greetingService->handleSpecialIntent('sapa');
                    return;
                }

                // help / tanya_finwa: classifier sering memberi label ini untuk
                // PERTANYAAN UMUM ("cara agar bisa menabung ?"). Dulu langsung dibalas
                // Panduan statis sehingga Chat LLM tidak pernah dipanggil.
                // Sekarang: FAQ spesifik → Chat LLM → Panduan (hanya bila keduanya gagal,
                // atau user memang minta panduan).
                if ($finwaIntent === 'help' || $finwaIntent === 'tanya_finwa') {
                    if ($finwaIntent === 'tanya_finwa' && $this->faqService->checkAndHandleFAQ($messageText)) {
                        return;
                    }
                    if (! $this->isExplicitHelpRequest($textLower)
                        && ! $isGroupMessage
                        && $this->answerAdviceQuestion($messageText)) {
                        return;
                    }
                    $this->greetingService->handleSpecialIntent('help');
                    return;
                }
                
                $confidence = $intentResult['data']['confidence'] ?? 0;
                
                // GUARD: Check if message contains transaction keywords but AI classified as check_balance/query/correction
                // This prevents "Naik TJ" from triggering "Ringkasan Keuangan"
                // AND prevents "Beli peralatan kos 126k" from triggering edit/correction
                $txKeywords = ['beli', 'bayar', 'naik', 'jajan', 'makan', 'minum', 'ngopi', 'isi', 'topup', 'transfer', 'ongkos', 'parkir', 'tol', 'ojek', 'grab', 'gojek', 'baju', 'pakaian', 'celana', 'sepatu', 'tas', 'jaket', 'jilbab', 'kerudung', 'sandal', 'sendal', 'kaos', 'kemeja', 'gamis', 'hijab', 'fashion'];
                $hasTxKeyword = false;
                $msgLower = strtolower($messageText);
                foreach($txKeywords as $k) {
                    if (str_contains($msgLower, " $k ") || str_starts_with($msgLower, "$k ")) {
                        $hasTxKeyword = true;
                        break;
                    }
                }

                // If it has transaction keywords, prevent entering query/check/edit mode
                if ($hasTxKeyword && in_array($finwaIntent, ['cek_saldo', 'cek_budget', 'cek_statistik', 'cek_target', 'query', 'unknown', 'koreksi_transaksi', 'edit_transaksi'])) {
                    // Fallthrough to transaction handler logic below
                    Log::info('Overriding AI intent to transaction due to keyword detection', [
                        'original_intent' => $finwaIntent,
                        'message' => $messageText
                    ]);
                    
                    // FORCE UPDATE intent data so fallback logic below treats it as transaction
                    $finwaIntent = 'catat_pengeluaran';
                    if (isset($intentResult['data'])) {
                        $intentResult['data']['intent'] = 'catat_pengeluaran';
                    }
                } else {
                    // Handle catat pengeluaran/pemasukan
                    if ($finwaIntent === 'catat_pengeluaran' || $finwaIntent === 'catat_pemasukan') {
                        // Strict guard: pesan harus mengandung kata kunci transaksi/kategori
                        if (!$hasTransactionKeyword) {
                            if ($hasAmount) {
                                $this->sendTransactionConfirmationPrompt($messageText, $contextService);
                                return;
                            }
                            Log::info('AI mengklasifikasikan sebagai transaksi, tetapi diblokir oleh Strict Keyword Guard karena tidak ada kata kunci kategori', [
                                'message_id' => $this->message->id,
                                'intent' => $finwaIntent,
                                'message' => $messageText
                            ]);
                            return; // Abaikan pesan, tidak dianggap transaksi
                        }
                        
                        $this->transactionService->handleTransaction($messageText, $finwaEntities);

                        // Check if handleTransaction detected an ambiguous pattern
                        if ($this->transactionService->lastAmbiguousResult !== null) {
                            $this->handleAmbiguousTransaction($this->transactionService->lastAmbiguousResult, $contextService);
                        }
                        return;
                    }

                    // Handle hutang/piutang (empat aliran) — route ke handleTransaction.
                    // Validasi keyword & mapping kategori dilakukan di dalam TransactionService (debt guard).
                    if (in_array($finwaIntent, ['catat_hutang', 'catat_piutang', 'bayar_hutang', 'terima_piutang'], true)) {
                        $this->transactionService->handleTransaction($messageText, $finwaEntities);

                        if ($this->transactionService->lastAmbiguousResult !== null) {
                            $this->handleAmbiguousTransaction($this->transactionService->lastAmbiguousResult, $contextService);
                        }
                        return;
                    }

                    // Handle hapus transaksi
                    if ($finwaIntent === 'hapus_transaksi') {
                        // Check if it's "hapus semua transaksi"
                        if (str_contains(strtolower($messageText), 'semua')) {
                            $this->accountCommandService->handleDeleteAllTransactions($messageText);
                            return;
                        }
                        // AI kadang menebak hapus untuk kalimat transaksi ("ga jadi beli X, beli Y 50rb")
                        // → hanya hapus bila pesan memang perintah batal; selain itu lanjut alur normal.
                        if (TransactionService::isUndoCommand($messageText)) {
                            $this->transactionService->handleDeleteTransaction();
                            return;
                        }
                    }
                    
                    // Handle lihat transaksi
                    if ($finwaIntent === 'lihat_transaksi') {
                        $this->transactionService->handleViewTransactions();
                        return;
                    }
                    
                    // Handle edit transaksi
                    if ($finwaIntent === 'edit_transaksi') {
                        $this->transactionService->handleEditTransaction($messageText, $finwaEntities);
                        return;
                    }
                    
                    // Handle set reminder
                    if ($finwaIntent === 'set_reminder') {
                        $this->reminderService->handleSetReminder($messageText, $finwaEntities);
                        return;
                    }
                    
                    // ========== NEW INTENTS (AI Enhancement Phase) ==========
                    
                    // Ringkasan berperiode adalah laporan transaksi, bukan cek saldo dompet.
                    if (($finwaIntent === 'cek_saldo' || $finwaIntent === 'cek_cashflow')
                        && preg_match('/\b(ringkasan|rekap|rekapan|rangkuman|laporan)\b/u', $textLower)
                        && $hasPeriodKeyword) {
                        $this->financialQueryHandler->handleQuery($messageText);
                        return;
                    }

                    // Handle cek saldo / cek cashflow
                    if ($finwaIntent === 'cek_saldo' || $finwaIntent === 'cek_cashflow') {
                        $this->walletCommand->handleCheckBalance($messageText);
                        return;
                    }
                    
                    // Handle cek budget
                    if ($finwaIntent === 'cek_budget') {
                        $this->budgetCommand->handleCheckBudget();
                        return;
                    }
                    
                    // Handle cek statistik
                    if ($finwaIntent === 'cek_statistik') {
                        $this->analysisCommandService->handleCheckStatisticsWithAI();
                        return;
                    }
                    
                    // Handle cek target
                    if ($finwaIntent === 'cek_target') {
                        $this->savingsGoalService->handleCheckTarget();
                        return;
                    }
                    
                    // Handle set budget
                    if ($finwaIntent === 'set_budget') {
                        $this->budgetCommand->handleSetBudget($messageText, $finwaEntities);
                        return;
                    }
                    
                    // Handle set target
                    if ($finwaIntent === 'set_target') {
                        $this->savingsGoalService->handleSetTarget($messageText, $finwaEntities);
                        return;
                    }
                    
                    // Handle export laporan
                    if ($finwaIntent === 'export_laporan') {
                        $this->reportService->handleExportPdf('export laporan bulan ini');
                        return;
                    }
                }
            }
        }
        
        // Fallback to AIProcessorService if FinWa-AI failed or is disabled
        if (!$intentResult || !$intentResult['success']) {

            
            $aiService = new AIProcessorService();
            $intentResult = $aiService->classifyIntent($messageText);
        }
        
        if (!$intentResult['success']) {
            Log::warning('Failed to classify intent, defaulting to transaction', [
                'message_id' => $this->message->id
            ]);
            $intent = 'transaction';
        } else {
            $intent = $intentResult['data']['intent'] ?? 'transaction';
        }
        

        
        // ══ CHAT LLM (Hybrid §6A): jawab pesan yang lolos FAQ/perintah/tanpa
        // nominal (mis. "Kelebihan finwa apa"). Query keuangan yang dikenali
        // pola ("berapa saldo", "ringkasan") & sapaan tetap pakai jalur lama.
        // Gagal → lanjut ke dispatcher bawah.
        $isFinancialQuery = $intent === 'query'
            && $this->financialQueryHandler->isRecognizedFinancialQuery($textLower);
        $needLlmChat = ! $isGroupMessage
            && ! $isFinancialQuery
            && $intent !== 'greeting'
            && ! $hasTransactionKeyword
            && ! $hasDebtKeyword
            && ! $hasAmount;

        if ($needLlmChat && $this->handleLlmChat($messageText)) {
            return;
        }
        // ══ AKHIR CHAT LLM ══

        // Handle based on intent
        if ($intent === 'irrelevant') {
            // Don't reply to non-relevant messages

            return; // Don't send any reply
        } elseif ($intent === 'query') {
            $this->financialQueryHandler->handleQuery($messageText);
        } elseif ($intent === 'greeting') {
            $this->greetingService->handleSpecialIntent('sapa');
        } elseif ($intent === 'unknown') {
            // Punya nominal → hampir pasti transaksi yang frasanya tak dikenali
            // (mis. "Bedak baby 34rb"). Tawarkan konfirmasi catat, bukan menolak.
            if ($hasAmount && ! $isGroupMessage && $this->transactionExtractor->extractAmountFromText($messageText)) {
                $this->sendTransactionConfirmationPrompt($messageText, $contextService);
                return;
            }

            $this->replyService->sendReply(
                "🤔 Maaf, pesan tidak dikenali.\n\n".
                "• _beli kopi 25rb_\n".
                "• _ringkasan bulan ini_\n".
                "• _help_ untuk panduan"
            );
            return;
        } else {
            // Strict guard untuk fallback transaksi (termasuk hutang/piutang)
            if (!$hasTransactionKeyword && !$hasDebtKeyword) {
                if ($hasAmount) {
                    $this->sendTransactionConfirmationPrompt($messageText, $contextService);
                    return;
                }
                Log::info('Fallback/AIProcessor mengklasifikasikan sebagai transaksi, tetapi diblokir oleh Strict Keyword Guard karena tidak ada kata kunci kategori', [
                    'message_id' => $this->message->id,
                    'intent' => $intent,
                    'message' => $messageText
                ]);
                return; // Abaikan pesan, tidak dianggap transaksi
            }

            // Check for ambiguous "transfer" pattern before processing
            $textLower = strtolower($messageText);
            $isAmbiguousTransfer = preg_match('/^transfer\s+\d/i', $messageText) 
                && !preg_match('/transfer\s+(masuk|keluar|ke|dari|terima)/i', $textLower);
            
            if ($isAmbiguousTransfer) {
                // Ask for clarification
                
                // Nominal dari teks (FinWa-AI bisa kosong/timeout → dulu tampil "Rp 0")
                $amount = (int) (\App\Support\AmountParser::extract($messageText) ?: ($finwaEntities['nominal'] ?? 0));
                $formattedAmount = 'Rp ' . number_format($amount, 0, ',', '.');

                $this->clarify('transfer_direction', "🤔 *Transfer {$formattedAmount}* — uang masuk atau keluar?", [
                    '1' => ['label' => '💰 Terima transfer _(pemasukan)_', 'action' => [
                        'type' => 'transaction', 'text' => "terima transfer {$amount}", 'force_type' => 'income', 'force_category_type' => 'pendapatan_transfer',
                    ]],
                    '2' => ['label' => '💸 Kirim transfer _(pengeluaran)_', 'action' => [
                        'type' => 'transaction', 'text' => "kirim transfer {$amount}", 'force_type' => 'expense', 'force_category_type' => 'pengeluaran_transfer',
                    ]],
                    '3' => ['label' => 'Bukan transaksi, abaikan', 'action' => ['type' => 'cancel', 'message' => '👍 Oke, tidak dicatat.']],
                ], ['masuk' => '1', 'terima' => '1', 'pemasukan' => '1', 'keluar' => '2', 'kirim' => '2', 'pengeluaran' => '2', 'batal' => '3', 'bukan' => '3'],
                    "_Balas *1*, *2*, atau *3*._\n💡 Pindah antar dompet sendiri? Ketik mis. *transfer 500rb dari BCA ke Gopay*");
                return;
            }
            
            // Pass FinWa entities if available for faster processing
            $this->transactionService->handleTransaction($messageText, $finwaEntities);

            // Check if handleTransaction detected an ambiguous pattern
            if ($this->transactionService->lastAmbiguousResult !== null) {
                $this->handleAmbiguousTransaction($this->transactionService->lastAmbiguousResult, $contextService);
            }
        }
    }

    protected function sendTransactionConfirmationPrompt(
        string $messageText,
        \App\Services\ConversationContextService $contextService
    ): void {
        $amount = $this->transactionExtractor->extractAmountFromText($messageText);
        $description = $this->transactionExtractor->extractDescriptionFromLine($messageText);

        if (!$amount || $amount <= 0) {
            return;
        }

        // Normalisasi slang sebelum deteksi tipe transaksi
        $normalizedText = \App\Services\KeywordNormalizer::normalize($messageText);

        // Frasa yang jenisnya sudah pasti ("pesanan masuk 300rb", "gajian 5jt") → langsung catat
        if (\App\Services\Transaction\TransactionTypeDetector::detectPhraseType(mb_strtolower($normalizedText)) !== null) {
            $this->transactionService->handleTransaction($messageText, null);

            return;
        }

        $formattedAmount = 'Rp ' . number_format($amount, 0, ',', '.');
        $descDisplay = $description ?: $messageText;
        $textLower = mb_strtolower($normalizedText);

        // Satu aturan dengan pencatatan: TransactionTypeDetector
        $isIncome = app(\App\Services\Transaction\TransactionTypeDetector::class)->detect($normalizedText) === 'income';

        $type = $isIncome ? 'income' : 'expense';

        // Dompet tujuan ditampilkan supaya user tahu uangnya dicatat ke mana.
        // Dompet yang disebut tapi belum ada (mis. "Masuk dana 2jt" tanpa dompet DANA)
        // → dompet utama; tidak pernah membuat dompet baru dari kalimat transaksi.
        $balanceService = app(\App\Services\BalanceService::class);
        $defaultWallet = $balanceService->getDefaultBalance($this->message->tenant_id);
        $mentioned = $this->transactionExtractor->extractAccountNameFromMessage($messageText);
        $targetWallet = $mentioned ? $balanceService->findOrCreateBalance($this->message->tenant_id, $mentioned, null) : null;
        $walletName = ($targetWallet ?? $defaultWallet)?->account_name;
        $walletHint = fn (string $dir) => $walletName ? " — {$dir} *{$walletName}*" : '';

        // "dana" bisa berarti e-wallet DANA ATAU sekadar "uang/dana masuk" → tawarkan dompet utama juga
        $offerDefault = $targetWallet && $defaultWallet && $targetWallet->id !== $defaultWallet->id
            && mb_strtolower((string) $mentioned) === 'dana';

        // Pilihan bernomor: tebakan salah cukup dibalas "2", tidak perlu mengetik ulang
        $guessNo = $isIncome ? '2' : '1';
        $options = [
            '1' => ['label' => '💸 Pengeluaran'.$walletHint('dari').($isIncome ? '' : ' _(tebakan)_'), 'action' => [
                'type' => 'transaction', 'text' => $messageText, 'force_type' => 'expense',
            ]],
            '2' => ['label' => '💰 Pemasukan'.$walletHint('ke').($isIncome ? ' _(tebakan)_' : ''), 'action' => [
                'type' => 'transaction', 'text' => $messageText, 'force_type' => 'income',
            ]],
        ];
        if ($offerDefault) {
            $options['3'] = ['label' => "💰 Pemasukan — ke *{$defaultWallet->account_name}* _(uang masuk biasa, bukan e-wallet DANA)_", 'action' => [
                'type' => 'transaction', 'text' => $messageText, 'force_type' => 'income', 'account_name' => $defaultWallet->account_name,
            ]];
        }
        $cancelNo = (string) (count($options) + 1);
        $options[$cancelNo] = ['label' => 'Bukan transaksi, abaikan', 'action' => ['type' => 'cancel', 'message' => '👍 Oke, tidak dicatat.']];

        $this->clarify('confirm_transaction', "🤔 *Konfirmasi Transaksi*\n\nCatat *{$formattedAmount}* — _{$descDisplay}_ sebagai:", $options, [
            'ya' => $guessNo, 'iya' => $guessNo, 'ok' => $guessNo, 'oke' => $guessNo, 'y' => $guessNo, 'catat' => $guessNo, 'betul' => $guessNo,
            'keluar' => '1', 'pengeluaran' => '1', 'expense' => '1',
            'masuk' => '2', 'pemasukan' => '2', 'income' => '2',
            'bukan' => $cancelNo, 'tidak' => $cancelNo, 'batal' => $cancelNo, 'abaikan' => $cancelNo, 'jangan' => $cancelNo,
        ], '_Balas dengan nomor pilihan (atau *ya* untuk tebakan)._');

        Log::info('Sent transaction confirmation prompt', [
            'message_id' => $this->message->id,
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
            'original' => $messageText,
        ]);
    }

    /**
     * Jawab query hutang/piutang via WhatsApp ("cek hutang", "piutang noki", dst).
     */
    protected function handleDebtQuery(string $messageText): void
    {
        $textLower = mb_strtolower($messageText);
        $askPiutang = str_contains($textLower, 'piutang');
        $askHutang = str_contains($textLower, 'hutang')
            || (str_contains($textLower, 'utang') && ! $askPiutang);

        $summary = $this->debtLedger->summarize($this->message->tenant_id);

        $party = $this->findMentionedParty($textLower, $summary, $askPiutang);
        if ($party !== null) {
            $this->replyService->sendReply($this->renderPartyReply($party));
            return;
        }

        $this->replyService->sendReply($this->renderDebtSummary($summary, $askPiutang, $askHutang));
    }

    /**
     * Jawab pertanyaan/obrolan umum via LLM konfigurasi admin (Hybrid §6A).
     * Dipanggil hanya setelah FAQ, perintah & deteksi transaksi tidak menangkap
     * pesan. Mengembalikan true bila balasan terkirim (baik sukses maupun gagal
     * → memakai balasan fallback sehingga pengguna tidak dibiarkan menunggu).
     */
    /**
     * Aturan pesan GRUP WhatsApp (lihat App\Services\WhatsApp\GroupMessageGate).
     * Pesan sampai di sini sudah lolos gerbang (grup aktif, Premium, pemicu "#",
     * anggota terdaftar). Mengembalikan true bila pesan sudah ditangani.
     *
     *  - Perintah destruktif / pengaturan akun → ditolak di grup.
     *  - Pertanyaan info keuangan (saldo, ringkasan, laporan, hutang …) → semua
     *    balasan dialihkan ke CHAT PRIBADI anggota; grup hanya diberi catatan singkat.
     *  - Pencatatan transaksi → diproses normal, dibalas di grup.
     */
    protected function applyGroupPolicy(string $text): bool
    {
        $metadata = is_array($this->message->metadata)
            ? $this->message->metadata
            : json_decode($this->message->metadata ?? '{}', true);
        if (empty($metadata['group_jid'])) {
            return false;
        }

        $lower = mb_strtolower($text);

        // Perintah yang mengubah/menghapus data akun secara massal, atau mengatur saldo
        if (preg_match('/\b(?:reset|hapus\s+(?:semua|akun|dompet|rekening)|delete\s+all|clear\s+all|mulai\s+dari\s+awal|set\s+saldo|ubah\s+saldo|atur\s+saldo|saldo\s+awal|tambah\s+(?:dompet|rekening)|ganti\s+dompet\s+utama|set\s+budget|set\s+target)\b/u', $lower)) {
            $this->replyService->sendReply(
                "⛔ *Perintah ini tidak bisa dipakai di grup.*\n\n".
                'Untuk keamanan, atur akun, saldo, dompet & budget lewat *chat pribadi* ke nomor FinWa.'
            );

            return true;
        }

        // Info keuangan (tanpa nominal = bukan pencatatan) → jawab di chat pribadi
        $isInfoRequest = preg_match('/\b(?:saldo|dompet|rekening|cashflow|cash\s+flow|arus\s+kas|ringkasan|rekap|laporan|statistik|analisis|export|pdf|riwayat|transaksi|pengeluaran|pemasukan|budget|anggaran|target|tabungan|hutang|utang|piutang|total|berapa)\b/u', $lower)
            && ! \App\Services\WhatsApp\MessageSignals::hasMoneyAmount($lower);
        if ($isInfoRequest) {
            $name = $metadata['author_name'] ?? null;
            $this->replyService->sendReply('📩 '.($name ? "{$name}, " : '').'infonya saya kirim lewat *chat pribadi* ya.');
            $this->replyService->forcePrivate();
        }

        return false;
    }

    /**
     * Jawab pertanyaan edukasi/nasihat keuangan: Chat LLM → jawaban lokal
     * (config finwa_chat.local_answers). False bila keduanya tidak tersedia,
     * sehingga pemanggil memakai jalur lamanya (mis. Panduan).
     */
    protected function answerAdviceQuestion(string $messageText): bool
    {
        if ($this->handleLlmChat($messageText, sendFallbackOnFailure: false)) {
            return true;
        }

        $local = \App\Services\AI\FinWaChatService::localAnswer($messageText);
        if ($local === null) {
            return false;
        }

        Log::info('Chat: jawaban lokal (LLM tidak aktif/gagal)', ['message_id' => $this->message->id]);
        $this->replyService->sendReply($local);

        return true;
    }

    /**
     * Kandidat nama dompet dari hasil regex (grup pertama berisi huruf, bukan nominal)
     * benar-benar dompet? Tanpa kandidat → true (pola tanpa nama dompet).
     */
    protected function walletCandidateIsWallet(array $matches, string $messageText): bool
    {
        foreach (array_slice($matches, 1) as $group) {
            $group = trim((string) $group);
            if ($group === '' || ! preg_match('/\p{L}/u', $group)
                || preg_match('/^[\d\.,]+\s*(?:rb|ribu|rebu|k|jt|juta)?$/iu', $group)) {
                continue;
            }
            // Pola "(ke|di) (.+)" menangkap sisa kalimat — ambil sampai sebelum "dari/untuk/buat"
            $name = trim((string) preg_split('/\s+(?:dari|untuk|buat|utk|beli)\b/iu', $group)[0]);

            return $this->walletCommand->looksLikeWallet($name, $messageText);
        }

        return true;
    }

    /**
     * ID user pemilik nomor WA pengirim (anggota grup → author). Null bila belum ter-mapping.
     */
    protected function resolveSenderUserId(): ?int
    {
        try {
            $numberId = app(\App\Services\WhatsAppUserMappingService::class)
                ->resolveUserWhatsAppNumberId($this->getAttributionSenderId(), $this->message->tenant_id);

            return $numberId
                ? \App\Models\UserWhatsAppNumber::whereKey($numberId)->value('user_id')
                : null;
        } catch (\Throwable $e) {
            Log::warning('resolveSenderUserId gagal', ['message_id' => $this->message->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * User memang meminta panduan/menu aplikasi ("help", "bantuan", "cara pakai
     * finwa", "fitur apa saja") — bukan pertanyaan umum seperti "cara menabung".
     */
    protected function isGroupMessage(): bool
    {
        $meta = is_array($this->message->metadata)
            ? $this->message->metadata
            : (json_decode((string) ($this->message->metadata ?? '{}'), true) ?: []);

        return (bool) ($meta['is_group'] ?? false);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Pertanyaan balik bernomor (Clarification) — Tahap 1 pemahaman ambigu
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Kunci identitas pengirim (LID & nomor HP orang yang sama → sama). Lihat SenderIdentity.
     *
     * @return list<string>
     */
    protected function senderKeys(): array
    {
        return \App\Support\SenderIdentity::keys($this->message, $this->getAttributionSenderId());
    }

    protected function clarify(string $kind, string $title, array $options, array $aliases = [], string $footer = '', array $extra = []): void
    {
        $this->replyService->sendReply(\App\Services\WhatsApp\Clarification::ask(
            $this->message->tenant_id, $this->senderKeys(), $kind, $title, $options, $aliases, $footer, $extra
        ));
    }

    /**
     * Jawaban atas pertanyaan bernomor. true bila pesan adalah jawaban (sudah ditangani).
     * Jawaban lain → pertanyaan dibatalkan & pesan diproses seperti biasa.
     */
    protected function handlePendingClarification(string $reply): bool
    {
        $tenantId = $this->message->tenant_id;
        $sender = $this->senderKeys();
        $pending = \App\Services\WhatsApp\Clarification::pending($tenantId, $sender);
        if ($pending === null) {
            return false;
        }

        $choice = \App\Services\WhatsApp\Clarification::resolve($reply, $pending);

        // Pertanyaan nominal: jawaban berupa angka ("35rb") langsung dipakai
        if ($choice === null && isset($pending['extra']['amount_base'])) {
            $amount = \App\Support\AmountParser::parseAnswer($reply);
            if ($amount && preg_match('/^\s*(?:rp\.?\s*)?[\d.,]+\s*(?:rb|ribu|rebu|k|jt|juta)?\s*$/iu', $reply)) {
                $choice = ['no' => 'amount', 'label' => '', 'action' => [
                    'type' => 'transaction',
                    'text' => $pending['extra']['amount_base'].' '.$amount,
                ]];
            }
        }

        \App\Services\WhatsApp\Clarification::clear($tenantId, $sender);
        if ($choice === null) {
            return false;
        }

        $action = $choice['action'] ?? [];
        switch ($action['type'] ?? null) {
            case 'transaction':
                $options = null;
                if (isset($action['force_type'])) {
                    $options = [
                        'force_type' => $action['force_type'],
                        'force_category_type' => $action['force_category_type']
                            ?? $this->inferCategoryType($action['text'], $action['force_type'] === 'income'),
                        // Dompet pilihan user (mis. "uang masuk biasa → Dompet Utama")
                        'account_name' => $action['account_name'] ?? null,
                        // Hutang/piutang: uangnya bergerak atau hanya dicatat
                        'debt_record_only' => $action['debt_record_only'] ?? null,
                        'counterparty' => $action['counterparty'] ?? null,
                        'amount' => $action['amount'] ?? null,
                    ];
                }
                $this->transactionService->handleTransaction($action['text'], null, $options);
                break;

            case 'wallet_move':
                $from = \App\Models\Balance::where('tenant_id', $tenantId)->find($action['from_id'] ?? 0);
                $to = \App\Models\Balance::where('tenant_id', $tenantId)->find($action['to_id'] ?? 0);
                if ($from && $to) {
                    $this->executeWalletMove($from, $to, (float) $action['amount'], (string) ($action['label'] ?? 'Pindah saldo'));
                } else {
                    $this->replyService->sendReply('⚠️ Dompet tidak ditemukan. Silakan kirim ulang.');
                }
                break;

            case 'wallet_topup_new':
                $to = \App\Models\Balance::where('tenant_id', $tenantId)->find($action['to_id'] ?? 0);
                if ($to) {
                    app(\App\Services\Ledger\TransactionLedger::class)->record([
                        'tenant_id' => $tenantId,
                        'balance_id' => $to->id,
                        'category_id' => \App\Models\Category::where('tenant_id', $tenantId)->where('type', 'kredit_internal')->value('id')
                            ?? \App\Models\Category::where('tenant_id', $tenantId)->where('type', 'pendapatan_lainnya')->value('id'),
                        'message_id' => $this->message->id,
                        'type' => 'kredit_internal',
                        'amount' => (float) $action['amount'],
                        'description' => 'Top up '.$to->account_name,
                        'transaction_date' => now()->toDateString(),
                        'source' => 'wallet_topup',
                    ]);
                    $to->refresh();
                    $this->replyService->sendReply(
                        "🔄 *Top Up Tercatat!* ✅\n\n".
                        '💵 Rp '.number_format((float) $action['amount'], 0, ',', '.')." → *{$to->account_name}*\n".
                        "💳 Saldo {$to->account_name}: Rp ".number_format((float) $to->balance, 0, ',', '.')
                    );
                }
                break;

            case 'cancel':
                $this->replyService->sendReply($action['message'] ?? '👍 Oke, tidak dicatat.');
                break;
        }

        return true;
    }

    protected function inferCategoryType(string $text, bool $isIncome): string
    {
        $inferred = app(\App\Services\Transaction\CategoryInferenceService::class)->infer($text, $isIncome, $this->message->tenant_id);
        $type = is_array($inferred) ? (string) ($inferred['category_type'] ?? '') : '';
        $prefix = $isIncome ? 'pendapatan_' : 'pengeluaran_';

        return str_starts_with($type, $prefix) ? $type : $prefix.'lainnya';
    }

    /**
     * "hutang budi 2jt" / "piutang noki 5jt": tidak jelas apakah uangnya baru
     * bergerak (saldo dompet berubah) atau ini hutang/piutang lama yang hanya
     * dicatat. Kalimat yang jelas ("pinjam dari…", "punya hutang…", paylater)
     * tidak ditanya — lihat DebtCashMode.
     */
    protected function askDebtCashMode(string $messageText, string $textLower): bool
    {
        if (! preg_match('/\b(?:hutang|utang|piutang)\b/u', $textLower)
            // Pelunasan/cicilan/bunga bukan pencatatan hutang baru
            || preg_match('/\b(?:bayar\w*|membayar|cicil\w*|angsur\w*|lunas\w*|melunasi|pelunasan|bunga|denda|terima|dibayar)\b/u', $textLower)
            // "dapat piutang dari Rina 800rb" = piutang dilunasi (uang masuk), bukan piutang baru
            || preg_match('/\b(?:dapat|dapet|nerima)\s+piutang\b/u', $textLower)) {
            return false;
        }

        $amount = \App\Support\AmountParser::extract($messageText);
        if (! $amount) {
            return false;
        }

        // Hanya pernyataan yang benar-benar eksplisit ("hutang lama…", "pinjam dari…") yang
        // langsung dicatat; selebihnya user memilih sendiri 1/2.
        if (\App\Services\DebtReceivable\DebtCashMode::detect($messageText) !== \App\Services\DebtReceivable\DebtCashMode::AMBIGUOUS) {
            return false;
        }

        // Jenisnya dari kata yang ditulis user, bukan tebakan arah: di "hutang Spay Pinjam 3jt"
        // kata "pinjam" adalah nama produk, bukan "Spay meminjam dari saya".
        $isHutang = ! preg_match('/\bpiutang\b/u', $textLower);
        $name = \App\Services\DebtReceivable\CounterpartyExtractor::extract($messageText);
        $name = $name !== null ? trim((string) preg_replace('/^(?:lama|lalu|dulu)\s+/iu', '', $name)) : null;
        $name = $name !== null && $name !== ''
            ? (\App\Services\DebtReceivable\CreditFacility::canonical($name) ?? ucwords(mb_strtolower($name)))
            : null;
        $rp = 'Rp '.number_format($amount, 0, ',', '.');

        $action = [
            'type' => 'transaction',
            'text' => $messageText,
            'amount' => $amount,
            'counterparty' => $name,
            'force_type' => $isHutang ? 'income' : 'expense',
            'force_category_type' => $isHutang ? 'pendapatan_hutang' : 'pengeluaran_piutang',
        ];

        if ($isHutang) {
            $title = '🏦 *Hutang '.$rp.($name ? " ke {$name}" : '').'* — uangnya gimana?';
            $cashLabel = 'Baru saya terima _(uang masuk dompet)_';
            $recordLabel = 'Hutang lama / hanya dicatat _(saldo tidak berubah)_';
        } else {
            $title = '🤝 *Piutang '.$rp.($name ? " dari {$name}" : '').'* — uangnya gimana?';
            $cashLabel = 'Baru saya pinjamkan _(uang keluar dompet)_';
            $recordLabel = 'Piutang lama / hanya dicatat _(saldo tidak berubah)_';
        }

        $this->clarify('debt_cash_mode', $title, [
            '1' => ['label' => $cashLabel, 'action' => $action + ['debt_record_only' => false]],
            '2' => ['label' => $recordLabel, 'action' => $action + ['debt_record_only' => true]],
        ], [
            'baru' => '1', 'masuk' => '1', 'keluar' => '1', 'terima' => '1', 'baru terima' => '1',
            'lama' => '2', 'hanya' => '2', 'hanya dicatat' => '2', 'hutang lama' => '2', 'utang lama' => '2', 'piutang lama' => '2',
        ]);

        return true;
    }

    /**
     * Tiga pengenal kalimat ambigu. true = pertanyaan dikirim / sudah ditangani.
     */
    protected function askIfAmbiguous(string $messageText, string $textLower, bool $looksLikeTransaction): bool
    {
        // Perintah edit/hapus/kueri/pengingat/budget BUKAN transaksi baru — biarkan jalurnya.
        // Dulu "ubah kopi jadi 30" ditanya "maksudnya Rp 30.000?" lalu tercatat sebagai transaksi
        // BARU, dan "ubah tarik tunai jadi 300rb" malah memindahkan saldo.
        if (preg_match('/^(?:ubah|edit|ganti|koreksi|ralat|revisi|hapus|delete|batal|batalkan|batalin|cancel|undo|ingatkan|ingetin|reminder|pengingat|set|atur|budget|anggaran|target|lihat|cek|berapa|total|rekap|ringkasan|laporan|export|kembalikan)\b/u', $textLower)
            || preg_match('/\b(?:harusnya|seharusnya|jadi|menjadi|bukan)\s+(?:rp\.?\s*)?\d/u', $textLower)
            || preg_match('/\?\s*$/u', $textLower)) {
            return false;
        }

        // 1) Pindah uang antar dompet sendiri
        $move = \App\Services\Wallet\InternalMoveDetector::detect($messageText);
        if ($move !== null && $this->handleInternalMove($move, $messageText)) {
            return true;
        }

        // 2) Arah pinjaman tidak jelas: "pinjam budi 100rb"
        if (\App\Services\Transaction\TransactionExtractorService::debtDirectionFromSubject($messageText) === 'ambiguous'
            && ($amount = \App\Support\AmountParser::extract($messageText))) {
            preg_match('/^(?:pinjam|minjem|pinjem)\s+([a-z][a-z\'\-]*(?:\s+(?!\d)[a-z][a-z\'\-]*)?)/iu', trim($messageText), $m);
            $name = ucwords(mb_strtolower(trim($m[1] ?? 'dia')));
            $rp = 'Rp '.number_format($amount, 0, ',', '.');
            $this->clarify('debt_direction', "🤝 *Pinjaman {$rp} dengan {$name}* — siapa yang meminjam?", [
                '1' => ['label' => "Saya pinjam dari {$name} _(uang masuk • hutang saya)_", 'action' => ['type' => 'transaction', 'text' => "pinjam dari {$name} {$amount}"]],
                '2' => ['label' => "{$name} pinjam dari saya _(uang keluar • piutang)_", 'action' => ['type' => 'transaction', 'text' => "kasih pinjam {$name} {$amount}"]],
            ], ['hutang' => '1', 'saya pinjam' => '1', 'masuk' => '1', 'piutang' => '2', 'dia pinjam' => '2', 'keluar' => '2']);

            return true;
        }

        // 2b) Hutang/piutang baru tanpa petunjuk uangnya bergerak atau tidak: "hutang budi 2jt"
        //     → uang baru diterima (masuk dompet) atau hutang lama (hanya dicatat)?
        if ($this->askDebtCashMode($messageText, $textLower)) {
            return true;
        }

        // 3) Nominal tanpa satuan & terlalu kecil: "bensin 50", "jajan 5"
        // Hanya bila angka satu-satunya & di AKHIR pesan ("bensin 50"), bukan "beli 2 kopi"
        if ($looksLikeTransaction && ! \App\Services\WhatsApp\MessageSignals::hasMoneyAmount($textLower)
            && preg_match_all('/\d+/u', $textLower) === 1
            && preg_match('/^(.*\p{L}.*?)\s+(\d{1,3})\s*$/u', trim($messageText), $tail)
            && ! preg_match('/\b(?:tgl|tanggal|jam|pukul|lantai|no|nomor|kamar|ke|x|kali|nomer|rt|rw|blok|tahun|umur|usia|lt|gang|gg)\s*\d+\s*$/u', $textLower)) {
            $small = (int) $tail[2];
            if ($small >= 1 && $small <= 999) {
                $guess = $small * 1000;
                $base = trim($tail[1]);
                $rp = 'Rp '.number_format($guess, 0, ',', '.');
                $cancel = ['label' => 'Bukan, batalkan', 'action' => ['type' => 'cancel']];
                $yes = ['ya' => '1', 'iya' => '1', 'ok' => '1', 'oke' => '1', 'betul' => '1', 'benar' => '1'];

                if ($small < 100) {
                    // "gaji 5" / "hp 3" → bisa ribu atau JUTA: tawarkan keduanya
                    $million = $small * 1_000_000;
                    $rpJt = 'Rp '.number_format($million, 0, ',', '.');
                    $this->clarify('small_amount', "💬 *{$base}* — maksudnya berapa?", [
                        '1' => ['label' => $rp, 'action' => ['type' => 'transaction', 'text' => "{$base} {$guess}"]],
                        '2' => ['label' => $rpJt, 'action' => ['type' => 'transaction', 'text' => "{$base} {$million}"]],
                        '3' => $cancel,
                    ], $yes + ['ribu' => '1', 'rb' => '1', 'juta' => '2', 'jt' => '2', 'bukan' => '3', 'batal' => '3', 'tidak' => '3'],
                        '_Balas *1* atau *2*, atau ketik nominal yang benar (mis. *50rb*)._',
                        ['amount_base' => $base]);
                } else {
                    $this->clarify('small_amount', "💬 *{$base}* — maksudnya *{$rp}*?", [
                        '1' => ['label' => "Ya, {$rp}", 'action' => ['type' => 'transaction', 'text' => "{$base} {$guess}"]],
                        '2' => $cancel,
                    ], $yes + ['bukan' => '2', 'batal' => '2', 'tidak' => '2'],
                        '_Balas *1* / *ya*, atau ketik nominal yang benar (mis. *500rb*)._',
                        ['amount_base' => $base]);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Pindah saldo antar dompet sendiri. Lengkap → langsung dicatat berpasangan;
     * dompet sumber/tujuan tidak jelas → tanya bernomor. false = bukan dompet user (lanjut biasa).
     *
     * @param  array{kind: string, amount: int, from: ?string, to: ?string}  $move
     */
    protected function handleInternalMove(array $move, string $messageText): bool
    {
        $tenantId = $this->message->tenant_id;
        $balanceService = app(\App\Services\BalanceService::class);
        $wallets = \App\Models\Balance::where('tenant_id', $tenantId)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('id')->get();
        $find = fn (?string $name) => $name ? $balanceService->findOrCreateBalance($tenantId, $name, null) : null;
        $cash = $wallets->firstWhere('account_type', 'cash') ?? $wallets->firstWhere('is_default', true);
        $amount = (float) $move['amount'];
        $rp = 'Rp '.number_format($amount, 0, ',', '.');

        [$from, $to] = match ($move['kind']) {
            'withdraw' => [$find($move['from']), $cash],
            'deposit' => [$cash, $find($move['to'])],
            'topup', 'to_wallet' => [$find($move['from']), $find($move['to'])],
            default => [null, null],
        };

        // "tf ke X" hanya pindah saldo bila X memang dompet user; selain itu jalur biasa
        if ($move['kind'] === 'to_wallet' && ! $to) {
            return false;
        }
        // Top up e-wallet yang belum terdaftar → buat dompetnya (nama e-wallet dikenal)
        if ($move['kind'] === 'topup' && ! $to && $move['to']) {
            $to = $balanceService->findOrCreateBalance($tenantId, ucwords($move['to']), 'wallet');
        }

        $label = match ($move['kind']) {
            'withdraw' => 'Tarik tunai',
            'deposit' => 'Setor tunai',
            'topup' => 'Top up',
            default => 'Pindah saldo',
        };

        if ($from && $to && $from->id !== $to->id) {
            $this->executeWalletMove($from, $to, $amount, $label);

            return true;
        }

        // Tanya sisi yang belum diketahui
        $askSource = ! $from || ($to && $from->id === $to->id);
        $known = $askSource ? $to : $from;
        if (! $known) {
            return false;
        }
        $candidates = $wallets->reject(fn ($w) => $w->id === $known->id)->values();
        if ($candidates->isEmpty()) {
            if ($move['kind'] === 'to_wallet') {
                return false; // satu-satunya dompet → jalur biasa (pengeluaran)
            }
            // Dulu jatuh ke jalur lama → "tarik tunai 500rb" tercatat sebagai PEMASUKAN
            $this->replyService->sendReply(
                "ℹ️ *{$label} {$rp}* adalah pindah uang antar dompet — tidak dicatat sebagai pemasukan/pengeluaran.\n\n".
                "Anda baru punya dompet *{$known->account_name}*. Tambahkan dompet lain dulu, contoh:\n".
                "• _tambah dompet BCA_\n".
                '• _tambah dompet Gopay_'
            );

            return true;
        }

        $options = [];
        $no = 1;
        foreach ($candidates->take(5) as $w) {
            $options[(string) $no++] = [
                'label' => ($askSource ? "Dari *{$w->account_name}*" : "Ke *{$w->account_name}*").
                    ' _(saldo Rp '.number_format((float) $w->balance, 0, ',', '.').')_',
                'action' => [
                    'type' => 'wallet_move',
                    'from_id' => $askSource ? $w->id : $known->id,
                    'to_id' => $askSource ? $known->id : $w->id,
                    'amount' => $amount,
                    'label' => $label,
                ],
            ];
        }
        if ($move['kind'] === 'topup') {
            $options[(string) $no++] = ['label' => 'Uang dari luar FinWa _(tambah saldo saja)_', 'action' => ['type' => 'wallet_topup_new', 'to_id' => $to->id, 'amount' => $amount]];
        }
        if ($move['kind'] === 'to_wallet') {
            $options[(string) $no++] = ['label' => 'Bukan pindah dompet — catat sebagai *pengeluaran*', 'action' => ['type' => 'transaction', 'text' => $messageText, 'force_type' => 'expense', 'force_category_type' => 'pengeluaran_transfer']];
        }

        $title = $askSource
            ? "🔄 *{$label} {$rp}* ke *{$known->account_name}*\nUangnya dari dompet mana?"
            : "🔄 *{$label} {$rp}* dari *{$known->account_name}*\nMasuk ke dompet mana?";
        $this->clarify('wallet_move', $title, $options, [], '_Balas dengan nomor. Pindah antar dompet tidak dihitung sebagai pemasukan/pengeluaran._');

        return true;
    }

    protected function executeWalletMove(\App\Models\Balance $from, \App\Models\Balance $to, float $amount, string $label): void
    {
        app(\App\Services\Ledger\TransactionLedger::class)->transfer($from, $to, $amount, [
            'message_id' => $this->message->id,
            'source' => 'wallet_move',
            'debit_description' => "{$label} ke {$to->account_name}",
            'credit_description' => "{$label} dari {$from->account_name}",
        ]);
        $from->refresh();
        $to->refresh();
        $fmt = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

        $this->replyService->sendReply(
            "🔄 *{$label} Tercatat!* ✅\n\n".
            "💵 {$fmt($amount)}\n".
            "📤 {$from->account_name} → 📥 {$to->account_name}\n\n".
            "💳 Saldo {$from->account_name}: {$fmt($from->balance)}\n".
            "💳 Saldo {$to->account_name}: {$fmt($to->balance)}\n\n".
            '_Pindah antar dompet — tidak dihitung sebagai pemasukan/pengeluaran._'
        );
    }

    protected function isExplicitHelpRequest(string $textLower): bool
    {
        $t = trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $textLower));
        $t = preg_replace('/\s+/', ' ', $t);

        if (preg_match('/^(?:help|bantuan|panduan|menu|tolong|info|perintah|command|commands)(?:\s+(?:dong|min|kak|ya|nya))?$/u', $t)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(?:cara\s+(?:pakai|pake|menggunakan|gunakan|kerja)|fitur(?:nya)?|perintah(?:nya)?|panduan|menu(?:nya)?)\b.*\b(?:finwa|aplikasi|app|bot|ini)\b|\b(?:finwa|aplikasi|bot)\b.*\b(?:fitur|cara\s+pakai|panduan|menu|bisa\s+apa)\b/u',
            $t
        );
    }

    protected function handleLlmChat(string $messageText, bool $sendFallbackOnFailure = true): bool
    {
        // Flag chat TERPISAH dari ekstraksi hybrid (lihat HybridLLMConfigService::isChatReady)
        if (! (new \App\Services\HybridLLMConfigService)->isChatReady()) {
            return false;
        }

        // Konteks: beberapa pesan user sebelumnya (per pengirim, 30 menit terakhir)
        $history = (new \App\Services\ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId()))
            ->getRecentUserMessages(1 + (int) config('finwa_chat.history_messages', 2));

        $start = hrtime(true);
        $answer = (new \App\Services\AI\FinWaChatService)->answer($messageText, $history);
        $latency = (int) ((hrtime(true) - $start) / 1_000_000);

        if ($answer === null) {
            Log::warning('Chat LLM: gagal', [
                'message_id' => $this->message->id,
                'latency_ms' => $latency,
                'fallback' => $sendFallbackOnFailure,
            ]);
            if (! $sendFallbackOnFailure) {
                // Pemanggil punya jawaban cadangan sendiri (mis. Panduan FinWa)
                return false;
            }
            $answer = \App\Services\AI\FinWaChatService::fallbackReply();
        } else {
            Log::info('Chat LLM: balasan terkirim', [
                'message_id' => $this->message->id,
                'latency_ms' => $latency,
                'answer_len' => mb_strlen($answer),
            ]);
        }

        $this->replyService->sendReply($answer);

        return true;
    }

    /**
     * Tangani perintah "saldo awal ubah jadi X" dengan membuat
     * transaksi income yang menandai saldo awal, sekaligus menyesuaikan
     * saldo dompet default ke nilai tersebut.
     */
    private function handleOpeningBalance(string $amountText): void
    {
        // Parse nominal & JUMLUHKAN semua bagian:
        // "2 jt" → 2.000.000, "3jt 400 rb" → 3.400.000, "500rb" → 500.000
        $amount = 0;
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(jt|juta|rb|ribu|k|m)?/iu', $amountText, $hits, PREG_SET_ORDER)) {
            foreach ($hits as $hit) {
                $num = (float) str_replace([','], '.', $hit[1]);
                $unit = strtolower($hit[2] ?? '');
                $amount += (int) ($num * match (true) {
                    in_array($unit, ['jt', 'juta', 'm'], true) => 1_000_000,
                    in_array($unit, ['rb', 'ribu', 'k'], true) => 1_000,
                    default => 1,
                });
            }
        }
        if ($amount <= 0) {
            $this->replyService->sendReply("⚠️ Nominal saldo awal tidak terbaca dari \"{$amountText}\".\nContoh: *saldo awal ubah jadi 1 jt*");
            return;
        }

        // Ambil dompet default user
        $defaultBalance = \App\Models\Balance::where('tenant_id', $this->message->tenant_id)
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->first();

        if (! $defaultBalance) {
            $this->replyService->sendReply("⚠️ Belum ada dompet aktif. Buat dompet dulu dengan: *tambah dompet [nama] [saldo awal]*");
            return;
        }

        try {
            // Saldo awal = baris khusus (kredit/debit_internal + kind=opening_balance) sebesar
            // SELISIH ke nilai yang diminta: saldo dompet jadi sesuai, riwayat tetap utuh,
            // dan TIDAK terhitung sebagai pemasukan di laporan.
            app(\App\Services\Ledger\TransactionLedger::class)->setBalanceTo(
                $defaultBalance,
                (float) $amount,
                \App\Models\Transaction::KIND_OPENING_BALANCE,
                ['message_id' => $this->message->id, 'source' => 'opening_balance_command']
            );

            Log::info('Saldo awal berhasil di-set', [
                'tenant_id' => $this->message->tenant_id,
                'balance_id' => $defaultBalance->id,
                'amount' => $amount,
            ]);

            $formatted = 'Rp ' . number_format($amount, 0, ',', '.');
            $this->replyService->sendReply("✅ *Saldo awal berhasil di-set*\n\nDompet: *{$defaultBalance->account_name}*\nSaldo awal: *{$formatted}*\n\nSekarang semua transaksi akan dihitung dari saldo awal ini.");
        } catch (\Throwable $e) {
            Log::error('Gagal set saldo awal', ['error' => $e->getMessage()]);
            $this->replyService->sendReply("⚠️ Gagal menyetel saldo awal: " . $e->getMessage());
        }
    }

    /**
     * @param  array{hutang: array, piutang: array}  $summary
     * @return array{kind: 'hutang'|'piutang', row: array<string, mixed>}|null
     */
    private function findMentionedParty(string $textLower, array $summary, bool $askPiutang): ?array
    {
        $order = $askPiutang ? ['piutang', 'hutang'] : ['hutang', 'piutang'];

        foreach ($order as $kind) {
            foreach ($summary[$kind] as $row) {
                $key = (string) $row['counterparty_normalized'];
                if ($key !== '' && $key !== 'tanpa nama' && str_contains($textLower, $key)) {
                    return ['kind' => $kind, 'row' => $row];
                }
            }
        }

        return null;
    }

    /**
     * @param  array{kind: 'hutang'|'piutang', row: array<string, mixed>}  $party
     */
    private function renderPartyReply(array $party): string
    {
        $row = $party['row'];
        $outstanding = abs((float) $row['outstanding']);
        $label = $row['counterparty'];
        $cur = number_format($outstanding, 0, ',', '.');

        if ($outstanding <= 0.009) {
            return "✅ Tidak ada sisa {$party['kind']} dengan *{$label}* (lunas).";
        }

        if ($party['kind'] === 'hutang') {
            return "🏦 *Hutang ke {$label}*: Rp {$cur}\n\nKamu masih berutang ke {$label} sebesar Rp {$cur}.";
        }

        return "💸 *Piutang dari {$label}*: Rp {$cur}\n\n{$label} masih berutang ke kamu sebesar Rp {$cur}.";
    }

    /**
     * @param  array{hutang: array, piutang: array}  $summary
     */
    private function renderDebtSummary(array $summary, bool $askPiutang, bool $askHutang): string
    {
        $showHutang = $askHutang || ! $askPiutang;
        $showPiutang = $askPiutang || ! $askHutang;

        $lines = [];

        if ($showHutang) {
            $lines[] = '🏦 *Hutang* (kamu berutang):';
            $active = array_values(array_filter($summary['hutang'], static fn ($r) => abs((float) $r['outstanding']) > 0.009));
            if ($active === []) {
                $lines[] = 'Tidak ada hutang aktif.';
            } else {
                foreach ($active as $r) {
                    $lines[] = "• {$r['counterparty']}: Rp ".number_format(abs((float) $r['outstanding']), 0, ',', '.');
                }
            }
            $lines[] = '';
        }

        if ($showPiutang) {
            $lines[] = '💸 *Piutang* (orang berutang ke kamu):';
            $active = array_values(array_filter($summary['piutang'], static fn ($r) => abs((float) $r['outstanding']) > 0.009));
            if ($active === []) {
                $lines[] = 'Tidak ada piutang aktif.';
            } else {
                foreach ($active as $r) {
                    $lines[] = "• {$r['counterparty']}: Rp ".number_format(abs((float) $r['outstanding']), 0, ',', '.');
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function handleAmbiguousTransaction(array $ambiguousResult, \App\Services\ConversationContextService $contextService): void
    {
        $amount = $ambiguousResult['amount'];
        $formattedAmount = 'Rp ' . number_format($amount, 0, ',', '.');
        $description = $ambiguousResult['description'];

        // Resolve human-readable category names
        $incomeCategoryName = $this->getAmbiguousCategoryDisplayName($ambiguousResult['income_category_type']);
        $expenseCategoryName = $this->getAmbiguousCategoryDisplayName($ambiguousResult['expense_category_type']);

        $prompt = "🤔 *Konfirmasi Tipe Transaksi*\n\n" .
            "Pesan: _{$description}_\n" .
            "Jumlah: *{$formattedAmount}*\n\n" .
            "Ketik 1 untuk Pemasukan ({$incomeCategoryName})\n" .
            "Ketik 2 untuk Pengeluaran ({$expenseCategoryName})";

        try {
            $this->replyService->sendReply($prompt);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send ambiguous confirmation prompt', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
            return; // Do NOT store pending if send fails
        }

        $contextService->storePendingConfirmation([
            'original_message' => $description,
            'description' => $description,
            'amount' => $amount,
            'type' => 'ambiguous',
            'income_category_type' => $ambiguousResult['income_category_type'],
            'expense_category_type' => $ambiguousResult['expense_category_type'],
            'transaction_date' => $ambiguousResult['transaction_date'] ?? now()->toDateString(),
            'retry_count' => 0,
        ]);
    }

    /**
     * Get display name for category type (for ambiguous confirmation prompt).
     */
    protected function getAmbiguousCategoryDisplayName(string $categoryType): string
    {
        // Map common category_type to human-readable name
        $map = [
            'pendapatan_gaji' => 'Gaji',
            'pengeluaran_gaji_karyawan' => 'Gaji Karyawan',
            'pendapatan_lainnya' => 'Pendapatan Lain',
            'pengeluaran_lainnya' => 'Pengeluaran Lain',
        ];

        if (isset($map[$categoryType])) {
            return $map[$categoryType];
        }

        // Fallback: humanize the category_type string
        $name = str_replace(['pendapatan_', 'pengeluaran_', '_'], ['', '', ' '], $categoryType);
        return ucwords(trim($name));
    }

    /**
     * Handle user reply to an ambiguous transaction confirmation.
     */
    protected function handleAmbiguousReply(string $reply, array $pending, \App\Services\ConversationContextService $contextService): void
    {
        $normalized = strtolower(trim($reply));

        // Check if this is a NEW transaction message (has amount and not just a confirmation keyword)
        $isNewTransaction = preg_match('/\d+\s*(rb|ribu|k|jt|juta)?/i', $reply)
            && !in_array($normalized, ['1', '2'])
            && !str_contains($normalized, 'pemasukan')
            && !str_contains($normalized, 'pengeluaran')
            && !str_contains($normalized, 'masuk')
            && !str_contains($normalized, 'keluar');

        if ($isNewTransaction) {
            $contextService->clearPendingConfirmation();
            // Re-route as new message
            $this->processTextMessage($reply);
            return;
        }

        // Check for valid income responses
        if ($normalized === '1' || str_contains($normalized, 'pemasukan') || str_contains($normalized, 'masuk')) {
            $this->processAmbiguousAsType($pending, 'income', $contextService);
            return;
        }

        // Check for valid expense responses
        if ($normalized === '2' || str_contains($normalized, 'pengeluaran') || str_contains($normalized, 'keluar')) {
            $this->processAmbiguousAsType($pending, 'expense', $contextService);
            return;
        }

        // Invalid reply
        $retryCount = $pending['retry_count'] ?? 0;
        if ($retryCount >= 1) {
            // Max retries reached — cancel
            $contextService->clearPendingConfirmation();
            $this->replyService->sendReply("❌ Konfirmasi dibatalkan. Silakan kirim ulang transaksi Anda.");
            return;
        }

        // Resend prompt with hint — increment retry
        $pending['retry_count'] = $retryCount + 1;
        $contextService->storePendingConfirmation($pending);

        $this->replyService->sendReply(
            "⚠️ Jawaban tidak dikenali.\n\n" .
            "Balas dengan:\n" .
            "• *1* atau *pemasukan* untuk Pemasukan\n" .
            "• *2* atau *pengeluaran* untuk Pengeluaran"
        );
    }

    /**
     * Process a confirmed ambiguous transaction as the specified type.
     */
    protected function processAmbiguousAsType(array $pending, string $type, \App\Services\ConversationContextService $contextService): void
    {
        $categoryType = $type === 'income'
            ? $pending['income_category_type']
            : $pending['expense_category_type'];

        $contextService->clearPendingConfirmation();

        // Process the transaction with forced type and category
        $this->transactionService->handleTransaction(
            $pending['original_message'],
            null,
            [
                'force_type' => $type,
                'force_category_type' => $categoryType,
                'transaction_date' => $pending['transaction_date'] ?? now()->toDateString(),
            ]
        );
    }
    
}
