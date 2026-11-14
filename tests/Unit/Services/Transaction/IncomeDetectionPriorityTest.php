<?php

use App\Services\Transaction\TransactionExtractorService;

/*
|--------------------------------------------------------------------------
| Income Detection Priority Tests
|--------------------------------------------------------------------------
|
| These tests verify the income detection priority behavior in
| TransactionExtractorService::extractTransactionLocally().
|
| Task 1: Bug Condition Exploration Tests (EXPECTED TO FAIL on unfixed code)
|   - Messages starting with income keyword but containing expense patterns
|   - These should be classified as 'income' but the bug causes 'expense'
|
| Task 2: Preservation Property Tests (EXPECTED TO PASS on unfixed code)
|   - Messages NOT starting with income keyword behave correctly
|   - These confirm baseline behavior to preserve after fix
|
*/

// ============================================================================
// TASK 1: Bug Condition Exploration Tests
// These tests are EXPECTED TO FAIL on unfixed code - failure confirms bug exists
// ============================================================================

it('classifies "Pemasukan bayar wifi mardani 150000" as income when message starts with income keyword', function () {
    // Bug condition: starts with "pemasukan" (income keyword) but contains "bayar" (expense override)
    // Expected: income (because position-based priority should win)
    // Actual on unfixed code: expense (because expense override is checked first)
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Pemasukan bayar wifi mardani 150000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
})->group('bug-condition');

it('classifies "Masuk 150rb mardani bayar wifi" as income when message starts with income keyword', function () {
    // Bug condition: starts with "masuk" (income keyword) but contains "bayar" (expense override)
    // Expected: income (because position-based priority should win)
    // Actual on unfixed code: expense (because expense override is checked first)
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Masuk 150rb mardani bayar wifi');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
})->group('bug-condition');

it('classifies "Gaji karyawan 5000000" as ambiguous when message matches ambiguous pattern', function () {
    // With ambiguous transaction confirmation feature: "gaji karyawan" is now a configured
    // ambiguous pattern that could mean income (receiving salary) or expense (paying employee salary).
    // The system should flag it as ambiguous and ask for confirmation.
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Gaji karyawan 5000000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('ambiguous');
    expect($result['pattern'])->toBe('gaji karyawan');
    expect($result['income_category_type'])->toBe('pendapatan_gaji');
    expect($result['expense_category_type'])->toBe('pengeluaran_gaji_karyawan');
})->group('bug-condition');

it('classifies "Terima pembayaran customer 200000" as income when message starts with income keyword', function () {
    // Note: "terima pembayaran" is itself an income keyword in the config.
    // The regex /\bbayar\b/ does NOT match inside "pembayaran" (no word boundary before 'b' in 'pembayaran').
    // So this case does NOT actually trigger the bug condition — it passes on unfixed code too.
    // Kept here to document that this edge case is already handled correctly.
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Terima pembayaran customer 200000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
})->group('bug-condition');

// ============================================================================
// TASK 2: Preservation Property Tests
// These tests MUST PASS on unfixed code - they confirm baseline behavior
// ============================================================================

it('classifies "beli obat 50000" as expense (expense pattern match preserved)', function () {
    // Preservation: "beli obat" matches expense_detection_patterns, no income keyword at start
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('beli obat 50000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
})->group('preservation');

it('classifies "bayar wifi 100000" as expense (bayar standalone preserved)', function () {
    // Preservation: "bayar" matches \bbayar\b regex, no income keyword at start
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('bayar wifi 100000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
})->group('preservation');

it('classifies "makan siang 25000" as expense (default expense, no income keyword)', function () {
    // Preservation: no income keyword at start, no explicit expense pattern, defaults to expense
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('makan siang 25000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
})->group('preservation');

it('classifies "dapat bonus 1000000" as income (income keyword via word boundary)', function () {
    // Preservation: "dapat" is an income keyword matching via \b word boundary
    // No expense override pattern present, so income detection succeeds
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('dapat bonus 1000000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
})->group('preservation');

it('classifies "bayar kos 500000" as expense (expense pattern overrides)', function () {
    // Preservation: "bayar kos" matches expense_detection_patterns explicitly
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('bayar kos 500000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
})->group('preservation');

it('classifies "masukan data 50000" as expense (word boundary prevents false match on "masuk")', function () {
    // Preservation: "masukan" should NOT match income keyword "masuk" because
    // \b word boundary in regex /\bmasuk\b/ prevents matching "masukan" (followed by 'a')
    // So this correctly falls through to expense (no income keyword match)
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('masukan data 50000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
})->group('preservation');
