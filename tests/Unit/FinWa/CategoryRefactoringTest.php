<?php

use App\Helpers\BatchTransactionHelper;
use App\Services\Transaction\TransactionExtractorService;

/*
|--------------------------------------------------------------------------
| P1+P2+P3 Category Refactoring Tests
|--------------------------------------------------------------------------
|
| Memverifikasi:
| 1. Config completeness (semua key ada, tidak kosong)
| 2. BatchTransactionHelper — amount extraction dengan spasi
| 3. TransactionExtractorService — income/expense detection
| 4. Config keyword consistency
|
*/

// ═══════════════════════════════════════════════════════════
// 1. CONFIG COMPLETENESS
// ═══════════════════════════════════════════════════════════

test('config finwa_category_rules memiliki semua section yang dibutuhkan', function () {
    $requiredKeys = [
        'expense_keywords',
        'income_keywords',
        'ai_category_map',
        'income_detection_keywords',
        'expense_detection_patterns',
        'ai_category_overrides',
        'local_expense_extras',
        'local_income_extras',
        'batch_expense_action_keywords',
        'batch_income_action_keywords',
        'error_income_keywords',
        'error_expense_keywords',
    ];

    foreach ($requiredKeys as $key) {
        expect(config("finwa_category_rules.{$key}"))->not->toBeNull("Config key '{$key}' tidak ditemukan");
        expect(config("finwa_category_rules.{$key}"))->toBeArray("Config key '{$key}' harus array");
    }
});

test('config expense_keywords tidak kosong', function () {
    $keywords = config('finwa_category_rules.expense_keywords', []);
    expect($keywords)->not->toBeEmpty();
    expect(count($keywords))->toBeGreaterThan(100);
});

test('config income_keywords tidak kosong', function () {
    $keywords = config('finwa_category_rules.income_keywords', []);
    expect($keywords)->not->toBeEmpty();
    expect(count($keywords))->toBeGreaterThan(20);
});

test('config batch_expense_action_keywords tidak kosong', function () {
    $keywords = config('finwa_category_rules.batch_expense_action_keywords', []);
    expect($keywords)->not->toBeEmpty();
    expect(count($keywords))->toBeGreaterThan(30);
});

test('config batch_income_action_keywords tidak kosong', function () {
    $keywords = config('finwa_category_rules.batch_income_action_keywords', []);
    expect($keywords)->not->toBeEmpty();
    expect(count($keywords))->toBeGreaterThan(5);
});

test('config error_income_keywords tidak kosong', function () {
    $keywords = config('finwa_category_rules.error_income_keywords', []);
    expect($keywords)->not->toBeEmpty();
    expect(count($keywords))->toBeGreaterThan(5);
});

test('config error_expense_keywords tidak kosong', function () {
    $keywords = config('finwa_category_rules.error_expense_keywords', []);
    expect($keywords)->not->toBeEmpty();
    expect(count($keywords))->toBeGreaterThan(10);
});

test('config ai_category_overrides semua value ada di ai_category_map', function () {
    $overrides = config('finwa_category_rules.ai_category_overrides', []);
    $aiMap = config('finwa_category_rules.ai_category_map', []);
    expect($overrides)->not->toBeEmpty();

    foreach ($overrides as $keyword => $kategori) {
        expect(array_key_exists($kategori, $aiMap))->toBeTrue("Override '{$keyword} => {$kategori}' harus ada di ai_category_map agar bisa di-resolve");
    }
});

test('config tidak ada duplikat keyword antara expense dan income detection', function () {
    $incomeKw = config('finwa_category_rules.income_detection_keywords', []);
    $expensePatterns = config('finwa_category_rules.expense_detection_patterns', []);

    // Check that no keyword appears in both
    $overlap = array_intersect($incomeKw, $expensePatterns);
    expect($overlap)->toBeEmpty('Keyword yang ada di income dan expense sekaligus: '.implode(', ', $overlap));
});

test('config ai_income_overrides tidak kosong', function () {
    $overrides = config('finwa_category_rules.ai_income_overrides', []);
    expect($overrides)->not->toBeEmpty();
    expect(count($overrides))->toBeGreaterThan(5);
});

test('config ai_income_overrides semua value dimulai dengan pendapatan_', function () {
    $overrides = config('finwa_category_rules.ai_income_overrides', []);
    foreach ($overrides as $keyword => $kategori) {
        expect($kategori)->toStartWith('pendapatan_', "Income override '{$keyword} => {$kategori}' harus pendapatan_*");
    }
});

// ═══════════════════════════════════════════════════════════
// 2. BATCH TRANSACTION HELPER — AMOUNT EXTRACTION
// ═══════════════════════════════════════════════════════════

test('extractAmount mendukung angka dengan spasi ribuan', function () {
    $testCases = [
        ['input' => 'makan 75 000', 'expected' => 75000],
        ['input' => 'beli buku 150 000', 'expected' => 150000],
        ['input' => 'bayar kos 1 500 000', 'expected' => 1500000],
        ['input' => 'transfer 5 000 000', 'expected' => 5000000],
        ['input' => 'makan 250 000', 'expected' => 250000],
    ];

    foreach ($testCases as $case) {
        $result = BatchTransactionHelper::extractAmount($case['input']);
        expect($result)->toBe($case['expected'], "extractAmount('{$case['input']}') harus {$case['expected']}, dapat {$result}");
    }
});

