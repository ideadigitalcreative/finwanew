<?php

namespace App\Jobs;

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
    protected \App\Services\WhatsApp\IntentDetectionService $intentDetection;
    protected \App\Services\WhatsApp\MessageRouterService $messageRouter;
    protected \App\Services\Savings\SavingsGoalService $savingsGoalService;
    protected \App\Services\WhatsApp\GreetingService $greetingService;
    protected DebtReceivableLedgerService $debtLedger;

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
                $inference = $categoryInference->infer($desc, $inc);
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
            fn($data) => $this->transactionService->createTransaction($data, false)
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

        $this->intentDetection = new \App\Services\WhatsApp\IntentDetectionService;
        $this->messageRouter = new \App\Services\WhatsApp\MessageRouterService($this->commandHandler);
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

            // Check if message is from super admin WhatsApp number
            // Super admin channel is only used for sending notifications, not for processing transactions
            // COMMENTED OUT: This blocks messages FROM the user's own number
            // TODO: Configure super admin number properly if needed
            /*
            $superAdminWhatsAppNumber = '6285242766676';
            
            if ($this->message->channel_account === $superAdminWhatsAppNumber) {

                // Skip all processing for super admin channel - it's only for sending notifications
                return;
            }
            */
            
            $regService = new \App\Services\WhatsApp\RegistrationFlowService($this->message);
            $regResult = $regService->resolve();
            if ($regResult['handled'] && !$regResult['shouldContinue']) {
                return;
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

                    $ocrJob = $this->ocrProcessor->createOcrJob();
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
                        "1ï¸âƒ£ Perpanjang di: ".config('app.url')."/subscriptions\n".
                        "2ï¸âƒ£ Hubungi Admin: 6285242766676\n\n".
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
        
        if ($originalText !== $messageText) {

        }
        
        // CONTEXT MEMORY: Check for follow-up questions and enrich with context
        // IMPORTANT: Check BEFORE saving new context, so getLastContext returns previous message
        $contextService = new \App\Services\ConversationContextService($this->message->tenant_id);
        
        // PRIORITY CHECK: Pending transaction follow-up (user replies with just amount)
        // If user previously sent "naik ojek" and now sends "15rb", combine them
        $msgTrimmed = trim($messageText);
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
                if (preg_match('/(minggu lalu|bulan lalu|kemarin|tadi|yang lalu)/i', $textLower)) {
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
        if ($this->batchTransaction->isBatchTransactionFormat($messageText)) {
            $this->commandHandler->handleBatchTransactions($messageText);
            return;
        }
        
        // FAST PATH: Check for simple financial queries that don't need AI classification
        // This handles queries like "pengeluaran bulan ini", "pemasukan hari ini" without calling external AI
        $queryKeywords = [
            'pengeluaran', 'pemasukan', 'ringkasan', 'saldo', 'cashflow', 'cash flow',
            'total', 'berapa', 'cek', 'lihat', 'daftar',
            // Informal query keywords
            'habis', 'udah habis', 'sudah habis', 'udah keluar', 'sudah keluar',
            'masuk berapa', 'keluar berapa', 'spending', 'income'
        ];
        $periodKeywords = [
            'hari ini', 'minggu ini', 'bulan ini', 'tahun ini', 
            'kemarin', 'bulan lalu', 'minggu lalu',
            // Informal period keywords
            'hr ini', 'hari ni', 'bln ini', 'bln lalu', 'kmrn'
        ];

        
        // Transaction keywords â€” merged from config (single source of truth) + informal slang
        $configKeywords = array_merge(
            array_keys(config('finwa_category_rules.expense_keywords', [])),
            array_keys(config('finwa_category_rules.income_keywords', [])),
            array_keys(config('finwa_category_rules.local_expense_extras', []))
        );

        // Additional informal/slang keywords not in config
        $slangKeywords = [
            'mkn', 'maem', 'mamam', 'nyemil', 'ngemil',
            'nasgor', 'nasgep', 'mie instant',
            'mcd', 'mcdonalds', 'kfc', 'hokben', 'yoshinoya', 'solaria', 'warunk upnormal',
            'starbucks', 'sbux', 'sbx', 'janji jiwa', 'kopi kenangan', 'fore', 'tomoro',
            'mixue', 'chatime', 'gulu gulu', 'xiboba', 'boba',
            'pizza hut', 'phd', 'dominos', 'burger king', 'wendys',
            'jco', 'dunkin', 'krispy kreme',
            'ngopi', 'esteh',
            'jajan', 'borong', 'checkout', 'order', 'pesen',
            'shopee', 'tokped', 'tokopedia', 'lazada', 'bukalapak', 'blibli', 'olshop', 'online shop',
            'alfamart', 'indomaret', 'alfamidi', 'superindo', 'hypermart', 'carrefour', 'giant', 'lotte',
            'ojol', 'ojek online', 'maxim', 'indriver', 'bluebird', 'busway',
            'etoll', 'e-toll',
            'isi bensin', 'ngisi bensin',
            'pln', 'pdam', 'indihome', 'biznet', 'firstmedia',
            'kuota', 'paket data', 'top up', 'topup', 'isi pulsa', 'isi kuota',
            'xxi', 'cgv', 'cinepolis',
            'netflix', 'spotify', 'youtube', 'disney', 'vidio', 'viu', 'wetv', 'iqiyi',
            'steam', 'playstation', 'xbox', 'mobile legend', 'ml', 'ff', 'pubg', 'valorant',
            'gpt', 'chatgpt', 'openai', 'gemini', 'claude', 'copilot', 'cursor',
            'canva', 'figma', 'adobe', 'prem', 'subs',
            'apk', 'app', 'aplikasi', 'software', 'license', 'lisensi',
            'cloud', 'hosting', 'domain', 'vps',
            'tf', 'trf', 'terima duit', 'dapat duit', 'dapet duit',
            'uang masuk', 'duit masuk',
            'ngontrak', 'kredit',
            'ngasih', 'kirimin', 'kirim ke', 'transfer ke',
            'infak', 'amal',
            'apotek', 'rumah sakit', 'rs', 'puskesmas', 'klinik', 'lab', 'cek darah',
            'salon', 'barbershop', 'potong rambut', 'cukur', 'facial', 'spa', 'pijat', 'massage',
            'skincare', 'makeup', 'kosmetik',
            'atk', 'alat tulis', 'bimbel',
            'abis', 'habis', 'kluar', 'spending', 'spent',
            'ngeluarin', 'keluarin', 'abis buat', 'habis buat', 'keluar buat',
            'tadi', 'barusan', 'kmrn', 'semalem',
            'geprek', 'bakwan', 'batagor', 'empek', 'cilok', 'cireng', 'ketoprak',
            'terang bulan', 'roti bakar',
        ];

        $transactionKeywords = array_unique(array_merge($configKeywords, $slangKeywords));

        
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

        $hasQueryKeyword = false;
        $hasPeriodKeyword = false;
        $hasTransactionKeyword = false;
        $hasAmount = preg_match('/\d+\s*(rb|ribu|k|jt|juta)?/i', $textLower);

        foreach ($queryKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {
                $hasQueryKeyword = true;
                break;
            }
        }
        
        foreach ($periodKeywords as $period) {
            if (str_contains($textLower, $period)) {
                $hasPeriodKeyword = true;
                break;
            }
        }
        
        foreach ($transactionKeywords as $txKeyword) {
            if (str_contains($textLower, $txKeyword)) {
                $hasTransactionKeyword = true;
                break;
            }
        }

        // FALLBACK: Standalone action verbs (bayar, beli, uang, dll) yang menandai transaksi
        // meski objek transaksinya tidak ada di daftar keyword (mis. "bayar finwa 50rb").
        // TransactionService sudah menangani verb ini sebagai expense override,
        // jadi gate fast-path harus meloloskannya agar tidak jatuh ke AI/no-response.
        if (! $hasTransactionKeyword) {
            $actionVerbPatterns = [
                '/\bbayar\b/u', '/\bbeli\b/u', '/\bbelanja\b/u', '/\bbayarin\b/u',
                '/\bnabung\b/u', '/\bsetor\b/u', '/\btarik\b/u',
                '/\buang\s+\w+/u', '/\bbiaya\s+\w+/u', '/\bongkos\s+\w+/u',
            ];
            foreach ($actionVerbPatterns as $verbPattern) {
                if (preg_match($verbPattern, $textLower)) {
                    $hasTransactionKeyword = true;
                    break;
                }
            }
        }

        // Keyword hutang/piutang (selaras dengan debt guard di TransactionService).
        // Dipisah dari hasTransactionKeyword agar pesan hutang/piutang TIDAK masuk
        // fast-path lokal (line 1482), tapi tetap di-route ke FinWa-AI / handleTransaction.
        $debtKeywords = ['hutang', 'utang', 'piutang', 'pinjam', 'pinjaman', 'pinjem', 'pijemin',
            'kasih pinjam', 'bayar hutang', 'bayar utang', 'pelunasan', 'lunas', 'dipinjemin',
            'balikin pinjaman', 'pinjamkan'];
        $hasDebtKeyword = false;
        foreach ($debtKeywords as $dk) {
            if (str_contains($textLower, $dk)) {
                $hasDebtKeyword = true;
                break;
            }
        }

        // FAST PATH 1.2: Query hutang/piutang ("cek hutang", "piutang noki", "hutang saya berapa").
        // Tanpa nominal â†’ bukan pencatatan, melainkan ringkasan outstanding.
        if ($hasDebtKeyword && !$hasAmount) {
            $isDebtQuery = $hasQueryKeyword
                || preg_match('/\b(saya|aku|ku|gue|gw|gua|siapa|masih)\b/u', $textLower) === 1
                || preg_match('/\b(hutang|utang|piutang)\s+\S+/u', $textLower) === 1;

            if ($isDebtQuery) {
                $this->handleDebtQuery($messageText);
                return;
            }
        }

        // Check for statistics-related keywords that need FinWa-AI classification
        // These should NOT be handled by fast-path, as they need cek_statistik intent
        $statisticsKeywords = [
            'terbesar', 'tertinggi', 'terendah', 'terkecil', 'paling', 'top',
            'rata-rata', 'average', 'statistik', 'stats', 'analisis', 'analysis',
            'tren', 'trend', 'kategori terbesar', 'spending habit'
        ];
        $hasStatisticsKeyword = false;
        foreach ($statisticsKeywords as $statKeyword) {
            if (str_contains($textLower, $statKeyword)) {
                $hasStatisticsKeyword = true;
                break;
            }
        }
        
        // FAST PATH 1: Query (query keyword + period keyword + NO amount + NO statistics keyword)
        // e.g., "pengeluaran bulan ini", "pemasukan hari ini"
        // But NOT "pengeluaran terbesar bulan ini" - that needs cek_statistik
        // EXCLUDE: "daftar transaksi", "lihat transaksi", "cek transaksi" - these go to handleViewTransactions
        $isViewTransactionRequest = str_contains($textLower, 'daftar transaksi') ||
                                     str_contains($textLower, 'lihat transaksi') ||
                                     str_contains($textLower, 'cek transaksi') ||
                                     str_contains($textLower, 'list transaksi') ||
                                     str_contains($textLower, 'histori transaksi') ||
                                     str_contains($textLower, 'history transaksi') ||
                                     str_contains($textLower, 'riwayat transaksi');
        
        if ($hasQueryKeyword && $hasPeriodKeyword && !$hasAmount && !$hasStatisticsKeyword && !$isViewTransactionRequest) {

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
            '/(?:isi|tambah|top\s*up)\s+uang\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(?:ke|di)\s+(.+)/i',
            // Pattern: tambah uang masuk dari bos 700.000
            '/(?:isi|tambah|top\s*up)\s+uang\s+masuk\s+(?:dari\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // Pattern: tambah transfer masuk 1.400.000 (tidak ada nama dompet, tapi harus ditangkap sebagai pemasukan / transfer masuk)
            // Namun karena method ini ditujukan untuk "To Wallet", kita tangkap jika ada pattern mirip
            '/(?:isi|tambah|top\s*up)\s+transfer\s+masuk\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i'
        ];
        
        foreach ($transferToWalletPatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $this->walletCommand->handleTransferToWallet($messageText);
                return;
            }
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
            '/(?:pengeluaran|keluar(?:an)?)\s+(?:dari\s+)?dompet\s+([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(.+)/i',
            // Pattern 2: "keluar dari dompet [nama] [nominal] [deskripsi]"
            '/keluar\s+dari\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(.+)/i',
            // Pattern 3: "bayar dari [nama] [nominal] [deskripsi]"
            '/bayar\s+dari\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(.+)/i',
            // Pattern 4: "dompet [nama] [nominal] [deskripsi]"
            '/^dompet\s+([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(.+)/i',
            // Pattern 5: "dari [nama] [nominal] untuk [deskripsi]"
            '/dari\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(?:untuk|buat|beli)\s+(.+)/i',
            // Pattern 6: "Pengeluaran dompet [nama] . harga [nominal] . Keterangan [deskripsi]"
            '/(?:pengeluaran|keluar(?:an)?)\s+(?:dari\s+)?dompet\s+([a-zA-Z0-9\s]+?)\s*\.\s*(?:harga|nominal)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s*\.\s*(?:keterangan|ket|desc)?\s*(.+)/i',
            // Pattern 7: "Pengeluaran dompet [nama] . [nominal] . [deskripsi]" (without keywords)
            '/(?:pengeluaran|keluar(?:an)?)\s+(?:dari\s+)?dompet\s+([a-zA-Z0-9\s]+?)\s*\.\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s*\.\s*(.+)/i',
        ];
        
        foreach ($expenseFromWalletPatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {

                $this->walletCommand->handleExpenseFromWallet($messageText);
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
        
        // 1.6ae: Check subscriptions - "cek langganan", "subscription saya"
        $checkSubsKeywords = [
            'cek langganan', 'lihat langganan', 'subscription saya', 'cek subscription',
            'pengeluaran rutin', 'bayaran bulanan', 'tagihan bulanan', 'recurring'
        ];
        foreach ($checkSubsKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

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
            if ($isSpecificDelete && !str_contains($textLower, 'terakhir') && !str_contains($textLower, 'semua') 
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
        
        // 1.6af2.5: Edit last transaction by context - "edit jadi 45rb", "edit terakhir jadi 45rb"
        if ($hasAmount && preg_match('/^(ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?(?:terakhir\s+)?(?:jadi|ke)\b/i', $textLower)) {
            $this->transactionService->handleEditWithContext($messageText);
            return;
        }
        
        // 1.6af3: Edit specific transaction by keyword - "ubah beli kue jadi 25rb", "edit makan siang 30rb"
        // Must have keyword + amount
        if (preg_match('/^(ubah|edit|ganti|koreksi)\s+(?!transaksi\s*$)(?!jadi\s)(?!ke\s)/i', $textLower) && $hasAmount) {
            // Check if pattern matches: "ubah [keyword] [jadi/ke]? [amount]"
            $isSpecificEdit = preg_match('/^(ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?[a-zA-Z].+\d+\s*(rb|ribu|k|jt|juta)?/i', $textLower);
            if ($isSpecificEdit && !str_contains($textLower, 'terakhir')) {
                $this->transactionService->handleEditTransactionByKeyword($messageText);
                return;
            }
        }
        

        // 1.6ag: Context-based Edit/Correction - "salah harusnya 50rb", "koreksi jadi 30rb"
        $editContextKeywords = [
            'salah', 'koreksi', 'harusnya', 'seharusnya', 'ubah jadi', 'ganti jadi',
            'bukan', 'yang bener', 'yang benar', 'ralat',
            'revisi', 'perbaikan'
        ];
        foreach ($editContextKeywords as $keyword) {
            // Use word boundary to avoid matching "peralatan" with "ralat"
            // Lepaskan syarat $hasAmount untuk niat koreksi tegas (mis. "Ganti jadi pemasukan")
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $textLower)) {

                $this->transactionService->handleEditWithContext($messageText);
                return;
            }
        }
        
        // 1.6ag2: Quick Undo/Cancel - "undo", "ga jadi", "batalin", "yang tadi salah"
        // These should delete the last transaction without needing amount
        $undoKeywords = [
            'undo', 'ga jadi', 'gak jadi', 'nggak jadi', 'gajadi',
            'batalin', 'batalkan', 'batal yang tadi', 'batalin yang tadi',
            'yang tadi salah', 'tadi salah', 'salah catat',
            'hapus yang tadi', 'delete yang tadi', 'yang barusan salah'
        ];
        foreach ($undoKeywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $textLower) || $textLower === 'batal' || $textLower === 'undo' || $textLower === 'hapus' || $textLower === 'delete') {
                $this->transactionService->handleDeleteTransaction();
                return;
            }
        }

        
        // 1.6ag3: Category correction by pattern - "air mineral kategori makanan", "beli susu ganti ke kesehatan"
        // Recognizes: [description] [kategori/ganti/ubah/ke] [category name]
        // Does NOT require prefix like "edit/ubah/ganti"
        // EXCEPTION: Skip if the phrase is "uang masuk", "uang keluar", "masuk uang", "keluar uang", "duit masuk", "duit keluar" â€” those are transactions, not corrections!
        if (preg_match('/^(.+?)\s+(?:kategori|ganti(?:in)?|ubah|pindah(?:in)?|masuk(?:in)?)\s+(?:ke\s+|jadi\s+)?(.+)$/i', $textLower, $corrMatches)) {
            $txKeyword = trim($corrMatches[1]);
            $catCandidate = trim($corrMatches[2]);
            // Validate: txKeyword should look like a transaction description (not empty, not just a number)
            // catCandidate should not be just a number
            // AND the phrase is NOT a transaction phrase like "uang masuk ke [dompet]"!
            $isTransactionPhrase = preg_match('/\b(uang|duit)\s+(masuk|keluar)\b/i', $textLower) || preg_match('/\b(masuk|keluar)\s+(uang|duit)\b/i', $textLower);
            if (!$isTransactionPhrase && strlen($txKeyword) >= 2 && !preg_match('/^\d+$/', $txKeyword) && strlen($catCandidate) >= 2 && !preg_match('/^\d+(\s*(rb|ribu|k|jt|juta))?$/i', $catCandidate)) {
                $this->transactionService->handleEditTransactionWithCorrection($txKeyword, $catCandidate);
                return;
            }
        }

        // 1.6ah: Daily Reminder - "aktifkan reminder", "nyalakan reminder"
        $enableReminderKeywords = [
            'aktifkan reminder', 'nyalakan reminder', 'hidupkan reminder',
            'enable reminder', 'on reminder', 'reminder on'
        ];
        foreach ($enableReminderKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->reminderService->handleEnableDailyReminder();
                return;
            }
        }
        
        $disableReminderKeywords = [
            'matikan reminder', 'nonaktifkan reminder', 'disable reminder',
            'off reminder', 'reminder off', 'stop reminder'
        ];
        foreach ($disableReminderKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->reminderService->handleDisableDailyReminder();
                return;
            }
        }
        
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
        
        // FAST PATH 3.1: Specific wallet balance query
        // e.g., "saldo BCA berapa?", "uang di gopay berapa?", "berapa di dana?"
        $specificWalletPatterns = [
            '/(?:saldo|uang|duit)\s+(?:di\s+)?(\w+)\s*(?:berapa|brp)\??/i',  // "saldo BCA berapa?"
            '/berapa\s+(?:saldo\s+)?(?:di\s+)?(\w+)\??/i',                    // "berapa di dana?"
            '/(?:cek|lihat)\s+saldo\s+(\w+)/i',                               // "cek saldo gopay"
        ];
        
        foreach ($specificWalletPatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $walletName = $matches[1] ?? null;
                if ($walletName && strlen($walletName) >= 2) {
                    $this->walletCommand->handleViewSpecificWallet($walletName);
                    return;
                }
            }
        }
        
        // Only process wallet management if NOT a question
        if (!$isQuestionAboutWallet) {
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
            '/^saldo\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?)/i',
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
        
        if ($hasTransactionKeyword && $hasAmount && !($hasQueryKeyword && $hasPeriodKeyword) && !$needsAIForIntent) {
            // Proceed to handleTransaction with null finwaEntities - it will use local extraction
            $this->transactionService->handleTransaction($messageText, null);

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
        
        foreach ($deleteTransactionsKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

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
            if (str_contains($textLower, $resetKeyword)) {

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
                // (mis. "Piutang Noki 20jt" â†’ catat_pengeluaran). Hanya bila ada nominal,
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

                // Koreksi arah "X bayar hutang" â†’ terima piutang (mis. "Rodi bayar hutang 600rb").
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
                if ($finwaIntent === 'sapa' || $finwaIntent === 'help') {
                    $this->greetingService->handleSpecialIntent($finwaIntent);
                    return;
                }
                
                // Handle tanya_finwa - Questions about FinWa app
                if ($finwaIntent === 'tanya_finwa') {
                    // Try to match specific FAQ pattern
                    if (!$this->faqService->checkAndHandleFAQ($messageText)) {
                        // If no specific match, send general help
                        $this->greetingService->handleSpecialIntent('help');
                    }
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

                    // Handle hutang/piutang (empat aliran) â€” route ke handleTransaction.
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
                        } else {
                            $this->transactionService->handleDeleteTransaction();
                        }
                        return;
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
        

        
        // Handle based on intent
        if ($intent === 'irrelevant') {
            // Don't reply to non-relevant messages

            return; // Don't send any reply
        } elseif ($intent === 'query') {
            $this->financialQueryHandler->handleQuery($messageText);
        } elseif ($intent === 'greeting') {
            $this->greetingService->handleSpecialIntent('sapa');
        } elseif ($intent === 'unknown') {
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
                
                $amount = $finwaEntities['nominal'] ?? 0;
                $formattedAmount = 'Rp ' . number_format($amount, 0, ',', '.');
                
                $clarificationMessage = "🤔 *Transfer {$formattedAmount}* - Ambigu (Pemasukan/Pengeluaran?)\n\n" .
                    "Sistem tidak yakin apakah ini uang masuk atau keluar.\n\n" .
                    "Mohon ketik ulang dengan lebih jelas:\n" .
                    "• *\"terima transfer {$formattedAmount}\"* (Pemasukan)\n" .
                    "• *\"kirim transfer {$formattedAmount}\"* (Pengeluaran)\n" .
                    "• *\"transfer ke Budi {$formattedAmount}\"* (Pengeluaran)";
                
                $this->replyService->sendReply($clarificationMessage);
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

        $formattedAmount = 'Rp ' . number_format($amount, 0, ',', '.');
        $descDisplay = $description ?: $messageText;
        $textLower = mb_strtolower($normalizedText);

        // Deteksi apakah transaksi ini condong ke pemasukan atau pengeluaran
        $incomeKeywords = config('finwa_category_rules.income_detection_keywords', []);
        $isIncome = false;

        foreach ($incomeKeywords as $keyword) {
            if (str_starts_with($textLower, $keyword)) {
                $afterKeyword = strlen($keyword);
                if ($afterKeyword >= strlen($textLower)
                    || $textLower[$afterKeyword] === ' '
                    || ctype_digit($textLower[$afterKeyword])) {
                    $isIncome = true;
                    break;
                }
            }
        }

        if (! $isIncome) {
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

            if (! $isExpenseOverride) {
                foreach ($incomeKeywords as $keyword) {
                    if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $textLower)) {
                        $isIncome = true;
                        break;
                    }
                }
            }
        }

        $type = $isIncome ? 'income' : 'expense';
        $typeLabel = $isIncome ? 'Pemasukan' : 'Pengeluaran';
        $typeEmoji = $isIncome ? '💰' : '💸';
        $exampleText = $isIncome
            ? "• _dapat {$descDisplay} {$formattedAmount}_"
            : "• _beli {$descDisplay} {$formattedAmount}_";

        $contextService->storePendingConfirmation([
            'original_message' => $messageText,
            'description' => $descDisplay,
            'amount' => $amount,
            'type' => $type,
        ]);

        $this->replyService->sendReply(
            "🤔 *Konfirmasi Transaksi*\n\n" .
            "Sepertinya Anda ingin mencatat:\n" .
            "{$typeEmoji} *{$typeLabel}* {$formattedAmount}\n" .
            "📝 _{$descDisplay}_\n\n" .
            "Balas *YA* untuk mencatat transaksi ini.\n" .
            "Atau kirim ulang dengan format yang lebih jelas, contoh:\n" .
            $exampleText
        );

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
            // Max retries reached â€” cancel
            $contextService->clearPendingConfirmation();
            $this->replyService->sendReply("❌ Konfirmasi dibatalkan. Silakan kirim ulang transaksi Anda.");
            return;
        }

        // Resend prompt with hint â€” increment retry
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
