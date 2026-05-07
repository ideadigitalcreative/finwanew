<?php

namespace App\Services\WhatsApp;

use App\Models\Message;
use App\Services\Transaction\BatchTransactionService;
use App\Services\Transaction\TransactionService;
use App\Services\Wallet\WalletCommandService;
use App\Services\Query\FinancialQueryHandler;
use App\Services\FinancialQueryService;
use App\Services\ConversationContextService;
use App\Services\AIProcessorService;
use App\Services\Category\CategoryCorrectionService;
use Illuminate\Support\Facades\Log;

class MessageRouterService
{
    protected CommandHandlerService $commandHandler;
    protected IntentDetectionService $intentDetection;

    public function __construct(CommandHandlerService $commandHandler)
    {
        $this->commandHandler = $commandHandler;
        $this->intentDetection = new IntentDetectionService();
    }

    /**
     * Route the message to appropriate handler based on intent flags.
     *
     * @param  Message  $message
     * @param  string   $messageText
     * @param  array    $intentFlags  From IntentDetectionService::detectIntentFlags()
     * @param  array    $finwaEntities AI entities (optional)
     * @return string   The handler that was called
     */
    public function route(
        Message $message,
        string $messageText,
        array $intentFlags,
        array $finwaEntities = []
    ): string {
        $hasQueryKeyword = $intentFlags['hasQueryKeyword'];
        $hasPeriodKeyword = $intentFlags['hasPeriodKeyword'];
        $hasTransactionKeyword = $intentFlags['hasTransactionKeyword'];
        $hasAmount = $intentFlags['hasAmount'];
        $hasStatisticsKeyword = $intentFlags['hasStatisticsKeyword'];
        $isViewTransactionRequest = $intentFlags['isViewTransactionRequest'];
        $isExplicitTransactionQuery = $intentFlags['isExplicitTransactionQuery'];
        $isBatchFormat = $intentFlags['isBatchFormat'];
        $textLower = $intentFlags['textLower'];

        // FAST PATH 1: Query with period (e.g., "pengeluaran bulan ini")
        // BUT NOT "pengeluaran terbesar bulan ini" - that needs statistics
        if ($hasQueryKeyword && $hasPeriodKeyword && ! $hasStatisticsKeyword && ! $isViewTransactionRequest) {
            $this->commandHandler->handleQuery($messageText);

            return 'query';
        }

        // FAST PATH 1.1: Simple informal queries (default to today)
        if ($this->intentDetection->isSimpleQuery($messageText)) {
            $this->commandHandler->handleQuery($messageText.' hari ini');

            return 'simple_query';
        }

        // FAST PATH 1.2: Balance check (e.g., "saldo", "cek saldo")
        if ($this->intentDetection->isBalanceCheck($messageText)) {
            $this->commandHandler->handleCheckBalance($messageText);

            return 'balance_check';
        }

        // FAST PATH 1.25: Set wallet balance (e.g., "saldo 400rb")
        if ($this->intentDetection->isSetWalletBalance($messageText)) {
            $this->commandHandler->handleSetWalletBalance($messageText);

            return 'set_balance';
        }

        // FAST PATH 1.26: Adjust wallet balance (e.g., "tambah saldo 100rb")
        if ($this->intentDetection->isAdjustWalletBalance($messageText)) {
            $this->commandHandler->handleAdjustWalletBalance($messageText);

            return 'adjust_balance';
        }

        // FAST PATH 1.5: View transactions (e.g., "daftar transaksi")
        if ($isViewTransactionRequest) {
            $this->commandHandler->handleViewTransactions($messageText);

            return 'view_transactions';
        }

        // FAST PATH 1.6: Edit context (e.g., "koreksi jadi 50rb")
        if ($this->intentDetection->isEditContext($messageText, $hasAmount)) {
            $this->commandHandler->handleEditWithContext($messageText);

            return 'edit_context';
        }

        // FAST PATH 1.7: Undo/Delete (e.g., "undo", "hapus terakhir")
        if ($this->intentDetection->isUndoOrDelete($messageText, $hasAmount)) {
            if (preg_match('/\b(undo|batalkan|gak jadi|nggak jadi|gak jadilah|nggak jadilah)\b/u', $textLower)) {
                $this->commandHandler->handleUndoTransaction($messageText);

                return 'undo_transaction';
            }
            if ($hasAmount && preg_match('/\b(hapus|delete|hilangkan)\b/u', $textLower)) {
                $this->commandHandler->handleDeleteTransaction($messageText);

                return 'delete_transaction';
            }
        }

        // FAST PATH 1.8: Recurring bill detection
        if ($this->intentDetection->isRecurringBillDetection($messageText)) {
            $this->commandHandler->handleRecurringBillDetection($messageText);

            return 'recurring_bill_detection';
        }

        // FAST PATH 1.9: Wallet expense from specific wallet (e.g., "pengeluaran dari BCA 100rb")
        // Must check before general transaction handling
        $walletKeywords = ['pengeluaran dari', 'pemasukan dari', 'dari'];
        $isWalletCommand = false;
        foreach ($walletKeywords as $walletKeyword) {
            if (preg_match('/\b'.preg_quote($walletKeyword, '/').'\b/u', $textLower)) {
                $isWalletCommand = true;
                break;
            }
        }
        if ($isWalletCommand && $hasAmount) {
            if (preg_match('/\b(pengeluaran|keluar|keluarkan)\b/u', $textLower)) {
                $this->commandHandler->handleExpenseFromWallet($messageText);

                return 'expense_from_wallet';
            }
            if (preg_match('/\b(pemasukan|masuk|masukan)\b/u', $textLower)) {
                $this->commandHandler->handleIncomeToWallet($messageText);

                return 'income_to_wallet';
            }
        }

        // FAST PATH 1.95: Quick single-transaction pattern (action word + nominal + description)
        // e.g., "Beli baju 100rb", "Bayar listrik 500k"
        if ($hasTransactionKeyword && $hasAmount && ! $hasQueryKeyword) {
            // Check if NOT a query with period (which was already handled above)
            // This catches: "Beli peralatan kos 126k", "Makan 25rb"
            $this->commandHandler->handleTransaction($messageText, $finwaEntities);

            return 'transaction';
        }

        // FAST PATH 2: Query without period (e.g., "pengeluaran 100rb beli jajan")
        // This is a transaction masquerading as query keyword
        if ($hasQueryKeyword && $hasAmount && $isExplicitTransactionQuery) {
            Log::info('Query keyword overridden: explicit transaction query detected', [
                'message' => $messageText,
            ]);
            $this->commandHandler->handleTransaction($messageText, $finwaEntities);

            return 'transaction_override';
        }

        // FAST PATH 2.5: Batch transaction format
        if ($isBatchFormat) {
            $this->commandHandler->handleBatchTransactions($messageText);

            return 'batch_transaction';
        }

        // FAST PATH 3: OCR Image (handled upstream in ProcessIncomingMessage)

        // FALLBACK: Needs AI classification
        return 'needs_ai';
    }

