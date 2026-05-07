<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Services\Transaction\TransactionService;
use App\Services\Transaction\BatchTransactionService;
use App\Services\Wallet\WalletCommandService;
use App\Services\Query\FinancialQueryHandler;
use App\Services\FinancialQueryService;
use App\Services\ConversationContextService;
use App\Services\AIProcessorService;
use App\Services\Category\CategoryCorrectionService;
use Illuminate\Support\Facades\Log;

class CommandHandlerService
{
    protected TransactionService $transactionService;
    protected BatchTransactionService $batchTransactionService;
    protected WalletCommandService $walletCommandService;
    protected FinancialQueryHandler $financialQueryHandler;
    protected FinancialQueryService $financialQueryService;
    protected ConversationContextService $contextService;
    protected AIProcessorService $aiProcessorService;
    protected CategoryCorrectionService $categoryCorrectionService;
    protected ProcessIncomingMessage $job;

    public function __construct(
        TransactionService $transactionService,
        BatchTransactionService $batchTransactionService,
        WalletCommandService $walletCommandService,
        FinancialQueryHandler $financialQueryHandler,
        FinancialQueryService $financialQueryService,
        ConversationContextService $contextService,
        AIProcessorService $aiProcessorService,
        CategoryCorrectionService $categoryCorrectionService
    ) {
        $this->transactionService = $transactionService;
        $this->batchTransactionService = $batchTransactionService;
        $this->walletCommandService = $walletCommandService;
        $this->financialQueryHandler = $financialQueryHandler;
        $this->financialQueryService = $financialQueryService;
        $this->contextService = $contextService;
        $this->aiProcessorService = $aiProcessorService;
        $this->categoryCorrectionService = $categoryCorrectionService;
    }

    /**
     * Set the job instance for callback methods.
     */
    public function setJob(ProcessIncomingMessage $job): void
    {
        $this->job = $job;
    }

    /**
     * Handle transaction (expense/income).
     */
    public function handleTransaction(string $messageText, array $finwaEntities = []): void
    {
        $this->transactionService->handleTransaction($messageText, $finwaEntities);
    }

    /**
     * Handle batch transactions.
     */
    public function handleBatchTransactions(string $messageText): void
    {
        $this->batchTransactionService->handleBatchTransactions($messageText);
    }

    /**
     * Handle financial query.
     */
    public function handleQuery(string $messageText): void
    {
        $this->financialQueryHandler->handleQuery($messageText);
    }

    /**
     * Handle check balance.
     */
    public function handleCheckBalance(string $messageText): void
    {
        $this->walletCommandService->handleCheckBalance($messageText);
    }

    /**
     * Handle set wallet balance.
     */
    public function handleSetWalletBalance(string $messageText): void
    {
        $this->walletCommandService->handleSetWalletBalance($messageText);
    }

    /**
     * Handle adjust wallet balance.
     */
    public function handleAdjustWalletBalance(string $messageText): void
    {
        $this->walletCommandService->handleAdjustWalletBalance($messageText);
    }

    /**
     * Handle view transactions.
     */
    public function handleViewTransactions(string $messageText): void
    {
        $this->transactionService->handleViewTransactions();
    }

    /**
     * Handle edit with context (correction).
     */
    public function handleEditWithContext(string $messageText): void
    {
        $this->transactionService->handleEditWithContext($messageText);
    }

    /**
     * Handle undo transaction.
     */
    public function handleUndoTransaction(string $messageText): void
    {
        // TransactionService::handleDeleteTransaction() handles undo (no params)
        $this->transactionService->handleDeleteTransaction();
    }

    /**
     * Handle delete transaction.
     */
    public function handleDeleteTransaction(string $messageText): void
    {
        // Check if message contains a transaction reference ID
        if (preg_match('/(?:#|REF-?|TRX-?|TRF-?|TRB-?)([A-Z0-9]{4,12})/i', $messageText, $matches)) {
            $this->transactionService->handleDeleteTransactionByKeyword($messageText);
        } else {
            // Delete last transaction
            $this->transactionService->handleDeleteTransaction();
        }
    }

    /**
     * Handle recurring bill detection.
     */
    public function handleRecurringBillDetection(string $messageText): void
    {
        // This is handled by the DetectRecurringBills command
        // For now, just log that it was requested
        Log::info('Recurring bill detection requested', ['message' => $messageText]);
    }

    /**
     * Handle expense from specific wallet.
     */
    public function handleExpenseFromWallet(string $messageText): void
    {
        // Parse wallet and amount from message, then call appropriate method
        // For now, delegate to wallet command service with proper parsing
        $this->walletCommandService->handleExpenseFromWallet($messageText);
    }

    /**
     * Handle income to specific wallet.
     */
    public function handleIncomeToWallet(string $messageText): void
    {
        // For now, log that this needs implementation
        Log::info('Income to wallet requested', ['message' => $messageText]);
    }
}
