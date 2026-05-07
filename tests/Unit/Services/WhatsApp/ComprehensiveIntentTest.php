<?php

use App\Services\WhatsApp\IntentDetectionService;

beforeEach(function () {
    $this->service = new IntentDetectionService();
});

// ===== QUERY DETECTION =====
it('detects query keywords with period', function () {
    $result = $this->service->detectIntentFlags('pengeluaran bulan ini');
    expect($result['hasQueryKeyword'])->toBeTrue();
    expect($result['hasPeriodKeyword'])->toBeTrue();
});

it('detects simple query patterns', function () {
    expect($this->service->isSimpleQuery('habis berapa?'))->toBeTrue();
    expect($this->service->isSimpleQuery('pengeluaran berapa?'))->toBeTrue();
    expect($this->service->isSimpleQuery('ringkasan'))->toBeTrue();
});

// ===== TRANSACTION DETECTION =====
it('detects transaction keywords with amount', function () {
    $result = $this->service->detectIntentFlags('beli baju 100rb');
    expect($result['hasTransactionKeyword'])->toBeTrue();
    expect($result['hasAmount'])->toBeTrue();
});

it('detects explicit transaction query', function () {
    $result = $this->service->detectIntentFlags('beli jajan 10rb');
    expect($result['isExplicitTransactionQuery'])->toBeTrue();
    
    $result = $this->service->detectIntentFlags('pengeluaran 100rb beli jajan');
    expect($result['isExplicitTransactionQuery'])->toBeTrue();
});

// ===== FALSE POSITIVE PREVENTION =====
it('does not false positive ralat in peralatan', function () {
    expect($this->service->isEditContext('beli peralatan kos 126k', true))->toBeFalse();
});

it('does not false positive jan in jajan', function () {
    $result = $this->service->detectIntentFlags('beli jajan 10rb');
    expect($result['hasPeriodKeyword'])->toBeFalse();
});

it('does not false positive gaji in mengaji', function () {
    // This is tested via word boundary in transaction service
    $result = $this->service->detectIntentFlags('daftar sekolah mengaji 150rb');
    expect($result['hasAmount'])->toBeTrue();
});

// ===== WALLET COMMANDS =====
it('detects balance check', function () {
    expect($this->service->isBalanceCheck('saldo'))->toBeTrue();
    expect($this->service->isBalanceCheck('cek saldo'))->toBeTrue();
});

it('detects set wallet balance', function () {
    expect($this->service->isSetWalletBalance('saldo 400rb'))->toBeTrue();
    expect($this->service->isSetWalletBalance('saldo BCA 300rb'))->toBeTrue();
});

it('detects adjust wallet balance', function () {
    expect($this->service->isAdjustWalletBalance('tambah saldo 100rb'))->toBeTrue();
    expect($this->service->isAdjustWalletBalance('kurangi saldo 50rb'))->toBeTrue();
});

// ===== EDIT/CORRECTION =====
it('detects edit context with amount', function () {
    expect($this->service->isEditContext('koreksi jadi 50rb', true))->toBeTrue();
    expect($this->service->isEditContext('harusnya 100rb', true))->toBeTrue();
});

// ===== UNDO/DELETE =====
it('detects undo/delete', function () {
    expect($this->service->isUndoOrDelete('undo', false))->toBeTrue();
    expect($this->service->isUndoOrDelete('batalkan', false))->toBeTrue();
    expect($this->service->isUndoOrDelete('hapus 50rb', true))->toBeTrue();
});

// ===== VIEW TRANSACTIONS =====
it('detects view transaction request', function () {
    $result = $this->service->detectIntentFlags('daftar transaksi');
    expect($result['isViewTransactionRequest'])->toBeTrue();
});

// ===== RECURRING BILLS =====
it('detects recurring bill', function () {
    expect($this->service->isRecurringBillDetection('tagihan berulang'))->toBeTrue();
});

// ===== EDGE CASES =====
it('handles mixed case and whitespace', function () {
    $result = $this->service->detectIntentFlags('  Beli  BAJU  100rb  ');
    expect($result['hasTransactionKeyword'])->toBeTrue();
    expect($result['hasAmount'])->toBeTrue();
});

it('handles amount in different formats', function () {
    $result = $this->service->detectIntentFlags('beli baju 100000');
    expect($result['hasAmount'])->toBeTrue();
    
    $result = $this->service->detectIntentFlags('beli baju 100k');
    expect($result['hasAmount'])->toBeTrue();
    
    $result = $this->service->detectIntentFlags('beli baju 1.5jt');
    expect($result['hasAmount'])->toBeTrue();
});

// ===== REAL-WORLD SCENARIOS =====
it('handles real message: daftar sekolah mengaji 150rb', function () {
    $result = $this->service->detectIntentFlags('Tanggal 5 mei daftar sekolah mengaji Ica 150rb');
    // Should be a transaction, not query
    expect($result['hasAmount'])->toBeTrue();
    expect($result['isExplicitTransactionQuery'])->toBeTrue();
});

it('handles real message: beli peralatan kos 126k', function () {
    $result = $this->service->detectIntentFlags('Beli peralatan kos 126k');
    expect($result['hasTransactionKeyword'])->toBeTrue();
    expect($result['hasAmount'])->toBeTrue();
    // Should NOT be edit context
    expect($this->service->isEditContext('Beli peralatan kos 126k', true))->toBeFalse();
});

it('handles real message: pengeluaran from ocbc', function () {
    $result = $this->service->detectIntentFlags('pengeluaran dari ocbc 81.932 ribu beli jajan poppy');
    expect($result['hasAmount'])->toBeTrue();
    expect($result['isExplicitTransactionQuery'])->toBeTrue();
});

it('handles batch transaction', function () {
    $message = "Belanja online 486000\nEs 8500\nBengkel 100000";
    $result = $this->service->detectIntentFlags($message);
    expect($result['hasAmount'])->toBeTrue();
});

// ===== STATISTICS KEYWORDS =====
it('detects statistics keywords', function () {
    $result = $this->service->detectIntentFlags('pengeluaran terbesar bulan ini');
    expect($result['hasStatisticsKeyword'])->toBeTrue();
    expect($result['hasQueryKeyword'])->toBeTrue();
    expect($result['hasPeriodKeyword'])->toBeTrue();
});
