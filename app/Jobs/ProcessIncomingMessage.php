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
use App\Services\Budget\BudgetCommandService;
use App\Services\Account\AccountCommandService;
use App\Services\Analysis\AnalysisCommandService;
use App\Services\Query\FinancialQueryHandler;
use App\Services\STT\SttProcessorService;

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
    protected BudgetCommandService $budgetCommandService;
    protected AccountCommandService $accountCommandService;
    protected AnalysisCommandService $analysisCommandService;
    protected FinancialQueryHandler $financialQueryHandler;
    protected SttProcessorService $sttProcessor;
    protected \App\Services\WhatsApp\CommandHandlerService $commandHandler;
    protected \App\Services\WhatsApp\IntentDetectionService $intentDetection;
    protected \App\Services\WhatsApp\MessageRouterService $messageRouter;
    protected \App\Services\Savings\SavingsGoalService $savingsGoalService;
    protected \App\Services\WhatsApp\GreetingService $greetingService;

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
            fn($desc, $inc) => $this->categoryMapping->determineCategoryFromText($desc, $inc),
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
        $this->budgetCommandService = new BudgetCommandService($this->message, $sendReply);
        $this->accountCommandService = new AccountCommandService($this->message, $sendReply);
        $this->analysisCommandService = new AnalysisCommandService($this->message, $sendReply);
        
        $this->financialQueryHandler = new FinancialQueryHandler(
            $this->message, 
            $sendReply,
            fn() => $this->walletCommand->handleCheckBalance()
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
            Log::debug('ProcessIncomingMessage: start', [
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
            Log::error('Error processing incoming message', [
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
            Log::debug('ProcessIncomingMessage: finish', [
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

        Log::error('ProcessIncomingMessage: failed', [
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

    /**
     * Process text message - classify intent and handle accordingly
     * 
     * @param string|null $overrideText Optional text to process (used for STT transcribed text)
     */
    protected function processTextMessage(?string $overrideText = null): void
    {
        // Use override text if provided (from STT), otherwise use message content
        $messageText = $overrideText ?? $this->message->content ?? '';
        
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
        $isOnlyAmount = preg_match('/^(Rp\s?)?\d+([.,]\d+)?\s*(rb|ribu|k|jt|juta|m)?$/i', $msgTrimmed);
        
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

        
        // Transaction keywords for fast-path detection (including informal/slang Indonesian)
        $transactionKeywords = [
            // Makanan - formal & informal
            'makan', 'makan pagi', 'makan siang', 'makan malam', 'sarapan', 'breakfast', 'lunch', 'dinner',
            'mkn', 'maem', 'mamam', 'nyemil', 'ngemil', 'cemilan', 'snack',
            'warteg', 'warung', 'resto', 'restoran', 'cafe', 'kafe', 'kantin', 'foodcourt',
            'geprek', 'gorengan', 'bakwan', 'siomay', 'batagor', 'pempek', 'empek', 'cilok', 'cireng',
            'soto', 'rawon', 'rendang', 'gudeg', 'pecel', 'gado', 'ketoprak', 'bubur', 'lontong',
            'martabak', 'terang bulan', 'roti', 'donat', 'kue', 'roti bakar',
            'indomie', 'mie ayam', 'mie goreng', 'nasgor', 'nasi goreng', 'nasgep', 'mie instant',
            
            // Restoran/brand
            'mcd', 'mcdonalds', 'kfc', 'aw', 'hokben', 'yoshinoya', 'solaria', 'warunk upnormal',
            'starbucks', 'sbux', 'sbx', 'janji jiwa', 'kopi kenangan', 'fore', 'tomoro',
            'mixue', 'chatime', 'gulu gulu', 'xiboba', 'boba',
            'pizza hut', 'phd', 'dominos', 'burger king', 'wendys',
            'jco', 'dunkin', 'krispy kreme',
            
            // Minuman
            'ngopi', 'kopi', 'coffee', 'teh', 'es teh', 'esteh', 'es jeruk', 'jus', 'juice', 'susu', 'minum',
            
            // Belanja - formal & informal
            'beli', 'bayar', 'belanja', 'jajan', 'borong', 'checkout', 'order', 'pesen', 'pesan',
            'shopee', 'tokped', 'tokopedia', 'lazada', 'bukalapak', 'blibli', 'olshop', 'online shop',
            'alfamart', 'indomaret', 'alfamidi', 'superindo', 'hypermart', 'carrefour', 'giant', 'lotte',
            
            // Transport - formal & informal
            'bensin', 'pertamax', 'pertalite', 'solar', 'isi bensin', 'ngisi bensin',
            'parkir', 'transport', 'transportasi', 'ongkos', 'ongkir', 'kirim', 'pengiriman',
            'grab', 'gojek', 'ojol', 'ojek', 'ojek online', 'maxim', 'indriver',
            'taxi', 'taksi', 'bluebird', 'angkot', 'busway', 'transjakarta', 'mrt', 'lrt', 'krl', 'kereta',
            'toll', 'tol', 'etoll', 'e-toll', 'tiket', 'pesawat', 'bus', 'travel',
            
            // Tagihan
            'listrik', 'pln', 'air', 'pdam', 'internet', 'wifi', 'indihome', 'biznet', 'firstmedia',
            'pulsa', 'kuota', 'paket data', 'top up', 'topup', 'isi pulsa', 'isi kuota',
            'bpjs', 'asuransi', 'pajak', 'pbb', 'stnk', 'sim',
            
            // Hiburan
            'nonton', 'bioskop', 'cinema', 'xxi', 'cgv', 'cinepolis',
            'netflix', 'spotify', 'youtube', 'disney', 'vidio', 'viu', 'wetv', 'iqiyi',
            'game', 'steam', 'playstation', 'ps', 'xbox', 'mobile legend', 'ml', 'ff', 'pubg', 'valorant',
            
            // Digital products & subscriptions
            'gpt', 'chatgpt', 'openai', 'gemini', 'claude', 'api', 'copilot', 'cursor',
            'canva', 'figma', 'adobe', 'premium', 'prem', 'langganan', 'subscription', 'subs',
            'apk', 'app', 'aplikasi', 'software', 'license', 'lisensi',
            'cloud', 'hosting', 'domain', 'server', 'vps',
            
            // Pendapatan - formal & informal
            'gaji', 'bonus', 'terima', 'transfer', 'tf', 'trf', 'terima duit', 'dapat duit', 'dapet duit',
            'honor', 'upah', 'THR', 'komisi', 'fee', 'bayaran', 'penghasilan', 'income',
            'uang masuk', 'duit masuk', 'masuk',
            
            // Hunian & Cicilan
            'sewa', 'kos', 'kost', 'kontrakan', 'kontrak', 'ngontrak', 'cicilan', 'kredit', 'angsuran',
            
            // Keluarga & Sosial
            'ngasih', 'kasih', 'kirimin', 'kirim ke', 'transfer ke', 'buat', 'untuk', 'ortu', 'orang tua',
            'sedekah', 'infaq', 'infak', 'zakat', 'sumbangan', 'donasi', 'amal',
            
            // Kesehatan & Kecantikan
            'obat', 'apotek', 'dokter', 'rumah sakit', 'rs', 'puskesmas', 'klinik', 'lab', 'cek darah',
            'salon', 'barbershop', 'potong rambut', 'cukur', 'facial', 'spa', 'pijat', 'massage',
            'skincare', 'makeup', 'kosmetik', 'parfum',
            
            // Pendidikan
            'spp', 'uang sekolah', 'uang kuliah', 'buku', 'atk', 'alat tulis', 'kursus', 'les', 'bimbel',
            
            // Keyword informal umum
            'abis', 'habis', 'keluar', 'kluar', 'spending', 'spent', 'uang', 'duit',
            
            // Slang expense keywords
            'ngeluarin', 'keluarin', 'abis buat', 'habis buat', 'keluar buat',
            
            // Time-based (indicates transaction)
            'tadi', 'barusan', 'kemarin', 'kmrn', 'semalem'
        ];

        
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
            // Pattern: Tambah saldo dana 5jt (Top Up)
            '/(?:isi|tambah|top\s*up)\s+saldo\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // Pattern: Tambah uang ke Jago Hadi 600rb
            '/(?:isi|tambah|top\s*up)\s+uang\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // Pattern: Tambah uang 600rb ke Jago Hadi
            '/(?:isi|tambah|top\s*up)\s+uang\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(?:ke|di)\s+(.+)/i'
        ];
        
        foreach ($transferToWalletPatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $this->walletCommand->handleTransferToWallet($messageText);
                return;
            }
        }

        // FAST PATH 1.92: Transfer between wallets (internal transfer)
        // e.g., "transfer saldo Jago Hadi ke BRI 300rb", "transfer ke Jago 200rb dari BCA Hadi"
        $transferBetweenWalletPatterns = [
            // "transfer 100rb dari BCA ke Mandiri"
            '/(?:trans[pf]er|tf|trf|pindah(?:kan)?|kirim)\s+(?:dana|saldo|uang\s+)?[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+(?:dari\s+)?[a-zA-Z0-9\s]+?\s+ke\s+[a-zA-Z0-9\s]+/i',
            // "transfer dari BCA ke Mandiri 100rb"
            '/(?:trans[pf]er|tf|trf|pindah(?:kan)?|kirim)\s+(?:dana|saldo|uang\s+)?(?:dari\s+)?[a-zA-Z0-9\s]+?\s+ke\s+[a-zA-Z0-9\s]+?\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?/i',
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

                $this->budgetCommandService->handleCheckBudget();
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
            'bukan', 'yang bener', 'yang benar', 'ralat'
        ];
        foreach ($editContextKeywords as $keyword) {
            // Use word boundary to avoid matching "peralatan" with "ralat"
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $textLower) && $hasAmount) {

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

        
        // 1.6ah: Daily Reminder - "aktifkan reminder", "matikan reminder"
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

                $this->budgetCommandService->handleDeleteBudget($messageText);
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

                $this->budgetCommandService->handleAddBudget($messageText, null);
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

                    $this->budgetCommandService->handleSetBudget($messageText, null);
                    return;
                }
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
            return;
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
            '/(?:set|atur|ubah|edit|ganti)\s+saldo\s+([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            '/saldo\s+([a-zA-Z0-9\s]+?)\s*=\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            '/(?:update|koreksi)\s+saldo\s+([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            '/saldo\s+([a-zA-Z0-9\s]+?)\s+(?:sekarang|jadi|menjadi)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
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
                $txKeywords = ['beli', 'bayar', 'naik', 'jajan', 'makan', 'minum', 'ngopi', 'isi', 'topup', 'transfer', 'ongkos', 'parkir', 'tol', 'ojek', 'grab', 'gojek'];
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
                        $this->transactionService->handleTransaction($messageText, $finwaEntities);
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
                    
                    // Handle cek saldo
                    if ($finwaIntent === 'cek_saldo') {
                        $this->walletCommand->handleCheckBalance();
                        return;
                    }
                    
                    // Handle cek budget
                    if ($finwaIntent === 'cek_budget') {
                        $this->budgetCommandService->handleCheckBudget();
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
                        $this->budgetCommandService->handleSetBudget($messageText, $finwaEntities);
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
        } else {
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
        }
    }
    
}
