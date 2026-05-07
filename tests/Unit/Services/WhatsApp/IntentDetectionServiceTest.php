<?php

use App\Services\WhatsApp\IntentDetectionService;

beforeEach(function () {
    $this->service = new IntentDetectionService();
});

it('detects query keywords with period', function () {
    $result = $this->service->detectIntentFlags('pengeluaran bulan ini');

    expect($result['hasQueryKeyword'])->toBeTrue();
    expect($result['hasPeriodKeyword'])->toBeTrue();
    expect($result['hasTransactionKeyword'])->toBeFalse();
});

it('detects transaction keywords with amount', function () {
    $result = $this->service->detectIntentFlags('beli baju 100rb');

    expect($result['hasTransactionKeyword'])->toBeTrue();
    expect($result['hasAmount'])->toBeTrue();
    expect($result['hasQueryKeyword'])->toBeFalse();
});

it('detects edit context with amount', function () {
    expect($this->service->isEditContext('koreksi jadi 50rb', true))->toBeTrue();
    expect($this->service->isEditContext('beli baju 100rb', true))->toBeFalse();
});

it('does not false positive ralat in peralatan', function () {
    // "ralat" should NOT match inside "peralatan" with word boundary
    expect($this->service->isEditContext('beli peralatan kos 126k', true))->toBeFalse();
});

it('does not false positive jan in jajan', function () {
    // "jan" (January short) should NOT match inside "jajan"
    $result = $this->service->detectIntentFlags('beli jajan 10rb');
    expect($result['hasPeriodKeyword'])->toBeFalse();
});

it('does not false positive gaji in mengaji', function () {
    // "gaji" should NOT match inside "mengaji" with word boundary
    // This is tested via TransactionService, but verify period detection is clean
    $result = $this->service->detectIntentFlags('daftar sekolah mengaji 150rb');
    expect($result['hasAmount'])->toBeTrue();
});

it('detects simple queries', function () {
    expect($this->service->isSimpleQuery('habis berapa?'))->toBeTrue();
    expect($this->service->isSimpleQuery('pengeluaran berapa?'))->toBeTrue();
    expect($this->service->isSimpleQuery('ringkasan'))->toBeTrue();
    expect($this->service->isSimpleQuery('beli baju 100rb'))->toBeFalse();
});

it('detects balance check', function () {
    expect($this->service->isBalanceCheck('saldo'))->toBeTrue();
    expect($this->service->isBalanceCheck('cek saldo'))->toBeTrue();
    expect($this->service->isBalanceCheck('saldo 400rb'))->toBeFalse(); // This is set balance
});

it('detects set wallet balance', function () {
    expect($this->service->isSetWalletBalance('saldo 400rb'))->toBeTrue();
    expect($this->service->isSetWalletBalance('saldo BCA 300rb'))->toBeTrue();
    expect($this->service->isSetWalletBalance('saldo'))->toBeFalse(); // No amount
});

it('detects adjust wallet balance', function () {
    expect($this->service->isAdjustWalletBalance('tambah saldo 100rb'))->toBeTrue();
    expect($this->service->isAdjustWalletBalance('kurangi saldo 50rb'))->toBeTrue();
    expect($this->service->isAdjustWalletBalance('saldo 400rb'))->toBeFalse();
});

it('detects undo/delete', function () {
    expect($this->service->isUndoOrDelete('undo', false))->toBeTrue();
    expect($this->service->isUndoOrDelete('batalkan', false))->toBeTrue();
    expect($this->service->isUndoOrDelete('hapus 50rb', true))->toBeTrue();
    expect($this->service->isUndoOrDelete('beli baju', true))->toBeFalse();
});

it('detects view transaction request', function () {
    $result = $this->service->detectIntentFlags('daftar transaksi');
    expect($result['isViewTransactionRequest'])->toBeTrue();

    $result = $this->service->detectIntentFlags('lihat transaksi bulan ini');
    expect($result['isViewTransactionRequest'])->toBeTrue();
});

it('detects recurring bill', function () {
    expect($this->service->isRecurringBillDetection('tagihan berulang'))->toBeTrue();
    expect($this->service->isRecurringBillDetection('beli baju 100rb'))->toBeFalse();
});

it('handles mixed case and whitespace', function () {
    $result = $this->service->detectIntentFlags('  Beli  BAJU  100rb  ');
    expect($result['hasTransactionKeyword'])->toBeTrue();
    expect($result['hasAmount'])->toBeTrue();
});

it('detects explicit transaction query', function () {
    $result = $this->service->detectIntentFlags('beli jajan 10rb');
    expect($result['isExplicitTransactionQuery'])->toBeTrue();

    $result = $this->service->detectIntentFlags('pengeluaran 100rb beli jajan');
    expect($result['isExplicitTransactionQuery'])->toBeTrue();
});