    /**
     * Delegate to AI classification and handle the result.
     */
    public function handleWithAI(
        Message $message,
        string $messageText,
        array $intentFlags,
        array $finwaEntities = []
    ): string {
        $textLower = $intentFlags['textLower'];
        $hasAmount = $intentFlags['hasAmount'];
        $hasQueryKeyword = $intentFlags['hasQueryKeyword'];
        $finwaIntent = $finwaEntities['_finwa_intent'] ?? null;

        // GUARD: Check if message contains transaction keywords but AI classified as koreksi/edit
        // This prevents "Beli peralatan kos 126k" from triggering edit/correction
        if ($hasAmount) {
            $txKeywords = ['beli', 'bayar', 'naik', 'jajan', 'makan', 'minum', 'ngopi', 'isi', 'topup', 'transfer', 'ongkos', 'parkir', 'tol', 'ojek', 'grab', 'gojek'];
            $hasTxKeyword = false;
            foreach($txKeywords as $k) {
                if (str_contains($textLower, " $k ") || str_starts_with($textLower, "$k ")) {
                    $hasTxKeyword = true;
                    break;
                }
            }
            
            // If it has transaction keywords and AI classified as correction/edit, override to transaction
            if ($hasTxKeyword && in_array($finwaIntent, ['koreksi_transaksi', 'edit_transaksi'])) {
                Log::info('Overriding AI intent from correction to transaction due to keyword detection', [
                    'original_intent' => $finwaIntent,
                    'message' => $messageText
                ]);
                $finwaIntent = 'catat_pengeluaran';
                $finwaEntities['_finwa_intent'] = 'catat_pengeluaran';
            }
        }

        // Map AI intent to handler
        switch ($finwaIntent) {
            case 'catat_pengeluaran':
            case 'catat_pemasukan':
            case 'ocr_receipt':
                $this->commandHandler->handleTransaction($messageText, $finwaEntities);

                return 'transaction_ai';
            case 'lihat_transaksi':
                $this->commandHandler->handleViewTransactions($messageText);

                return 'view_transactions_ai';
            case 'hapus_transaksi':
                if ($hasAmount) {
                    $this->commandHandler->handleDeleteTransaction($messageText);

                    return 'delete_transaction_ai';
                }
                break;
            case 'koreksi_transaksi':
                if ($hasAmount) {
                    $this->commandHandler->handleEditWithContext($messageText);

                    return 'edit_transaction_ai';
                }
                break;
            case 'query':
                // Safety: if AI classified as query but message has amount, it's likely a misclassified transaction
                if ($hasAmount && ($finwaIntent === 'unknown' || ! $finwaIntent)) {
                    Log::info('Query intent overridden to transaction: message has nominal and original intent was unknown', [
                        'message' => $messageText,
                        'original_intent' => $finwaIntent,
                    ]);
                    $this->commandHandler->handleTransaction($messageText, $finwaEntities);

                    return 'transaction_override_unknown';
                }
                $this->commandHandler->handleQuery($messageText);

                return 'query_ai';
            case 'unknown':
                // Unknown intent with amount → try as transaction
                if ($hasAmount) {
                    $this->commandHandler->handleTransaction($messageText, $finwaEntities);

                    return 'transaction_unknown';
                }
                // Unknown without amount → treat as query
                $this->commandHandler->handleQuery($messageText);

                return 'query_unknown';
            default:
                // Default fallback
                if ($hasAmount) {
                    $this->commandHandler->handleTransaction($messageText, $finwaEntities);

                    return 'transaction_default';
                }
                $this->commandHandler->handleQuery($messageText);

                return 'query_default';
        }

        return 'unhandled';
    }

    // Placeholder methods - actual implementations remain in ProcessIncomingMessage
    // These will be called via the job context
    protected function handleQuery(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage for now
        // Will be fully extracted in next refactoring step
    }

    protected function handleCheckBalance(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleSetWalletBalance(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleAdjustWalletBalance(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleViewTransactions(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleEditWithContext(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleUndoTransaction(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleDeleteTransaction(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleRecurringBillDetection(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleExpenseFromWallet(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleIncomeToWallet(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleTransaction(string $messageText, array $finwaEntities = []): void
    {
        // Delegated to ProcessIncomingMessage
    }

    protected function handleBatchTransactions(string $messageText): void
    {
        // Delegated to ProcessIncomingMessage
    }
}
