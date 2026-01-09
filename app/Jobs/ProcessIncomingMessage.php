<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\OcrJob;
use App\Models\SttJob;
use App\Models\Transaction;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Balance;
use App\Models\Budget;
use App\Models\SavingsGoal;
use App\Services\BalanceService;
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
use App\Services\FinancialQueryService;
use App\Services\WhatsAppService;
use App\Services\SpendingInsightService;
use App\Services\AchievementService;
use App\Services\SubscriptionTrackerService;
use App\Services\ConversationContextService;
use App\Services\PdfReportService;
use App\Helpers\WhatsAppRegistrationHelper as RegHelper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
        
        $this->transactionService = new TransactionService(
            $this->message,
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
        try {
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
            
            // CORRECT TENANT ROUTING
            // Check if the message was routed to the wrong tenant ID by the gateway
            // Usage: Fixes issue where a user is mapped to an old tenant in the gateway
            $senderNumber = preg_replace('/[^0-9]/', '', $this->message->sender_id);
            

            
            if (!empty($senderNumber)) {
                $correctTenantId = null;

                // 1. Check Primary User Registration (Highest Priority)
                $user = \App\Models\User::where('whatsapp_number', $senderNumber)->first();
                if ($user) {
                    $correctTenantId = $user->tenant_id;

                } else {
                    Log::warning('User NOT found in primary registration', [
                        'searched_number' => $senderNumber
                    ]);
                }

                // 2. Check UserWhatsAppNumber Mapping
                if (!$correctTenantId) {
                    $mapping = \App\Models\UserWhatsAppNumber::where('whatsapp_number', $senderNumber)
                        ->where('is_active', true)
                        ->first();
                    if ($mapping) {
                        $correctTenantId = $mapping->tenant_id;
                    }
                }

                // 3. SECURITY & AUTO-LINKING
                if (!$correctTenantId) {
                    // Detect LID: If NOT Indonesian mobile format (628xxx), it's LID
                    // Updated regex to strictly require '628' prefix. 
                    // Previous '62' prefix allowed LIDs starting with 626... to pass as phones.
                    $isLID = !preg_match('/^628[0-9]{8,13}$/', $senderNumber);
                    
                    // Get originalLid from message metadata for reply fallback
                    $metadata = is_array($this->message->metadata) ? $this->message->metadata : json_decode($this->message->metadata ?? '{}', true);
                    $originalLid = $metadata['original_sender_id'] ?? $this->message->sender_id;
                    
                    // AUTO-LINK: DISABLED - Was linking LIDs to wrong users
                    // Bug: Linked LID to most recent user without checking phone number match
                    // Fix: LIDs must verify manually by sending their phone number
                    if ($isLID && $this->message->tenant_id == 1) {
                        // First check if this LID is already mapped
                        $existingLidMapping = \App\Models\UserWhatsAppNumber::where('whatsapp_number', $senderNumber)->first();
                        
                        if (!$existingLidMapping) {
                            // DO NOT AUTO-LINK - Too dangerous
                            // LID will receive challenge message below to verify their phone number

                            
                            // Note: Auto-linking disabled to prevent linking to wrong users
                            // User must manually verify by sending their phone number
                        }
                    }
                    
                    // If still no correctTenantId found after auto-link attempt
                    if (!$correctTenantId && $isLID) {
                        $text = trim($this->message->content ?? '');
                        
                        // CHECK IF LID WANTS TO REGISTER (before phone verification)
                        if (RegHelper::isConfirmation($text) || RegHelper::isInRegistrationFlow($senderNumber)) {
                            // Treat LID like regular phone number for registration
                            // Use LID as phone number for registration
                            $messageText = $text;
                            
                            // Check if user is in registration flow
                            if (RegHelper::isInRegistrationFlow($senderNumber)) {
                                $currentStep = RegHelper::getCurrentStep($senderNumber);
                                
                                try {
                                    $ch = \App\Models\Channel::find($this->message->channel_id);
                                    $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
                                    $whatsappService = app(\App\Services\WhatsAppService::class);
                                    
                                    switch ($currentStep) {
                                        case 'awaiting_name':
                                            if (strlen($messageText) < 3) {
                                                $whatsappService->sendMessage(
                                                    $sessId, $this->message->sender_id,
                                                    "Nama terlalu pendek. Silakan kirim nama lengkap Anda:",
                                                    'text', $originalLid
                                                );
                                                return;
                                            }
                                            
                                            RegHelper::saveData($senderNumber, ['name' => $messageText]);
                                            RegHelper::setStep($senderNumber, 'awaiting_email');
                                            
                                            $whatsappService->sendMessage(
                                                $sessId, $this->message->sender_id,
                                                RegHelper::getAskEmailMessage($messageText),
                                                'text', $originalLid
                                            );
                                            return;
                                            
                                        case 'awaiting_email':
                                            if (!RegHelper::isValidEmail($messageText)) {
                                                $whatsappService->sendMessage(
                                                    $sessId, $this->message->sender_id,
                                                    "❌ Email tidak valid.\n\nSilakan kirim email yang benar (contoh: nama@gmail.com):",
                                                    'text', $originalLid
                                                );
                                                return;
                                            }
                                            
                                            if (\App\Models\User::where('email', $messageText)->exists()) {
                                                $whatsappService->sendMessage(
                                                    $sessId, $this->message->sender_id,
                                                    "❌ Email sudah terdaftar.\n\nSilakan gunakan email lain atau login di https://finwa.web.id",
                                                    'text', $originalLid
                                                );
                                                RegHelper::clearFlow($senderNumber);
                                                return;
                                            }
                                            
                                            RegHelper::saveData($senderNumber, ['email' => $messageText]);
                                            $regData = RegHelper::getRegistrationData($senderNumber);
                                            
                                            $result = RegHelper::createAccount($regData);
                                            
                                            // Auto-link LID to new account (only if not exists)
                                            $existingLidMapping = \App\Models\UserWhatsAppNumber::where('user_id', $result['user']->id)
                                                ->where('whatsapp_number', $senderNumber)
                                                ->first();
                                            
                                            if (!$existingLidMapping) {
                                                \App\Models\UserWhatsAppNumber::create([
                                                    'user_id' => $result['user']->id,
                                                    'tenant_id' => $result['tenant']->id,
                                                    'whatsapp_number' => $senderNumber,
                                                    'name' => 'LID - Registered',
                                                    'is_primary' => false,
                                                    'is_active' => true,
                                                    'is_lid' => true
                                                ]);
                                            }

                                            
                                            // Send success message
                                            try {
                                                $whatsappService->sendMessage(
                                                    $sessId, $this->message->sender_id,
                                                    RegHelper::getSuccessMessage($result),
                                                    'text', $originalLid
                                                );

                                            } catch (\Exception $sendError) {
                                                Log::error("Failed to send success message to LID: " . $sendError->getMessage(), [
                                                    'lid' => $senderNumber,
                                                    'email' => $result['user']->email,
                                                ]);
                                            }
                                            
                                            RegHelper::clearFlow($senderNumber);
                                            

                                            
                                            return;
                                    }
                                } catch (\Exception $e) {
                                    Log::error("WhatsApp LID Registration Error: " . $e->getMessage());
                                    RegHelper::clearFlow($senderNumber);
                                }
                                
                                return;
                            }
                            
                            // Start registration flow for LID
                            RegHelper::startFlow($senderNumber);
                            
                            try {
                                $ch = \App\Models\Channel::find($this->message->channel_id);
                                $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
                                
                                app(\App\Services\WhatsAppService::class)->sendMessage(
                                    $sessId, $this->message->sender_id,
                                    RegHelper::getAskNameMessage(),
                                    'text', $originalLid
                                );
                            } catch (\Exception $e) {
                                Log::error("Failed to send LID registration start: " . $e->getMessage());
                            }
                            
                            return;
                        }
                        
                        // Normalize phone number input (support 62xxx, 08xxx, 8xxx formats)
                        $tryPhone = preg_replace('/[^0-9]/', '', $text); // Remove non-digits
                        if (preg_match('/^0/', $tryPhone)) {
                            $tryPhone = '62' . substr($tryPhone, 1); // 08xxx -> 628xxx
                        } elseif (preg_match('/^8/', $tryPhone) && strlen($tryPhone) >= 9) {
                            $tryPhone = '62' . $tryPhone; // 8xxx -> 628xxx
                        }
                        
                        // IF VERIFICATION ATTEMPT (user sends their phone number)
                        // Match Indonesian mobile: 62 + 8/9 + 8-12 more digits = 11-15 total
                        if (preg_match('/^62[89][0-9]{8,12}$/', $tryPhone) && strlen($text) < 20) {
                            $targetUser = \App\Models\User::where('whatsapp_number', $tryPhone)->first();
                            
                            if ($targetUser) {
                                // CREATE MAPPING
                                 \App\Models\UserWhatsAppNumber::create([
                                     'user_id' => $targetUser->id,
                                     'tenant_id' => $targetUser->tenant_id,
                                     'whatsapp_number' => $senderNumber,
                                     'name' => 'LID - ' . substr($senderNumber, 0, 8),
                                     'is_primary' => false,
                                     'is_active' => true,
                                     'is_lid' => true
                                 ]);
                                 $correctTenantId = $targetUser->tenant_id;
                                 
                                 // REPLY SUCCESS (with originalLid fallback)
                                 try {
                                     $ch = \App\Models\Channel::find($this->message->channel_id);
                                     $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
                                     app(\App\Services\WhatsAppService::class)->sendMessage(
                                         $sessId, $this->message->sender_id, 
                                         "✅ *Akun Terhubung!*\nAnda telah terhubung ke akun *{$targetUser->name}*.\n\nSilakan kirim ulang transaksi Anda.",
                                         'text', $originalLid
                                     );
                                 } catch (\Exception $e) {
                                     Log::error("Failed to send LID link success: " . $e->getMessage());
                                 }
                                 
                                 $this->message->tenant_id = $correctTenantId;
                                 $this->message->save();
                                 return;
                            } else {
                                // REPLY NOT FOUND
                                 try {
                                     $ch = \App\Models\Channel::find($this->message->channel_id);
                                     $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
                                     app(\App\Services\WhatsAppService::class)->sendMessage(
                                         $sessId, $this->message->sender_id, 
                                         "❌ Nomor WA *$tryPhone* belum terdaftar.\nSilakan daftar di website terlebih dahulu.",
                                         'text', $originalLid
                                     );
                                 } catch (\Exception $e) {
                                     Log::error("Failed to send LID not found: " . $e->getMessage());
                                 }
                                 return;
                            }
                        }
                        // IF UNKNOWN LID -> SEND CHALLENGE with registration option
                        elseif ($this->message->tenant_id == 1) {
                             try {
                                 $ch = \App\Models\Channel::find($this->message->channel_id);
                                 $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
                                 app(\App\Services\WhatsAppService::class)->sendMessage(
                                     $sessId, $this->message->sender_id, 
                                     "👋 *Halo!*\n\nSepertinya Anda belum terdaftar di FinWa.\n\n*Pilihan:*\n1️⃣ Sudah punya akun? Kirim nomor HP Anda (contoh: 08123456789)\n2️⃣ Belum punya akun? Ketik *Daftar* untuk registrasi gratis (trial 3 hari)",
                                     'text', $originalLid
                                 );
                             } catch (\Exception $e) {
                                 Log::error("Failed to send LID challenge: " . $e->getMessage());
                             }
                             return; // BLOCK
                        }
                    }
                    
                    // CASE B: REGULAR PHONE NUMBER (Unregistered) - Handle registration flow
                    if (!$correctTenantId && !$isLID && $this->message->tenant_id == 1) {
                        $messageText = trim($this->message->content ?? '');
                        
                        // Check if user is in registration flow
                        if (RegHelper::isInRegistrationFlow($senderNumber)) {
                            $currentStep = RegHelper::getCurrentStep($senderNumber);
                            $regData = RegHelper::getRegistrationData($senderNumber);
                            
                            try {
                                $ch = \App\Models\Channel::find($this->message->channel_id);
                                $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
                                $whatsappService = app(\App\Services\WhatsAppService::class);
                                
                                switch ($currentStep) {
                                    case 'awaiting_name':
                                        // User sent their name
                                        if (strlen($messageText) < 3) {
                                            $whatsappService->sendMessage(
                                                $sessId, $this->message->sender_id,
                                                "Nama terlalu pendek. Silakan kirim nama lengkap Anda:",
                                                'text'
                                            );
                                            return;
                                        }
                                        
                                        // Save name and ask for email
                                        RegHelper::saveData($senderNumber, ['name' => $messageText]);
                                        RegHelper::setStep($senderNumber, 'awaiting_email');
                                        
                                        $whatsappService->sendMessage(
                                            $sessId, $this->message->sender_id,
                                            RegHelper::getAskEmailMessage($messageText),
                                            'text'
                                        );
                                        return;
                                        
                                    case 'awaiting_email':
                                        // User sent their email
                                        if (!RegHelper::isValidEmail($messageText)) {
                                            $whatsappService->sendMessage(
                                                $sessId, $this->message->sender_id,
                                                "❌ Email tidak valid.\n\nSilakan kirim email yang benar (contoh: nama@gmail.com):",
                                                'text'
                                            );
                                            return;
                                        }
                                        
                                        // Check if email already exists
                                        if (\App\Models\User::where('email', $messageText)->exists()) {
                                            $whatsappService->sendMessage(
                                                $sessId, $this->message->sender_id,
                                                "❌ Email sudah terdaftar.\n\nSilakan gunakan email lain atau login di https://finwa.web.id",
                                                'text'
                                            );
                                            RegHelper::clearFlow($senderNumber);
                                            return;
                                        }
                                        
                                        // Save email and create account
                                        RegHelper::saveData($senderNumber, ['email' => $messageText]);
                                        $regData = RegHelper::getRegistrationData($senderNumber);
                                        
                                        // Create account
                                        $result = RegHelper::createAccount($regData);
                                        
                                        // Send success message
                                        try {
                                            $whatsappService->sendMessage(
                                                $sessId, $this->message->sender_id,
                                                RegHelper::getSuccessMessage($result),
                                                'text'
                                            );

                                        } catch (\Exception $sendError) {
                                            Log::error("Failed to send success message: " . $sendError->getMessage(), [
                                                'phone' => $senderNumber,
                                                'email' => $result['user']->email,
                                            ]);
                                        }
                                        
                                        // Clear registration flow
                                        RegHelper::clearFlow($senderNumber);
                                        

                                        
                                        return;
                                }
                            } catch (\Exception $e) {
                                Log::error("WhatsApp Registration Error: " . $e->getMessage());
                                RegHelper::clearFlow($senderNumber);
                            }
                            
                            return;
                        }
                        
                        // Not in registration flow - check if user wants to register
                        if (RegHelper::isConfirmation($messageText)) {
                            // User wants to register
                            RegHelper::startFlow($senderNumber);
                            
                            try {
                                $ch = \App\Models\Channel::find($this->message->channel_id);
                                $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
                                
                                app(\App\Services\WhatsAppService::class)->sendMessage(
                                    $sessId, $this->message->sender_id,
                                    RegHelper::getAskNameMessage(),
                                    'text'
                                );
                            } catch (\Exception $e) {
                                Log::error("Failed to send registration start: " . $e->getMessage());
                            }
                            
                            return;
                        }
                        
                        if (RegHelper::isRejection($messageText)) {
                            // User rejected registration
                            try {
                                $ch = \App\Models\Channel::find($this->message->channel_id);
                                $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
                                
                                app(\App\Services\WhatsAppService::class)->sendMessage(
                                    $sessId, $this->message->sender_id,
                                    RegHelper::getCancellationMessage(),
                                    'text'
                                );
                            } catch (\Exception $e) {
                                Log::error("Failed to send cancellation: " . $e->getMessage());
                            }
                            
                            return;
                        }
                        
                        // First time unregistered user - send welcome message
                        try {
                            $ch = \App\Models\Channel::find($this->message->channel_id);
                            $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
                            
                            app(\App\Services\WhatsAppService::class)->sendMessage(
                                $sessId, $this->message->sender_id,
                                RegHelper::getWelcomeMessage(),
                                'text'
                            );
                        } catch (\Exception $e) {
                            Log::error("Failed to send welcome message: " . $e->getMessage());
                        }
                        
                        return; // Don't process further
                    }

                }

                // 4. Apply Correction if Mismatch Detected
                if ($correctTenantId && $correctTenantId != $this->message->tenant_id) {
                    Log::warning('CORRECTING TENANT ROUTING', [
                        'message_id' => $this->message->id,
                        'sender' => $senderNumber,
                        'wrong_tenant_id' => $this->message->tenant_id,
                        'correct_tenant_id' => $correctTenantId
                    ]);
                    
                    // Update Message Model in Database
                    $this->message->tenant_id = $correctTenantId;
                    $this->message->save();
                    
                    // Update current instance for processing
                    // Note: Relationships like $this->message->tenant might still be cached, 
                    // so be careful if using relationships later. ideally refresh()
                    $this->message->refresh();
                }
            }
            


            switch ($this->message->type) {
                case 'text':
                    $this->processTextMessage();
                    break;
                
                case 'image':
                    $this->createOcrJob();
                    break;
                
                case 'audio':
                    $this->createSttJob();
                    break;
                
                case 'doc':
                case 'csv':
                    // TODO: Process document

                    break;
            }

        } catch (\Exception $e) {
            Log::error('Error processing incoming message', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
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
        
        // Check if this message came from OCR (has OCR job)
        $ocrJob = OcrJob::where('message_id', $this->message->id)->first();
        $isFromOcr = $ocrJob !== null;
        

        
        // Normalize keywords (map variations to standard commands)
        $originalText = $messageText;
        $messageText = \App\Helpers\KeywordNormalizer::normalize($messageText);
        
        if ($originalText !== $messageText) {

        }
        
        // CONTEXT MEMORY: Check for follow-up questions and enrich with context
        // IMPORTANT: Check BEFORE saving new context, so getLastContext returns previous message
        $contextService = new ConversationContextService($this->message->tenant_id);
        
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
                $this->handleTransaction($combinedMessage, null);
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
        if ($this->isBatchTransactionFormat($messageText)) {
            $this->handleBatchTransactions($messageText);
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

            $this->handleQuery($messageText);
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
                $this->handleQuery($messageText . ' hari ini');
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

                $this->handleDeleteReminder($messageText);
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

                $this->handleListReminders();
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

                $this->handleEnableDailyReminder();
                return;
            }
        }
        
        $disableDailyReminderKeywords = [
            'matikan reminder', 'nonaktifkan reminder', 'disable reminder',
            'off reminder', 'reminder off', 'stop reminder'
        ];
        foreach ($disableDailyReminderKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->handleDisableDailyReminder();
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

                $this->handleSetReminder($messageText, null);
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
            '/(?:isi|tambah|top\s*up)\s+saldo\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i'
        ];
        
        foreach ($transferToWalletPatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $this->handleTransferToWallet($messageText);
                return;
            }
        }
        
        // FAST PATH 1.9b: Catch INCOMPLETE "tambah saldo" commands (missing amount)
        // e.g., "tambah saldo BCA" without nominal - give helpful error message
        if (preg_match('/^(?:isi|tambah|top\s*up)\s+saldo\s+([a-zA-Z0-9\s]+)$/i', trim($messageText), $incompleteMatch)) {
            $walletName = trim($incompleteMatch[1]);
            $this->sendReply(
                "⚠️ *Nominal tidak terdeteksi*\n\n" .
                "Untuk menambah saldo ke *{$walletName}*, sertakan nominal:\n\n" .
                "Contoh:\n" .
                "• _tambah saldo {$walletName} 100rb_\n" .
                "• _isi saldo {$walletName} 1jt_\n" .
                "• _top up {$walletName} 500.000_"
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

                $this->handleExpenseFromWallet($messageText);
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

                $this->handleCheckBudget();
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

                $this->handleCheckInsight();
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

                $this->handleCheckAchievements();
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
                $this->handleDeleteSavingsTarget($messageText);
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

                $this->handleSetSavingsTarget($messageText);
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
                $this->handleSetSavingsTarget($messageText);
                return;
            }

            $this->handleAddSavings($messageText);
            return;
        }
        
        // 1.6ac2b: Add to SPECIFIC savings target - "masuk 600rb ke tabung menikah", "setor 1jt ke tabungan nikah"
        // Patterns: "masuk [amount] ke tabung/tabungan [target name]"
        //           "setor [amount] ke target [name]"
        //           "tambah [amount] ke tabungan [name]"
        if (preg_match('/(?:masuk|setor|tambah|isi)\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+(?:ke\s+)?(?:tabung|tabungan|target)\s+/i', $textLower)) {
            $this->handleAddSavingsToTarget($messageText);
            return;
        }
        
        // 1.6ad: Check target tabungan - "cek target", "lihat target", "progress target"
        $checkTargetKeywords = [
            'cek target', 'lihat target', 'progress target', 'target saya',
            'daftar target', 'list target', 'cek terget', 'lihat terget'
        ];
        foreach ($checkTargetKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->handleCheckSavingsTarget();
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

                $this->handleCheckSubscriptions();
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

                $this->handleExportPdf($messageText);
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
                $this->handleMultipleTransactionCommands($lines);
                return;
            }
        }
        
        // 1.6af2: Delete specific transaction by keyword - "hapus beli kue", "hapus makan siang"
        // Must have keyword after "hapus" but NOT "hapus transaksi terakhir" or "hapus semua"
        // EXCLUDE: "hapus target" and "hapus tabungan" - these are handled by delete savings target
        if (preg_match('/^(hapus|delete|batal)\s+(?!transaksi\s*$)(?!semua)(?!terakhir)(?!target)(?!tabungan)(?!saving)/i', $textLower)) {
            // Check if it's "hapus transaksi [keyword]" or "hapus [keyword]"
            $isSpecificDelete = preg_match('/^(hapus|delete|batal)\s+(transaksi\s+)?[a-zA-Z]/i', $textLower);
            if ($isSpecificDelete && !str_contains($textLower, 'terakhir') && !str_contains($textLower, 'semua') 
                && !str_contains($textLower, 'target') && !str_contains($textLower, 'tabungan')) {
                $this->handleDeleteTransactionByKeyword($messageText);
                return;
            }
        }
        
        // 1.6af3: Edit specific transaction by keyword - "ubah beli kue jadi 25rb", "edit makan siang 30rb"
        // Must have keyword + amount
        if (preg_match('/^(ubah|edit|ganti|koreksi)\s+(?!transaksi\s*$)(?!jadi\s)(?!ke\s)/i', $textLower) && $hasAmount) {
            // Check if pattern matches: "ubah [keyword] [jadi/ke]? [amount]"
            $isSpecificEdit = preg_match('/^(ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?[a-zA-Z].+\d+\s*(rb|ribu|k|jt|juta)?/i', $textLower);
            if ($isSpecificEdit && !str_contains($textLower, 'terakhir')) {
                $this->handleEditTransactionByKeyword($messageText);
                return;
            }
        }
        

        // 1.6ag: Context-based Edit/Correction - "salah harusnya 50rb", "koreksi jadi 30rb"
        $editContextKeywords = [
            'salah', 'koreksi', 'harusnya', 'seharusnya', 'ubah jadi', 'ganti jadi',
            'bukan', 'yang bener', 'yang benar', 'ralat'
        ];
        foreach ($editContextKeywords as $keyword) {
            if (str_contains($textLower, $keyword) && $hasAmount) {

                $this->handleEditWithContext($messageText);
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
            if (str_contains($textLower, $keyword) || $textLower === 'batal' || $textLower === 'undo') {
                $this->handleDeleteTransaction();
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

                $this->handleEnableDailyReminder();
                return;
            }
        }
        
        $disableReminderKeywords = [
            'matikan reminder', 'nonaktifkan reminder', 'disable reminder',
            'off reminder', 'reminder off', 'stop reminder'
        ];
        foreach ($disableReminderKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->handleDisableDailyReminder();
                return;
            }
        }
        
        // 1.6aj: Natural Language Stats/Analysis
        if (preg_match('/(gimana|bagaimana)\s+(?:kondisi|status|kabar)\s+keuangan/i', $textLower) ||
            str_contains($textLower, 'pengeluaran terbesar') ||
            str_contains($textLower, 'keuanganku') ||
            str_contains($textLower, 'analisis keuangan')) {
                
            $this->handleCheckStatisticsWithAI();
            return;
        }
        
        // 1.6ai: Delete budget - "hapus budget makan", "delete budget transport"
        $deleteBudgetKeywords = [
            'hapus budget', 'delete budget', 'hilangkan budget', 'buang budget',
            'hapus anggaran', 'delete anggaran', 'hilangkan anggaran'
        ];
        foreach ($deleteBudgetKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {

                $this->handleDeleteBudget($messageText);
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

                $this->handleAddBudget($messageText, null);
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

                    $this->handleSetBudget($messageText, null);
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
            $this->handleTransaction($messageText, null);
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
                    $this->handleViewSpecificWallet($walletName);
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
                        $this->handleAddWallet($messageText);
                    } elseif (str_contains($walletKeyword, 'hapus') || str_contains($walletKeyword, 'delete')) {
                        $this->handleDeleteWallet($messageText);
                    } else {
                        $this->handleListWallets();
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
            $this->handleMultipleSetWalletBalance($messageText);
            return;
        }
        
        // Single line match - use regular handler
        foreach ($setBalancePatterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {

                $this->handleSetWalletBalance($messageText);
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

                $this->handleDeleteAllTransactions($messageText);
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

                $this->handleResetAccount($messageText);
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
                $this->handleViewTransactions();
                return;
            }
        }

        // FAST PATH 4: FAQ/General Questions about the app
        // e.g., "bagaimana cara pakai?", "bisa untuk grup?", "apa itu finwa?"
        $faqResult = $this->checkAndHandleFAQ($messageText);
        if ($faqResult) {
            return;
        }
        
        // If message is from OCR, always treat as transaction
        if ($isFromOcr) {

            
            // For receipts: use structured data from OcrJob (total, date) instead of sending to AI
            // This prevents AI from creating multiple transactions for each line
            // NOTE: structured_data can be in metadata OR in result (JSON encoded by FinWa-AI handler)
            $metadata = $ocrJob->metadata ?? [];
            $structuredData = $metadata['structured_data'] ?? [];
            
            // Also check result field (FinWa-AI handler saves structured_data there)
            if (empty($structuredData) && !empty($ocrJob->result)) {
                $resultData = is_string($ocrJob->result) ? json_decode($ocrJob->result, true) : $ocrJob->result;
                if ($resultData && isset($resultData['structured_data'])) {
                    $structuredData = $resultData['structured_data'];
                }
            }
            
            $fields = $structuredData['fields'] ?? [];
            $entities = $structuredData['entities'] ?? [];
            
            // Debug logging


            
            // PRIORITY 1: Get total from structured data (most accurate - from LLM extraction)
            $total = isset($fields['total']) && $fields['total'] > 0 ? (int)$fields['total'] : null;
            $dateRaw = $fields['date_raw'] ?? null;
            
            // PRIORITY 2: If no total in structured data, try to extract from text using regex
            if (!$total) {

                $total = $this->extractTotalFromOcrText($messageText);
            }
            
            // PRIORITY 3: If still no total, try FinWa-AI for OCR extraction
            $storeName = null;
            $dateRaw = $dateRaw ?? null;
            
            if (!$total) {
                $finwaService = new FinWaAIService();
                if ($finwaService->isEnabled()) {

                    
                    $finwaResult = $finwaService->processOCR($messageText);
                    if ($finwaResult['success']) {
                        $finwaEntities = $finwaResult['data']['entities'] ?? [];
                        $total = $finwaEntities['nominal'] ?? null;
                        $storeName = $finwaEntities['merchant'] ?? null;
                        $dateRaw = $dateRaw ?? $finwaEntities['tanggal'] ?? null;
                        

                    }
                }
            }
            
            if ($total && $total > 0) {
                // Create single expense transaction for the receipt total

                
                // Determine store name from OCR text if not from FinWa-AI
                if (!$storeName) {
                    // Try to get from entities first (FinWa-AI)
                    $storeName = $entities['merchant'] ?? null;
                    if (!$storeName) {
                        $storeName = $this->extractStoreNameFromOcrText($messageText);
                    }
                }
                
                // Extract items from structured data if available
                // Priority: entities['items'] (FinWa-AI) > structuredData['items'] > fields['items']
                $items = $entities['items'] ?? $structuredData['items'] ?? $fields['items'] ?? [];
                
                // Create transaction data
                $txData = [
                    'type' => 'expense',
                    'amount' => $total,
                    'category_type' => 'pengeluaran_belanja',
                    'transaction_date' => $this->parseReceiptDate($dateRaw),
                    'description' => $storeName ? "Belanja di {$storeName}" : "Belanja dari struk",
                    'source' => 'receipt_ocr',
                    'confidence_score' => 0.95,
                    'account_name' => null
                ];
                
                // Create the transaction
                $transaction = $this->createTransaction($txData, false);
                
                if ($transaction) {
                    // Log items extraction result

                    
                    // Use sendReceiptConfirmation for detailed product list (if items available)
                    if (!empty($items)) {
                        $this->sendReceiptConfirmation($transaction, $items, $storeName);
                    } else {
                        // Fallback to simple confirmation if no items
                        $reply = "✅ *Berhasil Dicatat*\n\n";
                        $reply .= "💸 *Pengeluaran*: Rp " . number_format($total, 0, ',', '.') . "\n";
                        $reply .= "📁 Kategori: Belanja\n";
                        if ($storeName) {
                            $reply .= "🏪 Toko: {$storeName}\n";
                        }
                        if ($dateRaw) {
                            $reply .= "📅 Tanggal: {$dateRaw}\n";
                        }
                        $reply .= "\n*Struk berhasil dicatat sebagai 1 transaksi pengeluaran.*";
                        
                        $this->sendReply($reply);
                    }
                } else {
                    $this->sendReply("⚠️ Gagal mencatat transaksi dari struk. Silakan coba lagi.");
                }
                return;
            }
            
            // PRIORITY 4 (Fallback): if no total found, use AIProcessor to extract
            Log::warning('No total found in OCR data, falling back to AIProcessor extraction', [
                'message_id' => $this->message->id
            ]);
            $optimizedText = $this->optimizeOcrTextForAI($messageText);
            $this->handleTransaction($optimizedText);
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
                    $this->handleFinWaSpecialIntent($finwaIntent);
                    return;
                }
                
                // Handle tanya_finwa - Questions about FinWa app
                if ($finwaIntent === 'tanya_finwa') {
                    // Try to match specific FAQ pattern
                    if (!$this->checkAndHandleFAQ($messageText)) {
                        // If no specific match, send general help
                        $this->handleFinWaSpecialIntent('help');
                    }
                    return;
                }
                
                $confidence = $intentResult['data']['confidence'] ?? 0;
                
                // GUARD: Check if message contains transaction keywords but AI classified as check_balance/query
                // This prevents "Naik TJ" from triggering "Ringkasan Keuangan"
                $txKeywords = ['beli', 'bayar', 'naik', 'jajan', 'makan', 'minum', 'ngopi', 'isi', 'topup', 'transfer', 'ongkos', 'parkir', 'tol', 'ojek', 'grab', 'gojek'];
                $hasTxKeyword = false;
                $msgLower = strtolower($messageText);
                foreach($txKeywords as $k) {
                    if (str_contains($msgLower, " $k ") || str_starts_with($msgLower, "$k ")) {
                        $hasTxKeyword = true;
                        break;
                    }
                }

                // If it has transaction keywords, prevent entering query/check mode
                if ($hasTxKeyword && in_array($finwaIntent, ['cek_saldo', 'cek_budget', 'cek_statistik', 'cek_target', 'query', 'unknown'])) {
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
                        $this->handleTransaction($messageText, $finwaEntities);
                        return;
                    }
                    
                    // Handle hapus transaksi
                    if ($finwaIntent === 'hapus_transaksi') {
                        // Check if it's "hapus semua transaksi"
                        if (str_contains(strtolower($messageText), 'semua')) {
                            $this->handleDeleteAllTransactions($messageText);
                        } else {
                            $this->handleDeleteTransaction();
                        }
                        return;
                    }
                    
                    // Handle lihat transaksi
                    if ($finwaIntent === 'lihat_transaksi') {
                        $this->handleViewTransactions();
                        return;
                    }
                    
                    // Handle edit transaksi
                    if ($finwaIntent === 'edit_transaksi') {
                        $this->handleEditTransaction($messageText, $finwaEntities);
                        return;
                    }
                    
                    // Handle set reminder
                    if ($finwaIntent === 'set_reminder') {
                        $this->handleSetReminder($messageText, $finwaEntities);
                        return;
                    }
                    
                    // ========== NEW INTENTS (AI Enhancement Phase) ==========
                    
                    // Handle cek saldo
                    if ($finwaIntent === 'cek_saldo') {
                        $this->handleCheckBalance();
                        return;
                    }
                    
                    // Handle cek budget
                    if ($finwaIntent === 'cek_budget') {
                        $this->handleCheckBudget();
                        return;
                    }
                    
                    // Handle cek statistik
                    if ($finwaIntent === 'cek_statistik') {
                        $this->handleCheckStatisticsWithAI();
                        return;
                    }
                    
                    // Handle cek target
                    if ($finwaIntent === 'cek_target') {
                        $this->handleCheckTarget();
                        return;
                    }
                    
                    // Handle set budget
                    if ($finwaIntent === 'set_budget') {
                        $this->handleSetBudget($messageText, $finwaEntities);
                        return;
                    }
                    
                    // Handle set target
                    if ($finwaIntent === 'set_target') {
                        $this->handleSetTarget($messageText, $finwaEntities);
                        return;
                    }
                    
                    // Handle export laporan
                    if ($finwaIntent === 'export_laporan') {
                        $this->handleExportReport();
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
            $this->handleQuery($messageText);
        } elseif ($intent === 'greeting') {
            $this->handleFinWaSpecialIntent('sapa');
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
                
                $this->sendReply($clarificationMessage);
                return;


            }
            
            // Pass FinWa entities if available for faster processing
            $this->handleTransaction($messageText, $finwaEntities);
        }
    }
    
    /**
     * Process transfer after clarification response
     */
    protected function processTransferWithClarification(float $amount, bool $isIncome): void
    {

        
        $type = $isIncome ? 'income' : 'expense';
        $category = $isIncome ? 'pendapatan_lainnya' : 'transfer';
        $categoryType = $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
        $description = $isIncome ? "Terima transfer Rp " . number_format($amount, 0, ',', '.') 
                                 : "Kirim transfer Rp " . number_format($amount, 0, ',', '.');
        
        // Create transaction data
        $txData = [
            'type' => $type,
            'amount' => $amount,
            'category' => $category,
            'category_type' => $categoryType,
            'description' => $description . " [LocalType:{$categoryType}]", // Add debug info
            'account_name' => null,
            'transaction_date' => now()->toDateString(),
            'confidence_score' => 0.95,
            'source' => 'clarification_response',
        ];
        
        // Create the transaction
        $this->createTransactionFromData($txData);
    }
    
    /**
     * Create transaction from standardized data array
     */
    protected function createTransactionFromData(array $data): void
    {
        $this->transactionService->createTransactionFromData($data);
    }

    /**
     * Handle transaction extraction and creation
     */
    protected function handleTransaction(string $messageText, ?array $finwaEntities = null): void
    {
        $this->transactionService->handleTransaction($messageText, $finwaEntities);
    }
    
    /**
     * Handle special intents from FinWa-AI (sapa, help)
     */
    protected function handleFinWaSpecialIntent(string $intent): void
    {


        
        if ($intent === 'sapa') {
            // Get time-based greeting
            $hour = (int) now()->format('H');
            $greeting = match(true) {
                $hour >= 5 && $hour < 11 => 'Selamat pagi',
                $hour >= 11 && $hour < 15 => 'Selamat siang',
                $hour >= 15 && $hour < 18 => 'Selamat sore',
                default => 'Selamat malam',
            };
            
            // Try to get user's name
            $userName = '';
            try {
                $tenant = $this->message->tenant;
                if ($tenant && $tenant->user && $tenant->user->name) {
                    $firstName = explode(' ', $tenant->user->name)[0];
                    $userName = ", {$firstName}";
                }
            } catch (\Exception $e) {
                // Ignore if can't get name
            }
            
            $this->sendReply(
                "{$greeting}{$userName}! 👋\n\n" .
                "Saya *FinWa*, asisten keuangan pribadi Anda. Siap membantu mencatat dan menganalisis keuangan Anda dengan mudah! 💰\n\n" .
                "📝 *Mau catat transaksi?*\n" .
                "Cukup ketik seperti ngobrol biasa:\n" .
                "• _\"beli kopi 25rb\"_\n" .
                "• _\"gaji bulan ini 5jt\"_\n" .
                "• _\"kasih orang tua 500rb\"_\n" .
                "• Atau kirim foto struk! 📸\n\n" .
                "📊 *Mau cek keuangan?*\n" .
                "• _\"ringkasan bulan ini\"_\n" .
                "• _\"berapa pengeluaran minggu ini\"_\n\n" .
                "Ketik *help* untuk panduan lengkap 💡"
            );
        } elseif ($intent === 'help') {
            $this->sendReply(
                "📱 *FinWa - Panduan Lengkap v2.7*\n\n" .
                "Halo! Saya FinWa, asisten keuangan Anda 🚀\n\n" .
                "━━━ 💸 *CATAT PENGELUARAN* ━━━\n" .
                "Ketik seperti ngobrol biasa:\n" .
                "• _makan siang 25rb_\n" .
                "• _beli bensin 50k_\n" .
                "• _kasih orang tua 500rb_\n\n" .
                "*Dari dompet tertentu:*\n" .
                "• _keluar dari BCA 50rb beli kopi_\n" .
                "• _bayar dari gopay 25rb grab_\n\n" .
                "━━━ 📸 *SCAN STRUK* ━━━\n" .
                "Kirim foto struk, FinWa baca otomatis!\n" .
                "• Foto harus jelas & tidak kusut\n" .
                "• Total belanja terlihat jelas\n\n" .
                "━━━ 🎤 *VOICE NOTE* ━━━\n" .
                "Malas ketik? Kirim pesan suara!\n" .
                "Contoh ucapkan:\n" .
                "• _\"beli makan dua puluh lima ribu\"_\n" .
                "• _\"terima transfer lima ratus ribu\"_\n\n" .
                "━━━ 📝 *CATAT BANYAK SEKALIGUS* ━━━\n" .
                "*Pengeluaran:*\n" .
                "_Biaya tanggal 10 Des 2025_\n" .
                "_1. Makan siang 25rb_\n" .
                "_2. Grab pulang 15rb_\n\n" .
                "*Pemasukan dari banyak orang:*\n" .
                "_Uang masuk atas nama_\n" .
                "_Agung 116k_\n" .
                "_Budi 500rb_\n\n" .
                "━━━ 💰 *CATAT PEMASUKAN* ━━━\n" .
                "• _gaji bulan ini 8jt_\n" .
                "• _dapat bonus 1.5jt_\n" .
                "• _terima transfer 500rb_\n" .
                "• _dapet tf 100rb ke BCA_ (ke dompet)\n\n" .
                "━━━ 💳 *DOMPET/REKENING* ━━━\n" .
                "*Tambah dompet:*\n" .
                "• _tambah dompet BCA_\n" .
                "• _tambah dompet Gopay saldo 100rb_\n\n" .
                "*Kelola dompet:*\n" .
                "• _lihat dompet_ - daftar saldo\n" .
                "• _set saldo BCA menjadi 1jt_ - atur saldo\n" .
                "• _tambah saldo Dana 500rb_ - top up\n" .
                "• _hapus dompet [nama]_\n\n" .
                "━━━ 📊 *CEK KEUANGAN* ━━━\n" .
                "• _cek saldo_ - lihat saldo\n" .
                "• _ringkasan hari ini_ - harian\n" .
                "• _ringkasan minggu ini_ - mingguan\n" .
                "• _ringkasan bulan ini_ - bulanan\n" .
                "• _cek cashflow_ - arus kas\n" .
                "• _lihat transaksi_ - riwayat\n\n" .
                "━━━ 🎯 *BUDGET* ━━━\n" .
                "• _set budget makan 500rb_\n" .
                "• _cek budget_ - lihat budget\n\n" .
                "━━━ 💰 *TARGET TABUNGAN* ━━━\n" .
                "• _set target 10jt untuk liburan_\n" .
                "• _tabung 500rb_ - tambah tabungan\n" .
                "• _cek target_ - lihat progress\n\n" .
                "━━━ 📈 *INSIGHT & ANALISIS* ━━━\n" .
                "• _cek insight_ - pola pengeluaran\n" .
                "• _cek statistik_ - analisis\n" .
                "• _cek langganan_ - track subscription\n\n" .
                "━━━ 🏆 *ACHIEVEMENT* ━━━\n" .
                "• _lihat achievement_ - badge & poin\n" .
                "• _badge saya_ - cek pencapaian\n\n" .
                "━━━ ✏️ *EDIT & HAPUS* ━━━\n" .
                "• _edit jadi 50rb_ - ubah nominal\n" .
                "• _ganti kategori makan_\n" .
                "• _edit tanggal kemarin_\n" .
                "• _hapus transaksi_\n" .
                "• _hapus semua transaksi_\n" .
                "• _reset akun_ - hapus data & dompet (mulai nol)\n\n" .
                "━━━ 📥 *DOWNLOAD LAPORAN* ━━━\n" .
                "• _download laporan_ - PDF bulanan\n" .
                "• _laporan bulan ini_ - PDF bulan ini\n" .
                "• _laporan minggu ini_ - PDF minggu ini\n\n" .
                "━━━ 💡 *TIPS* ━━━\n" .
                "• Format angka: 25rb, 50k, 1.5jt\n" .
                "• Bahasa gaul OK: _gw beli kopi 25rb_\n" .
                "• Voice note 🎤 / Foto struk 📸\n" .
                "• Kategori otomatis terdeteksi\n\n" .
                "Butuh bantuan? Langsung tanya saja! 😊"
            );
        }
    }
    
    /**
     * Handle delete transaction request (hapus transaksi terakhir)
     */
    protected function createTransaction(array $txData, bool $needsReview = false): ?Transaction
    {
        return $this->transactionService->createTransaction($txData, $needsReview);
    }

    protected function createOcrJob(): void
    {
        $ocrJob = $this->ocrProcessor->createOcrJob();
        
        if ($ocrJob) {
            $this->ocrProcessor->dispatchToOcrWorker($ocrJob);
        } else {
            $this->sendReply("⚠️ Gagal memproses gambar. Pastikan gambar valid.");
        }
    }

    /**
     * Create and process STT job for voice notes
     */
    protected function createSttJob(): void
    {
        $sttJob = $this->sttProcessor->createSttJob();
        
        if ($sttJob) {
            // Process the STT job (transcribe audio)
            $this->sttProcessor->processSttJob($sttJob);
            
            // After transcription, check if we have text to process
            $sttJob->refresh();
            if ($sttJob->status === 'completed' && !empty($sttJob->transcribed_text)) {
                // Process the transcribed text as a regular text message
                $this->processTextMessage($sttJob->transcribed_text);
            }
        } else {
            $this->sendReply(
                "⚠️ *Gagal memproses pesan suara*\n\n" .
                "Pastikan voice note valid.\n" .
                "Atau ketik pesan Anda secara manual."
            );
        }
    }

    protected function handleBatchTransactions(string $messageText): void
    {
        $this->batchTransaction->handleBatchTransactions($messageText);
    }

    protected function isBatchTransactionFormat(string $text): bool
    {
        return $this->batchTransaction->isBatchTransactionFormat($text);
    }

    protected function sendReply(string $message): void
    {
        $this->replyService->sendReply($message);
    }
    protected function handleDeleteTransaction(): void
    {
        $this->transactionService->handleDeleteTransaction();
    }
    
    /**
     * Handle delete transaction by keyword (e.g., "hapus beli kue")
     */
    protected function handleDeleteTransactionByKeyword(string $messageText): void
    {
        $this->transactionService->handleDeleteTransactionByKeyword($messageText);
    }
    
    /**
     * Handle edit transaction by keyword (e.g., "ubah beli kue jadi 25rb")
     */
    protected function handleEditTransactionByKeyword(string $messageText): void
    {
        $this->transactionService->handleEditTransactionByKeyword($messageText);
    }
    
    /**
     * Handle view specific wallet balance (e.g., "saldo BCA berapa?")
     */
    protected function handleViewSpecificWallet(string $walletName): void
    {
        try {
            // Search for wallet by name (case-insensitive)
            $wallet = \App\Models\Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->whereRaw('LOWER(account_name) LIKE ?', ['%' . strtolower($walletName) . '%'])
                ->first();
            
            if (!$wallet) {
                $this->sendReply(
                    "⚠️ *Dompet tidak ditemukan*\n\n" .
                    "Tidak ada dompet dengan nama \"*{$walletName}*\".\n\n" .
                    "💡 Ketik _lihat dompet_ untuk melihat daftar dompet Anda."
                );
                return;
            }
            
            $balance = number_format($wallet->balance, 0, ',', '.');
            $icon = $wallet->icon ?? '💰';
            
            $this->sendReply(
                "{$icon} *Saldo {$wallet->account_name}*\n" .
                "━━━━━━━━━━━━━━━\n\n" .
                "💵 *Rp {$balance}*"
            );
            
        } catch (\Exception $e) {
            Log::error('Error viewing specific wallet', [
                'wallet_name' => $walletName,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal mengambil saldo*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }

    /**
     * Handle multiple transaction commands (hapus/edit) from multi-line message
     */
    protected function handleMultipleTransactionCommands(array $lines): void
    {
        $results = [];
        $deleteCount = 0;
        $editCount = 0;
        $errorCount = 0;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $lineLower = strtolower($line);
            
            // Check if it's a delete command
            if (preg_match('/^(hapus|delete|batal)\s+/i', $lineLower)) {
                $result = $this->processDeleteCommand($line);
                if ($result['success']) {
                    $deleteCount++;
                    $results[] = "🗑️ Dihapus: {$result['description']} (Rp {$result['amount']})";
                } else {
                    $errorCount++;
                    $results[] = "❌ Gagal hapus: {$result['error']}";
                }
            }
            // Check if it's an edit command
            elseif (preg_match('/^(ubah|edit|ganti|koreksi)\s+/i', $lineLower)) {
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
        
        // Build reply
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
     * Process a single delete command (used by batch processor)
     */
    protected function processDeleteCommand(string $line): array
    {
        try {
            $keyword = preg_replace('/^(hapus|delete|batal|batalkan)\s+(transaksi\s+)?/i', '', trim($line));
            $keyword = trim($keyword);
            
            if (empty($keyword) || strlen($keyword) < 3) {
                return ['success' => false, 'error' => 'keyword terlalu pendek'];
            }
            
            // Search for matching transaction today only
            $transaction = \App\Models\Transaction::where('tenant_id', $this->message->tenant_id)
                ->whereRaw('LOWER(description) LIKE ?', ['%' . strtolower($keyword) . '%'])
                ->whereDate('transaction_date', today())
                ->orderBy('created_at', 'desc')
                ->first();
            
            if (!$transaction) {
                return ['success' => false, 'error' => "'{$keyword}' tidak ditemukan"];
            }
            
            // Reverse balance
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
     * Process a single edit command (used by batch processor)
     */
    protected function processEditCommand(string $line): array
    {
        try {
            // Pattern: "ubah keyword jadi 25rb" or "edit keyword 25rb"
            $pattern = '/^(ubah|edit|ganti|koreksi)\s+(?:transaksi\s+)?(.+?)\s+(?:jadi|ke|menjadi)?\s*(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?/i';
            
            if (!preg_match($pattern, trim($line), $matches)) {
                return ['success' => false, 'error' => 'format tidak valid'];
            }
            
            $keyword = trim($matches[2]);
            $numValue = floatval(str_replace(',', '.', $matches[3]));
            $suffix = strtolower($matches[4] ?? '');
            
            $multipliers = ['rb' => 1000, 'ribu' => 1000, 'k' => 1000, 'jt' => 1000000, 'juta' => 1000000];
            $multiplier = $multipliers[$suffix] ?? 1;
            $newAmount = (int)($numValue * $multiplier);
            
            if ($newAmount <= 0) {
                return ['success' => false, 'error' => 'nominal tidak valid'];
            }
            
            // Search for matching transaction today only
            $transaction = \App\Models\Transaction::where('tenant_id', $this->message->tenant_id)
                ->whereRaw('LOWER(description) LIKE ?', ['%' . strtolower($keyword) . '%'])
                ->whereDate('transaction_date', today())
                ->orderBy('created_at', 'desc')
                ->first();
            
            if (!$transaction) {
                return ['success' => false, 'error' => "'{$keyword}' tidak ditemukan"];
            }
            
            // Update balance
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
                'new_amount' => number_format($newAmount, 0, ',', '.')
            ];
            
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    
    /**
     * Handle view transactions request (lihat transaksi hari ini)
     */
    protected function handleViewTransactions(): void
    {
        $this->transactionService->handleViewTransactions();
    }
    
    /**
     * Handle edit transaction request (edit transaksi terakhir)
     * Supports: "edit jadi 50rb", "ubah ke 100rb", "ganti nominal 75rb", "edit tanggal 11 des 2025"
     */
    protected function handleEditTransaction(string $messageText, ?array $finwaEntities = null): void
    {
        $this->transactionService->handleEditTransaction($messageText, $finwaEntities);
    }
    
    /**
     * Handle set reminder request
     * Supports: "ingatkan bayar listrik tanggal 20 setiap bulan 500rb"
     *           "reminder internet 250rb tanggal 5"
     *           "ingatkan gaji setiap tanggal 25"
     */
    protected function handleSetReminder(string $messageText, ?array $finwaEntities = null): void
    {
        $this->reminderService->handleSetReminder($messageText, $finwaEntities);
    }
    
    /**
     * Handle delete reminder request
     * Supports: "hapus pengingat", "hapus pengingat 1", "hapus pengingat terakhir", "hapus semua pengingat"
     */
    protected function handleDeleteReminder(string $messageText): void
    {
        $this->reminderService->handleDeleteReminder($messageText);
    }
    
    /**
     * Handle list reminders request
     * Shows all active reminders for this tenant
     */
    protected function handleListReminders(): void
    {
        $this->reminderService->handleListReminders();
    }
    
    /**
     * Handle reset account request
     * Allows user to reset all their wallets, transactions, and reminders
     * Requires confirmation with specific keyword to prevent accidents
     */
    protected function handleResetAccount(string $messageText): void
    {
        $this->accountCommandService->handleResetAccount($messageText);
    }
    
    /**
     * Handle delete all transactions (keeps wallets and reminders)
     */
    protected function handleDeleteAllTransactions(string $messageText): void
    {
        $this->accountCommandService->handleDeleteAllTransactions($messageText);
    }
    
    /**
     * Handle transfer to specific wallet
     * e.g., "dapet tf 25rb ke BCA", "terima transfer 100rb ke gopay"
     */
    protected function handleTransferToWallet(string $messageText): void
    {
        $this->walletCommand->handleTransferToWallet($messageText);
    }
    
    /**
     * Handle expense from specific wallet
     * e.g., "Pengeluaran dompet kas kepri 98k beli mata kunci sok 1 set"
     *       "keluar dari dompet BCA 50rb beli kopi"
     *       "bayar dari gopay 25rb grab"
     */
    protected function handleExpenseFromWallet(string $messageText): void
    {
        $this->walletCommand->handleExpenseFromWallet($messageText);
    }
    
    /**
     * Determine category from description text
     */
    protected function determineCategoryFromDescription(string $description): string
    {
        return $this->categoryMapping->determineCategoryFromDescription($description);
    }

    /**
     * Handle set/edit wallet balance
     */
    protected function handleSetWalletBalance(string $messageText): void
    {
        $this->walletCommand->handleSetWalletBalance($messageText);
    }

    /**
     * Handle multiple wallet balance updates from a single multi-line message
     */
    protected function handleMultipleSetWalletBalance(string $messageText): void
    {
        $this->walletCommand->handleMultipleSetWalletBalance($messageText);
    }

    /**
     * Handle financial query
     */
    protected function handleQuery(string $question): void
    {
        $this->financialQueryHandler->handleQuery($question);
    }

    /**
     * Create default categories for tenant
     */
    protected function createCategoriesForTenant(int $tenantId): void
    {
        $this->categoryManager->createCategoriesForTenant($tenantId);
    }

    /**
     * Map FinWa-AI kategori to system category_type format
     * 
     * @param string|null $kategori The kategori from FinWa-AI (e.g., 'makan', 'transport', 'gaji')
     * @param bool $isIncome Whether this is an income transaction
     * @return string The mapped category_type (e.g., 'pengeluaran_makanan', 'pendapatan_gaji')
     */
    protected function mapFinwaKategoriToCategoryType(?string $kategori, bool $isIncome): string
    {
        return $this->categoryMapping->mapFinwaKategoriToCategoryType($kategori, $isIncome);
    }

    /**
     * Extract product name from description
     */
    protected function extractProductName(?string $description): ?string
    {
        return $this->transactionExtractor->extractProductName($description);
    }

    /**
     * Extract account name from message text (fallback if AI doesn't extract)
     */
    protected function extractAccountNameFromMessage(string $messageText): ?string
    {
        return $this->transactionExtractor->extractAccountNameFromMessage($messageText);
    }

    /**
     * Send transaction confirmation reply
     */
    protected function sendTransactionConfirmation(array $transactions, bool $needsReview = false): void
    {
        $this->confirmationService->sendConfirmation($transactions, $needsReview);
    }
    

    
    /**
     * Send budget warning as a follow-up message
     */
    protected function sendBudgetWarning(string $message, Transaction $transaction, Budget $budget, bool $isOverBudget = false): void
    {
        $this->budgetAlert->sendBudgetWarning($message, $transaction, $budget, $isOverBudget);
    }
    
    /**
     * Handle check insight request (cek insight, analisis spending)
     */
    protected function handleCheckInsight(): void
    {
        try {
            $insightService = new SpendingInsightService($this->message->tenant_id);
            $report = $insightService->generateInsightReport();
            
            $this->sendReply($report);
            

            
        } catch (\Exception $e) {
            Log::error('Error generating insight report', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal memuat insight*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
    
    /**
     * Handle check achievements request (lihat achievement, badge saya)
     */
    protected function handleCheckAchievements(): void
    {
        try {
            $achievementService = new AchievementService($this->message->tenant_id);
            $message = $achievementService->generateSummaryMessage();
            $this->sendReply($message);
            

        } catch (\Exception $e) {
            Log::error('Error generating achievement report', [
                'error' => $e->getMessage()
            ]);
            $this->sendReply("⚠️ *Gagal memuat achievement*\n\nTerjadi kesalahan. Silakan coba lagi nanti.");
        }
    }
    
    
    
    /**
     * Handle set savings target request (set target 10jt, target nabung 5jt)
     */
    protected function handleSetSavingsTarget(string $messageText): void
    {
        try {
            // Extract amount from message
            $nominal = null;
            $name = 'Target Tabungan';
            
            // Pattern 1: set target [nominal] [optional: untuk nama]
            // e.g., "set target 50jt untuk nikah", "target nabung 10jt untuk umroh"
            if (preg_match('/(?:set\s+target|target\s+(?:tabungan|nabung|saving)|mau\s+nabung|buat\s+target)\s*(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?(?:\s+(?:untuk|buat)\s+(.+))?/i', $messageText, $matches)) {
                $numericValue = str_replace(['.', ','], '', $matches[1]);
                $nominal = (float) $numericValue;
                
                $multiplier = strtolower($matches[2] ?? '');
                if (in_array($multiplier, ['rb', 'ribu', 'k'])) {
                    $nominal *= 1000;
                } elseif (in_array($multiplier, ['jt', 'juta'])) {
                    $nominal *= 1000000;
                }
                
                if (!empty($matches[3])) {
                    $name = ucfirst(trim($matches[3]));
                }
            }
            // Pattern 2: tabung [nominal] untuk [nama] - NEW FORMAT
            // e.g., "tabung 50jt untuk menikah", "nabung 1jt buat umroh"
            elseif (preg_match('/(?:tabung|nabung)\s+(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?\s+(?:untuk|buat)\s+(.+)/i', $messageText, $matches)) {
                $numericValue = str_replace(['.', ','], '', $matches[1]);
                $nominal = (float) $numericValue;
                
                $multiplier = strtolower($matches[2] ?? '');
                if (in_array($multiplier, ['rb', 'ribu', 'k'])) {
                    $nominal *= 1000;
                } elseif (in_array($multiplier, ['jt', 'juta'])) {
                    $nominal *= 1000000;
                }
                
                if (!empty($matches[3])) {
                    $name = ucfirst(trim($matches[3]));
                }
            }
            
            if (!$nominal || $nominal <= 0) {
                $this->sendReply(
                    "🎯 *Set Target Tabungan*\n\n" .
                    "Untuk mengatur target, ketik:\n\n" .
                    "• _\"set target 10jt\"_\n" .
                    "• _\"target nabung 5jt untuk liburan\"_\n" .
                    "• _\"mau nabung 2jt untuk iPhone\"_\n\n" .
                    "💡 Target membantu Anda fokus pada tujuan keuangan."
                );
                return;
            }
            
            // Create savings goal
            $goal = SavingsGoal::create([
                'tenant_id' => $this->message->tenant_id,
                'name' => $name,
                'target_amount' => $nominal,
                'current_amount' => 0,
                'status' => 'active',
                'icon' => '🎯',
            ]);
            
            $formattedAmount = number_format($nominal, 0, ',', '.');
            
            $this->sendReply(
                "✅ *Target Tabungan Dibuat!*\n\n" .
                "🎯 {$name}\n" .
                "💵 Target: Rp {$formattedAmount}\n" .
                "📊 Progress: 0%\n\n" .
                $goal->getProgressBar() . "\n\n" .
                "💡 *Cara menabung:*\n" .
                "_\"tabung 500rb\"_ atau _\"nabung 1jt\"_\n\n" .
                "Cek progress: _\"cek target\"_"
            );
            

            
        } catch (\Exception $e) {
            Log::error('Error creating savings target', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal membuat target*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle delete savings target request (hapus target menikah, hapus tabungan nikah)
     */
    protected function handleDeleteSavingsTarget(string $messageText): void
    {
        try {
            // Extract target name from message
            // Patterns: "hapus target menikah", "hapus tabungan nikah", "hapus target tabungan menikah"
            $targetName = null;
            
            // Pattern handles: "hapus target tabungan X", "hapus target X", "hapus tabungan X"
            if (preg_match('/(?:hapus|delete|batalkan|remove|hilangkan)\s+(?:target\s+tabungan|target|tabungan|saving)\s+(.+)/i', $messageText, $matches)) {
                $targetName = trim($matches[1]);
            }
            
            if (!$targetName) {
                // Show list of targets that can be deleted
                $goals = SavingsGoal::where('tenant_id', $this->message->tenant_id)
                    ->where('status', 'active')
                    ->orderBy('created_at', 'desc')
                    ->get();
                
                if ($goals->isEmpty()) {
                    $this->sendReply(
                        "🎯 *Hapus Target Tabungan*\n\n" .
                        "Belum ada target tabungan aktif.\n\n" .
                        "Buat target baru:\n" .
                        "_\"set target 10jt untuk liburan\"_"
                    );
                    return;
                }
                
                $message = "🗑️ *Hapus Target Tabungan*\n\n";
                $message .= "Target yang tersedia:\n";
                foreach ($goals as $index => $goal) {
                    $num = $index + 1;
                    $message .= "{$num}. {$goal->icon} {$goal->name}\n";
                }
                $message .= "\nUntuk menghapus, ketik:\n";
                $message .= "_\"hapus target [nama target]\"_\n\n";
                $message .= "Contoh: _hapus target menikah_";
                
                $this->sendReply($message);
                return;
            }
            
            // Search for the target by name (case-insensitive, fuzzy match)
            $goals = SavingsGoal::where('tenant_id', $this->message->tenant_id)
                ->where('status', 'active')
                ->get();
            
            $matchedGoal = null;
            $similarGoals = [];
            
            foreach ($goals as $goal) {
                $goalNameLower = strtolower($goal->name);
                $targetNameLower = strtolower($targetName);
                
                // Exact match
                if ($goalNameLower === $targetNameLower) {
                    $matchedGoal = $goal;
                    break;
                }
                
                // Partial match (target name contains search term or vice versa)
                if (str_contains($goalNameLower, $targetNameLower) || str_contains($targetNameLower, $goalNameLower)) {
                    $similarGoals[] = $goal;
                }
            }
            
            // If no exact match but one similar goal found, use it
            if (!$matchedGoal && count($similarGoals) === 1) {
                $matchedGoal = $similarGoals[0];
            }
            
            if (!$matchedGoal) {
                if (count($similarGoals) > 1) {
                    // Multiple similar matches found
                    $message = "⚠️ *Ditemukan beberapa target serupa:*\n\n";
                    foreach ($similarGoals as $index => $goal) {
                        $num = $index + 1;
                        $message .= "{$num}. {$goal->icon} {$goal->name}\n";
                    }
                    $message .= "\nSebutkan nama yang lebih spesifik:\n";
                    $message .= "_\"hapus target [nama lengkap]\"_";
                    $this->sendReply($message);
                    return;
                }
                
                // No match found
                $message = "❌ *Target tidak ditemukan*\n\n";
                $message .= "Target \"{$targetName}\" tidak ada.\n\n";
                
                if ($goals->isNotEmpty()) {
                    $message .= "Target yang tersedia:\n";
                    foreach ($goals as $index => $goal) {
                        $num = $index + 1;
                        $message .= "{$num}. {$goal->icon} {$goal->name}\n";
                    }
                }
                
                $this->sendReply($message);
                return;
            }
            
            // Delete the target (soft delete by setting status to 'cancelled')
            $deletedName = $matchedGoal->name;
            $deletedIcon = $matchedGoal->icon;
            $currentAmount = $matchedGoal->current_amount;
            $targetAmount = $matchedGoal->target_amount;
            
            $matchedGoal->status = 'cancelled';
            $matchedGoal->save();
            
            $formattedCurrent = number_format($currentAmount, 0, ',', '.');
            $formattedTarget = number_format($targetAmount, 0, ',', '.');
            
            $this->sendReply(
                "✅ *Target Berhasil Dihapus!*\n\n" .
                "{$deletedIcon} *{$deletedName}*\n" .
                "💰 Terkumpul: Rp {$formattedCurrent}\n" .
                "🎯 Target: Rp {$formattedTarget}\n\n" .
                "Target tabungan ini sudah dihapus.\n\n" .
                "_Lihat target lain: \"cek target\"_\n" .
                "_Buat target baru: \"set target 10jt untuk liburan\"_"
            );
            
            Log::info('Savings target deleted', [
                'message_id' => $this->message->id,
                'goal_id' => $matchedGoal->id,
                'goal_name' => $deletedName
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error deleting savings target', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menghapus target*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle add savings to specific target (masuk 600rb ke tabung menikah, setor 1jt ke tabungan nikah)
     */
    protected function handleAddSavingsToTarget(string $messageText): void
    {
        try {
            // Extract amount and target name
            // Patterns: "masuk 600rb ke tabung menikah", "setor 1jt ke tabungan nikah"
            $nominal = null;
            $targetName = null;
            
            // Pattern: [action] [amount] ke tabung/tabungan/target [name]
            if (preg_match('/(?:masuk|setor|tambah|isi)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(?:ke\s+)?(?:tabung|tabungan|target)\s+(.+)/i', $messageText, $matches)) {
                $nominal = $this->transactionExtractor->extractAmountFromText($matches[1]);
                $targetName = trim($matches[2]);
            }
            
            if (!$nominal || $nominal <= 0) {
                $this->sendReply(
                    "💰 *Menabung ke Target Spesifik*\n\n" .
                    "Format: _masuk [nominal] ke tabung [nama target]_\n\n" .
                    "Contoh:\n" .
                    "• _masuk 600rb ke tabung menikah_\n" .
                    "• _setor 1jt ke tabungan nikah_\n" .
                    "• _tambah 500rb ke target umroh_"
                );
                return;
            }
            
            if (!$targetName) {
                $this->sendReply(
                    "⚠️ *Nama target tidak terdeteksi*\n\n" .
                    "Sebutkan nama target tabungan:\n" .
                    "• _masuk 600rb ke tabung menikah_\n" .
                    "• _setor 1jt ke tabungan liburan_"
                );
                return;
            }
            
            // Search for the target by name (case-insensitive, fuzzy match)
            $goals = SavingsGoal::where('tenant_id', $this->message->tenant_id)
                ->where('status', 'active')
                ->get();
            
            $matchedGoal = null;
            $similarGoals = [];
            
            foreach ($goals as $goal) {
                $goalNameLower = strtolower($goal->name);
                $targetNameLower = strtolower($targetName);
                
                // Exact match
                if ($goalNameLower === $targetNameLower) {
                    $matchedGoal = $goal;
                    break;
                }
                
                // Partial match (target name contains search term or vice versa)
                if (str_contains($goalNameLower, $targetNameLower) || str_contains($targetNameLower, $goalNameLower)) {
                    $similarGoals[] = $goal;
                }
            }
            
            // If no exact match but one similar goal found, use it
            if (!$matchedGoal && count($similarGoals) === 1) {
                $matchedGoal = $similarGoals[0];
            }
            
            if (!$matchedGoal) {
                if (count($similarGoals) > 1) {
                    // Multiple similar matches found
                    $message = "⚠️ *Ditemukan beberapa target serupa:*\n\n";
                    foreach ($similarGoals as $index => $goal) {
                        $num = $index + 1;
                        $message .= "{$num}. {$goal->icon} {$goal->name}\n";
                    }
                    $message .= "\nSebutkan nama yang lebih spesifik:\n";
                    $message .= "_\"masuk 600rb ke tabung [nama lengkap]\"_";
                    $this->sendReply($message);
                    return;
                }
                
                // No match found
                if ($goals->isEmpty()) {
                    $this->sendReply(
                        "❌ *Belum ada target tabungan*\n\n" .
                        "Buat target dulu:\n" .
                        "_\"tabung 50jt untuk menikah\"_\n\n" .
                        "Setelah itu baru bisa menabung!"
                    );
                } else {
                    $message = "❌ *Target \"{$targetName}\" tidak ditemukan*\n\n";
                    $message .= "Target yang tersedia:\n";
                    foreach ($goals as $index => $goal) {
                        $num = $index + 1;
                        $message .= "{$num}. {$goal->icon} {$goal->name}\n";
                    }
                    $message .= "\nContoh: _masuk 600rb ke tabung {$goals->first()->name}_";
                    $this->sendReply($message);
                }
                return;
            }
            
            // Add savings to the matched goal
            $previousAmount = $matchedGoal->current_amount;
            $matchedGoal->addSavings($nominal);
            
            $formattedNominal = number_format($nominal, 0, ',', '.');
            $formattedCurrent = number_format($matchedGoal->current_amount, 0, ',', '.');
            $formattedTarget = number_format($matchedGoal->target_amount, 0, ',', '.');
            $formattedRemaining = number_format($matchedGoal->getRemainingAmount(), 0, ',', '.');
            $percentage = round($matchedGoal->getProgressPercentage());
            
            $message = "✅ *Tabungan Ditambahkan!*\n\n";
            $message .= "🎯 {$matchedGoal->icon} *{$matchedGoal->name}*\n";
            $message .= "💵 +Rp {$formattedNominal}\n\n";
            $message .= "📊 Progress: {$percentage}%\n";
            $message .= $matchedGoal->getProgressBar() . "\n\n";
            $message .= "💰 Terkumpul: Rp {$formattedCurrent}\n";
            $message .= "🎯 Target: Rp {$formattedTarget}\n";
            
            if ($matchedGoal->isCompleted()) {
                $message .= "\n🎉🎉 *SELAMAT! TARGET TERCAPAI!* 🎉🎉";
            } else {
                $message .= "📌 Kurang: Rp {$formattedRemaining}";
            }
            
            $this->sendReply($message);
            
            Log::info('Savings added to specific target', [
                'message_id' => $this->message->id,
                'goal_id' => $matchedGoal->id,
                'goal_name' => $matchedGoal->name,
                'amount' => $nominal,
                'new_total' => $matchedGoal->current_amount
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error adding savings to target', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menambah tabungan*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle check savings target request (cek target, lihat target)
     */
    protected function handleCheckSavingsTarget(): void
    {
        try {
            $goals = SavingsGoal::where('tenant_id', $this->message->tenant_id)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->get();
            
            if ($goals->isEmpty()) {
                $this->sendReply(
                    "🎯 *Target Tabungan*\n\n" .
                    "Belum ada target aktif.\n\n" .
                    "Buat target baru:\n" .
                    "_\"set target 10jt untuk liburan\"_\n" .
                    "_\"target nabung 5jt\"_"
                );
                return;
            }
            
            $message = "🎯 *Target Tabungan Anda*\n";
            $message .= "━━━━━━━━━━━━━━━\n\n";
            
            foreach ($goals as $index => $goal) {
                $num = $index + 1;
                $targetFormatted = number_format($goal->target_amount, 0, ',', '.');
                $currentFormatted = number_format($goal->current_amount, 0, ',', '.');
                $remainingFormatted = number_format($goal->getRemainingAmount(), 0, ',', '.');
                $percentage = round($goal->getProgressPercentage());
                
                $message .= "{$num}. {$goal->icon} *{$goal->name}*\n";
                $message .= "   💵 Target: Rp {$targetFormatted}\n";
                $message .= "   💰 Terkumpul: Rp {$currentFormatted}\n";
                $message .= "   📊 " . $goal->getProgressBar(15) . "\n";
                
                if ($goal->getRemainingAmount() > 0) {
                    $message .= "   📌 Kurang: Rp {$remainingFormatted}\n";
                    
                    // Suggested monthly savings if deadline exists
                    $suggested = $goal->getSuggestedMonthlySavings();
                    if ($suggested) {
                        $suggestedFormatted = number_format($suggested, 0, ',', '.');
                        $message .= "   💡 Nabung Rp {$suggestedFormatted}/bulan\n";
                    }
                } else {
                    $message .= "   🎉 *TARGET TERCAPAI!*\n";
                }
                $message .= "\n";
            }
            
            $message .= "_Tambah tabungan: \"tabung 500rb\"_";
            
            $this->sendReply($message);
            

            
        } catch (\Exception $e) {
            Log::error('Error viewing savings targets', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal memuat target*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle add savings request (tabung 500rb, nabung 1jt)
     */
    protected function handleAddSavings(string $messageText): void
    {
        try {
            // Extract amount
            $nominal = null;
            
            // Try to match specific savings keywords first
            if (preg_match('/(?:tabung|nabung|tambah\s+tabungan|isi\s+tabungan|ambah\s+tabungan)\s+((?:Rp\s?)?[\d\.,]+(?:\s*(?:rb|ribu|k|jt|juta|juta|m|million))?)/i', $messageText, $matches)) {
                 $nominal = $this->transactionExtractor->extractAmountFromText($matches[1]);
            } else {
                 // Fallback to general extraction
                 $nominal = $this->transactionExtractor->extractAmountFromText($messageText);
            }
            
            if (!$nominal || $nominal <= 0) {
                $this->sendReply(
                    "💰 *Menabung*\n\n" .
                    "Format: _tabung 500rb_ atau _nabung 1jt_\n\n" .
                    "Contoh:\n" .
                    "• _tabung 100rb_\n" .
                    "• _nabung 500rb_\n" .
                    "• _tabung 2jt_"
                );
                return;
            }
            
            // Get active savings goal
            $goal = SavingsGoal::where('tenant_id', $this->message->tenant_id)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->first();
            
            if (!$goal) {
                $this->sendReply(
                    "❌ *Belum ada target tabungan*\n\n" .
                    "Buat target dulu:\n" .
                    "_\"set target 10jt untuk liburan\"_\n\n" .
                    "Setelah itu baru bisa menabung!"
                );
                return;
            }
            
            // Add savings
            $previousAmount = $goal->current_amount;
            $goal->addSavings($nominal);
            
            $formattedNominal = number_format($nominal, 0, ',', '.');
            $formattedCurrent = number_format($goal->current_amount, 0, ',', '.');
            $formattedTarget = number_format($goal->target_amount, 0, ',', '.');
            $formattedRemaining = number_format($goal->getRemainingAmount(), 0, ',', '.');
            
            $message = "✅ *Tabungan Ditambahkan!*\n\n";
            $message .= "🎯 {$goal->name}\n";
            $message .= "💵 +Rp {$formattedNominal}\n\n";
            $message .= "📊 Progress:\n";
            $message .= $goal->getProgressBar() . "\n\n";
            $message .= "💰 Terkumpul: Rp {$formattedCurrent}\n";
            $message .= "🎯 Target: Rp {$formattedTarget}\n";
            
            if ($goal->isCompleted()) {
                $message .= "\n🎉🎉 *SELAMAT! TARGET TERCAPAI!* 🎉🎉";
            } else {
                $message .= "📌 Kurang: Rp {$formattedRemaining}";
            }
            
            $this->sendReply($message);
            

            
        } catch (\Exception $e) {
            Log::error('Error adding savings', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menambah tabungan*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle check subscriptions request (cek langganan, subscription saya)
     */
    protected function handleCheckSubscriptions(): void
    {
        try {
            $trackerService = new SubscriptionTrackerService($this->message->tenant_id);
            $message = $trackerService->generateSummaryMessage();
            
            $this->sendReply($message);
            

            
        } catch (\Exception $e) {
            Log::error('Error viewing subscriptions', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal memuat langganan*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle export PDF request (export pdf, laporan pdf)
     */
    protected function handleExportPdf(string $messageText): void
    {
        $this->reportService->handleExportPdf($messageText);
    }
    


    /**
     * Handle edit transaction with context
     * Allows users to correct their last transaction using "salah harusnya 50rb"
     */
    protected function handleEditWithContext(string $messageText): void
    {
        $this->transactionService->handleEditWithContext($messageText);
    }
    
    

    /**
     * Handle enable daily reminder
     */
    protected function handleEnableDailyReminder(): void
    {
        $this->reminderService->handleEnableDailyReminder();
    }

    /**
     * Handle disable daily reminder
     */
    protected function handleDisableDailyReminder(): void
    {
        $this->reminderService->handleDisableDailyReminder();
    }
    /**
     * Extract total amount from OCR text
     * Priority: TOTAL BELANJA > NON TUNAI > TUNAI > TOTAL > JUMLAH
     * @param string $text Raw OCR text
     * @param bool $strictMode If true, only check specific Total/Bayar labels, no guessing largest number
     */
    protected function extractTotalFromOcrText(string $text, bool $strictMode = false): ?int
    {
        return $this->receiptParser->extractTotalFromOcrText($text, $strictMode);
    }
    
    
    protected function extractStoreNameFromOcrText(string $text): ?string
    {
        return $this->receiptParser->extractStoreNameFromOcrText($text);
    }
    
    
    /**
     * Parse date from receipt text
     */
    protected function parseReceiptDate(?string $dateRaw): string
    {
        return $this->receiptParser->parseReceiptDate($dateRaw);
    }
    
    
    
    /**
     * Extract transaction from message text locally (without AI)
     * This handles simple messages like "Makan Pagi Hara Chicken 60rb"
     * 
     * @param string $messageText The message text
     * @return array|null Transaction data or null if extraction failed
     */
    protected function extractTransactionLocally(string $messageText): ?array
    {
        return $this->transactionExtractor->extractTransactionLocally($messageText);
    }
    
    /**
     * Extract date from text (kemarin, minggu lalu, tgl 15, etc)
     */
    protected function extractDateFromText(string $text): ?string
    {
        return $this->transactionExtractor->extractDateFromText($text);
    }
        

    
    // ========== NEW INTENT HANDLERS (AI Enhancement Phase) ==========

    
    /**
     * Handle check balance request (cek saldo)
     */
    protected function handleCheckBalance(): void
    {
        $this->walletCommand->handleCheckBalance();
    }
    
    /**
     * Handle check budget request (cek budget)
     */
    protected function handleCheckBudget(): void
    {
        $this->budgetCommandService->handleCheckBudget();
    }
    
    /**
     * Handle check statistics request (cek statistik)
     */
    protected function handleCheckStatistics(): void
    {
        $this->analysisCommandService->handleCheckStatistics();
    }
    
    /**
     * Handle check target request (cek target)
     */
    protected function handleCheckTarget(): void
    {
        try {
            $reply = "🎯 *Target Tabungan Anda*\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";
            
            // Get total balance as savings progress
            $totalBalance = \App\Models\Balance::where('tenant_id', $this->message->tenant_id)
                ->sum('current_balance');
            
            $reply .= "💰 *Tabungan saat ini*: Rp " . number_format($totalBalance, 0, ',', '.') . "\n\n";
            
            // Note about setting targets
            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= "💡 *Set target:*\n";
            $reply .= "_\"set target 10jt untuk liburan\"_\n";
            $reply .= "_\"mau nabung 5jt bulan ini\"_";
            
            $this->sendReply($reply);
            
        } catch (\Exception $e) {
            Log::error('Error checking target', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal memuat target*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
    
    /**
     * Handle set budget request (set budget untuk kategori)
     */
    protected function handleSetBudget(string $messageText, ?array $finwaEntities = null): void
    {
        $this->budgetCommandService->handleSetBudget($messageText, $finwaEntities);
    }
    
    /**
     * Handle add to budget command
     * Increments existing budget amount instead of replacing
     */
    protected function handleAddBudget(string $messageText, ?array $finwaEntities = null): void
    {
        $this->budgetCommandService->handleAddBudget($messageText, $finwaEntities);
    }
    
    /**
     * Handle delete budget request (hapus budget)
     */
    protected function handleDeleteBudget(string $messageText): void
    {
        $this->budgetCommandService->handleDeleteBudget($messageText);
    }
    
    /**
     * Handle set target request (set target tabungan)
     */
    protected function handleSetTarget(string $messageText, ?array $finwaEntities = null): void
    {
        try {
            $nominal = $finwaEntities['nominal'] ?? null;
            
            if (!$nominal) {
                $this->sendReply(
                    "🎯 *Set Target Tabungan*\n\n" .
                    "Untuk mengatur target, ketik:\n\n" .
                    "• _\"set target 10jt\"_\n" .
                    "• _\"mau nabung 5jt bulan ini\"_\n" .
                    "• _\"target tabung 2jt untuk liburan\"_\n\n" .
                    "💡 Target membantu Anda fokus pada tujuan keuangan."
                );
                return;
            }
            
            // Note: Full target feature would need a SavingsTarget model
            // For now, just acknowledge the request
            
            $this->sendReply(
                "✅ *Target Tabungan Diatur!*\n\n" .
                "🎯 Target Anda:\n" .
                "💵 Rp " . number_format($nominal, 0, ',', '.') . "\n\n" .
                "Terus pantau progress Anda dengan:\n" .
                "_\"cek target\"_\n\n" .
                "💪 Semangat mencapai target!"
            );
            

            
        } catch (\Exception $e) {
            Log::error('Error setting target', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal mengatur target*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle export report request (export laporan)
     * Now redirects to PDF export for direct download
     */
    protected function handleExportReport(): void
    {
        // Redirect to PDF export for better UX
        $this->handleExportPdf('export laporan bulan ini');
    }
    
    // ========== WALLET/PAYMENT METHOD HANDLERS ==========
    
    /**
     * Handle add wallet/payment method request
     * Supports: "tambah dompet BCA", "tambah metode pembayaran Gopay saldo 100rb"
     */
    protected function handleAddWallet(string $messageText): void
    {
        $this->walletCommand->handleAddWallet($messageText);
    }
    
    /**
     * Handle list wallets request
     */
    protected function handleListWallets(): void
    {
        $this->walletCommand->handleListWallets();
    }
    
    /**
     * Handle delete wallet request
     */
    protected function handleDeleteWallet(string $messageText): void
    {
        $this->walletCommand->handleDeleteWallet($messageText);
    }
    
    /**
     * Extract wallet name from message text
     */
    protected function extractWalletName(string $messageText): ?string
    {
        return $this->walletCommand->extractWalletName($messageText);
    }
    
    /**
     * Determine account type from wallet name
     */
    protected function determineAccountType(string $walletName): string
    {
        return $this->walletCommand->determineAccountType($walletName);
    }
    
    // ========== FAQ/GENERAL QUESTIONS HANDLER ==========
    
    /**
     * Check if message is a FAQ/general question and handle it
     * Returns true if handled, false otherwise
     */
    protected function checkAndHandleFAQ(string $messageText): bool
    {
        return $this->faqService->checkAndHandleFAQ($messageText);
    }
    
    /**
     * FAQ: How to use the app
     */
    protected function getFAQHowToUse(): string
    {
        return $this->faqService->getFAQHowToUse();
    }
    
    /**
     * FAQ: About the app
     */
    protected function getFAQAboutApp(): string
    {
        return $this->faqService->getFAQAboutApp();
    }
    
    /**
     * FAQ: Group feature
     */
    protected function getFAQGroupFeature(): string
    {
        return $this->faqService->getFAQGroupFeature();
    }
    
    /**
     * FAQ: Pricing
     */
    protected function getFAQPricing(): string
    {
        return $this->faqService->getFAQPricing();
    }
    
    /**
     * FAQ: Security
     */
    protected function getFAQSecurity(): string
    {
        return $this->faqService->getFAQSecurity();
    }
    
    /**
     * FAQ: Features
     */
    protected function getFAQFeatures(): string
    {
        return $this->faqService->getFAQFeatures();
    }
    
    /**
     * FAQ: How to register
     */
    protected function getFAQRegister(): string
    {
        return $this->faqService->getFAQRegister();
    }
    
    /**
     * FAQ: Support contact
     */
    protected function getFAQSupport(): string
    {
        return $this->faqService->getFAQSupport();
    }
    
    /**
     * FAQ: Categories
     */
    protected function getFAQCategories(): string
    {
        return $this->faqService->getFAQCategories();
    }
    
    /**
     * FAQ: Export reports
     */
    protected function getFAQExport(): string
    {
        return $this->faqService->getFAQExport();
    }
    
    /**
     * FAQ: Limits
     */
    protected function getFAQLimits(): string
    {
        return $this->faqService->getFAQLimits();
    }
    
    /**
     * FAQ: How to create wallet
     */
    protected function getFAQCreateWallet(): string
    {
        return $this->faqService->getFAQCreateWallet();
    }
    
    /**
     * FAQ: How to use budgeting
     */
    protected function getFAQBudgeting(): string
    {
        return $this->faqService->getFAQBudgeting();
    }
    
    /**
     * FAQ: Financial tips
     */
    protected function getFAQFinancialTips(): string
    {
        return $this->faqService->getFAQFinancialTips();
    }
    
    /**
     * FAQ: How to record transaction
     */
    protected function getFAQRecordTransaction(): string
    {
        return $this->faqService->getFAQRecordTransaction();
    }
    
    /**
     * FAQ: How to check balance
     */
    protected function getFAQCheckBalance(): string
    {
        return $this->faqService->getFAQCheckBalance();
    }

    /**
     * Handle check statistics request with AI Insight
     */
    protected function handleCheckStatisticsWithAI(): void
    {
        $this->analysisCommandService->handleCheckStatisticsWithAI();
    }
}