test('extractAmount tetap mendukung format lama (titik, koma, suffix)', function () {
    $testCases = [
        ['input' => 'makan 50.000', 'expected' => 50000],
        ['input' => 'bayar 50rb', 'expected' => 50000],
        ['input' => 'transfer 2jt', 'expected' => 2000000],
        ['input' => 'gaji 5juta', 'expected' => 5000000],
        ['input' => 'beli 100ribu', 'expected' => 100000],
        ['input' => 'Rp 75000', 'expected' => 75000],
        ['input' => 'Rp. 100.000', 'expected' => 100000],
        ['input' => 'makan 50000', 'expected' => 50000],
    ];

    foreach ($testCases as $case) {
        $result = BatchTransactionHelper::extractAmount($case['input']);
        expect($result)->toBe($case['expected'], "extractAmount('{$case['input']}') harus {$case['expected']}, dapat {$result}");
    }
});

test('extractAllAmounts mendukung angka dengan spasi ribuan', function () {
    $result = BatchTransactionHelper::extractAllAmounts('makan 75 000 dan minum 15 000');
    expect($result)->toContain(75000);
    expect($result)->toContain(15000);
});

test('extractAllAmounts mendukung format titik atau spasi (satu format per pesan)', function () {
    $resultDots = BatchTransactionHelper::extractAllAmounts('makan 50.000 dan minum 15.000');
    expect($resultDots)->toContain(50000);
    expect($resultDots)->toContain(15000);

    $resultSpaces = BatchTransactionHelper::extractAllAmounts('makan 50 000 dan minum 15 000');
    expect($resultSpaces)->toContain(50000);
    expect($resultSpaces)->toContain(15000);
});

// ═══════════════════════════════════════════════════════════
// 3. BATCH TRANSACTION DETECTION
// ═══════════════════════════════════════════════════════════

test('isBatchTransaction mendeteksi batch dengan angka spasi', function () {
    $text = "makan 75 000\nminum 15 000\njajan 25 000";
    expect(BatchTransactionHelper::isBatchTransaction($text))->toBeTrue();
});

test('isBatchTransaction mendeteksi batch dengan angka titik', function () {
    $text = "makan 50.000\nminum 15.000\njajan 25.000";
    expect(BatchTransactionHelper::isBatchTransaction($text))->toBeTrue();
});

test('isBatchTransaction mendeteksi batch dengan suffix rb', function () {
    $text = "makan 50rb\nminum 15rb\njajan 25rb";
    expect(BatchTransactionHelper::isBatchTransaction($text))->toBeTrue();
});

test('isBatchTransaction return false untuk single transaksi', function () {
    expect(BatchTransactionHelper::isBatchTransaction('makan 50rb'))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════
// 4. TRANSACTION EXTRACTOR — INCOME/EXPENSE DETECTION
// ═══════════════════════════════════════════════════════════

test('extractTransactionLocally mendeteksi amplop nikahan sebagai expense', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('kasih amplop nikahan 200 rb');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
    expect($result['category_type'])->toBe('pengeluaran_sosial');
});

test('extractTransactionLocally mendeteksi amplop sebagai expense', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('amplop nikahan 100rb');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
    expect($result['category_type'])->toBe('pengeluaran_sosial');
});

test('extractTransactionLocally mendeteksi makan sebagai expense', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('makan siang 25000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
    expect($result['category_type'])->toBe('pengeluaran_makanan');
});

test('extractTransactionLocally mendeteksi gaji sebagai income', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('gaji bulan ini 5000000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
    expect($result['category_type'])->toBe('pendapatan_gaji');
});

test('extractTransactionLocally mendeteksi bonus sebagai income', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('bonus 1000000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
    expect($result['category_type'])->toBe('pendapatan_bonus');
});

test('extractTransactionLocally mendeteksi zakat sebagai expense', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('zakat 500000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
    expect($result['category_type'])->toBe('pengeluaran_donasi');
});

test('extractTransactionLocally mendeteksi sedekah sebagai expense', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('sedekah 100rb');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
    expect($result['category_type'])->toBe('pengeluaran_donasi');
});

test('extractTransactionLocally mendeteksi beli bensin sebagai expense', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('beli bensin 50rb');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
    expect($result['category_type'])->toBe('pengeluaran_transport');
});

test('extractTransactionLocally mendeteksi dikasih sebagai income', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('dikasih uang 50rb');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
});

// ═══════════════════════════════════════════════════════════
// 5. AMOUNT EXTRACTION DENGAN BERBAGAI FORMAT
// ═══════════════════════════════════════════════════════════

test('extractTransactionLocally dengan angka spasi', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('makan 75 000');

    expect($result)->not->toBeNull();
    expect($result['amount'])->toBe(75000);
    expect($result['type'])->toBe('expense');
});

test('extractTransactionLocally dengan angka titik', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('makan 75.000');

    expect($result)->not->toBeNull();
    expect($result['amount'])->toBe(75000);
});

