<?php

use App\Services\WhatsApp\IntentDetectionService;
use App\Services\WhatsApp\MessageRouterService;

// Mock classes (simplified - in real app would use Mockery)
class MockTransactionService {}
class MockBatchTransactionService {}
class MockWalletCommandService {}
class MockFinancialQueryHandler {}
class MockFinancialQueryService {}
class MockConversationContextService {}
class MockAIProcessorService {}
class MockCategoryCorrectionService {}

beforeEach(function () {
    $this->intentService = new IntentDetectionService();
    
    // For router, we'll test routing logic separately
});

it('detects correct routing for simple transaction', function () {
    $result = $this->intentService->detectIntentFlags('beli baju 100rb');
    
    expect($result['hasTransactionKeyword'])->toBeTrue();
    expect($result['hasAmount'])->toBeTrue();
    expect($result['hasQueryKeyword'])->toBeFalse();
});

it('detects correct routing for query with period', function () {
    $result = $this->intentService->detectIntentFlags('pengeluaran bulan ini');
    
    expect($result['hasQueryKeyword'])->toBeTrue();
    expect($result['hasPeriodKeyword'])->toBeTrue();
});

it('detects correct routing for edit context', function () {
    expect($this->intentService->isEditContext('koreksi jadi 50rb', true))->toBeTrue();
    expect($this->intentService->isEditContext('beli baju 100rb', true))->toBeFalse();
});

it('does not false positive on word boundaries', function () {
    // These should NOT be detected as edit
    expect($this->intentService->isEditContext('beli peralatan kos 126k', true))->toBeFalse();
    
    // These should NOT be detected as period
    $result = $this->intentService->detectIntentFlags('beli jajan 10rb');
    expect($result['hasPeriodKeyword'])->toBeFalse();
});

it('detects batch transaction format', function () {
    $result = $this->intentService->detectIntentFlags("Belanja online 486000\nEs 8500\nBengkel 100000");
    
    expect($result['hasAmount'])->toBeTrue();
    // Batch detection is done by BatchTransactionService
});

it('handles OCR path correctly', function () {
    $result = $this->intentService->detectIntentFlags('foto struk belanja', true);
    
    // OCR has different flow
    expect($result['textLower'])->toContain('foto struk belanja');
});

it('detects balance check correctly', function () {
    expect($this->intentService->isBalanceCheck('saldo'))->toBeTrue();
    expect($this->intentService->isBalanceCheck('cek saldo'))->toBeTrue();
    expect($this->intentService->isBalanceCheck('saldo 400rb'))->toBeFalse();
});

it('detects wallet commands', function () {
    expect($this->intentService->isSetWalletBalance('saldo 400rb'))->toBeTrue();
    expect($this->intentService->isAdjustWalletBalance('tambah saldo 100rb'))->toBeTrue();
});

it('detects undo/delete correctly', function () {
    expect($this->intentService->isUndoOrDelete('undo', false))->toBeTrue();
    expect($this->intentService->isUndoOrDelete('hapus 50rb', true))->toBeTrue();
});

it('handles mixed case input', function () {
    $result = $this->intentService->detectIntentFlags('  Beli  BAJU  100rb  ');
    
    expect($result['hasTransactionKeyword'])->toBeTrue();
    expect($result['hasAmount'])->toBeTrue();
});

it('prioritizes transaction over query when both keywords present', function () {
    // "pengeluaran 100rb beli jajan" has both query keyword AND action verb + amount
    $result = $this->intentService->detectIntentFlags('pengeluaran 100rb beli jajan');
    
    expect($result['hasQueryKeyword'])->toBeTrue();
    expect($result['hasAmount'])->toBeTrue();
    expect($result['isExplicitTransactionQuery'])->toBeTrue();
});
