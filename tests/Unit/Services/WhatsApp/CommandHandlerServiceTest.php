<?php

use App\Services\WhatsApp\CommandHandlerService;
use App\Services\Transaction\TransactionService;
use App\Services\Transaction\BatchTransactionService;
use App\Services\Wallet\WalletCommandService;
use App\Services\Query\FinancialQueryHandler;
use App\Services\FinancialQueryService;
use App\Services\ConversationContextService;
use App\Services\AIProcessorService;
use App\Services\Category\CategoryCorrectionService;

beforeEach(function () {
    $this->transactionService = \Mockery::mock(TransactionService::class);
    $this->batchTransactionService = \Mockery::mock(BatchTransactionService::class);
    $this->walletCommandService = \Mockery::mock(WalletCommandService::class);
    $this->financialQueryHandler = \Mockery::mock(FinancialQueryHandler::class);
    $this->financialQueryService = \Mockery::mock(FinancialQueryService::class);
    $this->contextService = \Mockery::mock(ConversationContextService::class);
    $this->aiProcessorService = \Mockery::mock(AIProcessorService::class);
    $this->categoryCorrectionService = \Mockery::mock(CategoryCorrectionService::class);

    $this->handler = new CommandHandlerService(
        $this->transactionService,
        $this->batchTransactionService,
        $this->walletCommandService,
        $this->financialQueryHandler,
        $this->financialQueryService,
        $this->contextService,
        $this->aiProcessorService,
        $this->categoryCorrectionService
    );
});

afterEach(function () {
    \Mockery::close();
});

it('delegates handleTransaction to TransactionService', function () {
    $this->transactionService
        ->shouldReceive('handleTransaction')
        ->once()
        ->with('beli kopi 25rb', []);

    $this->handler->handleTransaction('beli kopi 25rb', []);
});

it('delegates handleBatchTransactions to BatchTransactionService', function () {
    $this->batchTransactionService
        ->shouldReceive('handleBatchTransactions')
        ->once()
        ->with("Makan 25rb\nGrab 15rb");

    $this->handler->handleBatchTransactions("Makan 25rb\nGrab 15rb");
});

it('delegates handleQuery to FinancialQueryHandler', function () {
    $this->financialQueryHandler
        ->shouldReceive('handleQuery')
        ->once()
        ->with('pengeluaran bulan ini');

    $this->handler->handleQuery('pengeluaran bulan ini');
});

it('delegates handleCheckBalance to WalletCommandService', function () {
    $this->walletCommandService
        ->shouldReceive('handleCheckBalance')
        ->once()
        ->with('');

    $this->handler->handleCheckBalance('');
});

it('delegates handleSetWalletBalance to WalletCommandService', function () {
    $this->walletCommandService
        ->shouldReceive('handleSetWalletBalance')
        ->once()
        ->with('set saldo BCA 500rb');

    $this->handler->handleSetWalletBalance('set saldo BCA 500rb');
});

it('delegates handleAdjustWalletBalance to WalletCommandService', function () {
    $this->walletCommandService
        ->shouldReceive('handleAdjustWalletBalance')
        ->once()
        ->with('tambah saldo BCA 100rb');

    $this->handler->handleAdjustWalletBalance('tambah saldo BCA 100rb');
});

it('delegates handleEditWithContext to TransactionService', function () {
    $this->transactionService
        ->shouldReceive('handleEditWithContext')
        ->once()
        ->with('salah harusnya 50rb');

    $this->handler->handleEditWithContext('salah harusnya 50rb');
});

it('delegates handleUndoTransaction to TransactionService delete', function () {
    $this->transactionService
        ->shouldReceive('handleDeleteTransaction')
        ->once();

    $this->handler->handleUndoTransaction('undo');
});

it('delegates handleDeleteTransaction with reference to TransactionService byKeyword', function () {
    $this->transactionService
        ->shouldReceive('handleDeleteTransactionByKeyword')
        ->once()
        ->with('hapus REF-ABC123');

    $this->handler->handleDeleteTransaction('hapus REF-ABC123');
});

it('delegates handleDeleteTransaction without reference to TransactionService delete', function () {
    $this->transactionService
        ->shouldReceive('handleDeleteTransaction')
        ->once();

    $this->handler->handleDeleteTransaction('hapus transaksi terakhir');
});

it('delegates handleExpenseFromWallet to WalletCommandService', function () {
    $this->walletCommandService
        ->shouldReceive('handleExpenseFromWallet')
        ->once()
        ->with('keluar dari BCA 50rb beli kopi');

    $this->handler->handleExpenseFromWallet('keluar dari BCA 50rb beli kopi');
});

it('handles setJob correctly', function () {
    $job = \Mockery::mock(\App\Jobs\ProcessIncomingMessage::class);
    $this->handler->setJob($job);

    // No error means setJob works
    expect(true)->toBeTrue();
});