test('extractTransactionLocally dengan suffix rb', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('makan 75rb');

    expect($result)->not->toBeNull();
    expect($result['amount'])->toBe(75000);
});

test('extractTransactionLocally dengan suffix jt', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('gaji 5jt');

    expect($result)->not->toBeNull();
    expect($result['amount'])->toBe(5000000);
});

// ═══════════════════════════════════════════════════════════
// 6. DESCRIPTION EXTRACTION
// ═══════════════════════════════════════════════════════════

test('description extraction amount-first dengan spasi', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('75 000 makan siang');

    expect($result)->not->toBeNull();
    expect($result['description'])->toBe('75 000 makan siang');
    expect($result['amount'])->toBe(75000);
});

test('description extraction amount-first dengan suffix', function () {
    $svc = app(TransactionExtractorService::class);
    $result = $svc->extractTransactionLocally('50rb makan siang');

    expect($result)->not->toBeNull();
    expect($result['description'])->toBe('50rb makan siang');
    expect($result['amount'])->toBe(50000);
});

test('extractDescriptionFromLine menghapus amount dari awal', function () {
    $svc = app(TransactionExtractorService::class);
    expect($svc->extractDescriptionFromLine('75 000 makan siang'))->toBe('makan siang');
    expect($svc->extractDescriptionFromLine('50rb makan siang'))->toBe('makan siang');
    expect($svc->extractDescriptionFromLine('20rb maxim dari JGC'))->toBe('maxim dari JGC');
});

// ═══════════════════════════════════════════════════════════
// 7. BATCH SPLIT DENGAN ANGKA SPASI
// ═══════════════════════════════════════════════════════════

test('splitSingleBatchLine mendukung angka dengan spasi', function () {
    $svc = app(\App\Services\Transaction\BatchTransactionService::class, [
        'sendReplyCallback' => function () {},
    ]);

    $method = new ReflectionMethod($svc, 'splitSingleBatchLine');
    $method->setAccessible(true);

    $result = $method->invoke($svc, 'makan 75 000 dan minum 15 000');
    expect($result)->toBeArray();
    expect(count($result))->toBeGreaterThanOrEqual(2);
});

// ═══════════════════════════════════════════════════════════
// 8. CONFIG KEYWORD MAPPING CONSISTENCY
// ═══════════════════════════════════════════════════════════

test('semua expense_keywords value dimulai dengan pengeluaran_', function () {
    $keywords = config('finwa_category_rules.expense_keywords', []);
    foreach ($keywords as $kw => $kategori) {
        expect($kategori)->toStartWith('pengeluaran_', "expense_keywords['{$kw}'] = '{$kategori}' harus pengeluaran_*");
    }
});

test('semua income_keywords value dimulai dengan pendapatan_', function () {
    $keywords = config('finwa_category_rules.income_keywords', []);
    foreach ($keywords as $kw => $kategori) {
        expect($kategori)->toStartWith('pendapatan_', "income_keywords['{$kw}'] = '{$kategori}' harus pendapatan_*");
    }
});

test('semua local_expense_extras value dimulai dengan pengeluaran_', function () {
    $keywords = config('finwa_category_rules.local_expense_extras', []);
    foreach ($keywords as $kw => $kategori) {
        expect($kategori)->toStartWith('pengeluaran_', "local_expense_extras['{$kw}'] = '{$kategori}' harus pengeluaran_*");
    }
});

test('semua local_income_extras value dimulai dengan pendapatan_', function () {
    $keywords = config('finwa_category_rules.local_income_extras', []);
    foreach ($keywords as $kw => $kategori) {
        expect($kategori)->toStartWith('pendapatan_', "local_income_extras['{$kw}'] = '{$kategori}' harus pendapatan_*");
    }
});

test('amplop nikahan ada di expense_keywords', function () {
    $keywords = config('finwa_category_rules.expense_keywords', []);
    expect($keywords)->toHaveKey('amplop nikahan');
    expect($keywords['amplop nikahan'])->toBe('pengeluaran_sosial');
});

test('amplop nikahan di-list SEBELUM amplop (longer match priority)', function () {
    $keywords = config('finwa_category_rules.expense_keywords', []);
    $keys = array_keys($keywords);
    $posAmplopNikahan = array_search('amplop nikahan', $keys);
    $posAmplop = array_search('amplop', $keys);

    expect($posAmplopNikahan)->not->toBeFalse('amplop nikahan harus ada di config');
    expect($posAmplop)->not->toBeFalse('amplop harus ada di config');
    expect($posAmplopNikahan)->toBeLessThan($posAmplop, 'amplop nikahan harus sebelum amplop');
});

test('amplop TIDAK ada di income_keywords', function () {
    $incomeKeywords = config('finwa_category_rules.income_keywords', []);
    expect($incomeKeywords)->not->toHaveKey('amplop');
});

test('amplop TIDAK ada di batch_income_action_keywords', function () {
    $batchIncome = config('finwa_category_rules.batch_income_action_keywords', []);
    expect($batchIncome)->not->toContain('amplop');
});
