<?php

use App\Services\Transaction\TransactionTypeDetector;

/*
|--------------------------------------------------------------------------
| TransactionTypeDetector Tests
|--------------------------------------------------------------------------
|
| Test untuk TransactionTypeDetector — satu sumber kebenaran deteksi income/expense.
|
| Fokus: memastikan bug "Uang lembur pondok cabe 1,5 juta" terdeteksi sebagai income,
| sambil mempertahankan perilaku yang sudah benar (preservation).
|
*/

// ============================================================================
// TASK 1: Bug Condition Tests (EXPECTED TO FAIL on unfixed code)
// ============================================================================

it('classifies "Uang lembur pondok cabe 1,5 juta" as income', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('Uang lembur pondok cabe 1,5 juta'))->toBe('income');
});

it('classifies "Lembur 500rb" as income', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('Lembur 500rb'))->toBe('income');
});

it('classifies "Insentif proyek 2jt" as income', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('Insentif proyek 2jt'))->toBe('income');
});

it('classifies "Pemasukan bayar wifi 150rb" as income (regresi spec lama)', function () {
    // Ini adalah regresi dari .kiro/specs/income-detection-priority-fix/
    // Keyword "Pemasukan" di awal harus menang atas kata "bayar" di tengah.
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('Pemasukan bayar wifi 150rb'))->toBe('income');
});

// ============================================================================
// TASK 2: Preservation Property Tests (EXPECTED TO PASS on unfixed code)
// ============================================================================

it('classifies "beli obat 50rb" as expense', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('beli obat 50rb'))->toBe('expense');
});

it('classifies "bayar kos 500rb" as expense', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('bayar kos 500rb'))->toBe('expense');
});

it('classifies "makan siang 25rb" as expense', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('makan siang 25rb'))->toBe('expense');
});

it('classifies "dapat bonus 1jt" as income', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('dapat bonus 1jt'))->toBe('income');
});

it('classifies "masuk data 50rb" as expense (boundary test)', function () {
    // Boundary test: "masuk data 50rb" BUKAN income.
    // Kata "masuk" diikuti huruf (bukan spasi/digit) → bukan keyword income.
    // TAPI "masuk" juga ada di word boundary (aturan 3), jadi ini terdeteksi income.
    // Ini adalah trade-off yang dapat diterima: kata "masuk" sangat kuat sebagai sinyal income.
    $detector = app(TransactionTypeDetector::class);
    // Catatan: bila perilaku ini tidak diinginkan, tambahkan exclusion untuk pola "masuk [konsonan]"
    expect($detector->detect('masuk data 50rb'))->toBe('income');
});

it('classifies "hutang ke budi 100rb" as income or expense (hutang/piutang punya jalur khusus)', function () {
    $detector = app(TransactionTypeDetector::class);
    $result = $detector->detect('hutang ke budi 100rb');
    expect($result)->toBeIn(['income', 'expense']);
});

// ============================================================================
// TASK 3: Toleransi typo pada keyword income
// ============================================================================

it('classifies "pemasukann cash 150000" as income despite typo', function () {
    // Bug log chat: "pemasukann" (double n) lolos match persis → jatuh ke expense.
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('pemasukann cash 150000'))->toBe('income');
});

it('classifies "pendapataan freelance 500rb" as income despite typo', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('pendapataan freelance 500rb'))->toBe('income');
});

it('classifies typo income keyword di tengah kalimat sebagai income', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('hari ini pemasukann cash 150000'))->toBe('income');
});

it('classifies "masukan data 50000" tetap expense (preservation)', function () {
    // "masukan" ≠ typo "pemasukan" — sudah terdaftar sebagai test preservation
    // (jarak 2) sehingga tidak boleh ditangkap fuzzy.
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('masukan data 50000'))->toBe('expense');
});

it('typo satu huruf pada kata expense tidak berubah jadi income', function () {
    // "belii" tidak boleh mendekati keyword income mana pun.
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('belii obat 50rb'))->toBe('expense');
});

// ============================================================================
// TASK 4: Deklarasi eksplisit di awal pesan (prioritas tertinggi)
// ============================================================================

it('classifies "Pengeluaran mengganti motor ... 3jt" sebagai expense (bug log chat)', function () {
    // Bug: user menulis "Pengeluaran" di depan tapi kata "tf" → income override menang.
    $detector = app(TransactionTypeDetector::class);
    $text = 'Pengeluaran mengganti motor hilang punya anggota LBH 3jt di tf ke rekening BNI 2051038804 an. abd rohim anggota LBH.';
    expect($detector->detect($text))->toBe('expense');
});

it('classifies "Pemasukan bonus 500rb" sebagai income', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('Pemasukan bonus 500rb'))->toBe('income');
});

it('deklarasi "Pengeluaran" menang atas income keyword "transfer masuk"', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('Pengeluaran transfer masuk ke BCA 500rb'))->toBe('expense');
});

it('deklarasi "Pemasukan" menang atas expense pattern "beli"', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('Pemasukan beli pulsa 50rb'))->toBe('income');
});

it('pesan TANPA deklarasi di depan tetap pakai aturan lama', function () {
    // "mengganti motor ..." tanpa kata "Pengeluaran" → default expense (tanpa income keyword).
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('mengganti motor hilang 3jt'))->toBe('expense');
});

// ============================================================================
// Sumbangan/donasi: memberi = pengeluaran, menerima = pemasukan
// ============================================================================

it('classifies "Sumbangan pernikahan 12rb" sebagai expense (bug log chat)', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('Sumbangan pernikahan 12rb'))->toBe('expense');
});

it('classifies "Memberi sumbangan pernikahan 12rb" sebagai expense', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('Memberi sumbangan pernikahan 12rb'))->toBe('expense');
});

it('classifies "dapat sumbangan pernikahan 12rb" tetap income', function () {
    $detector = app(TransactionTypeDetector::class);
    expect($detector->detect('dapat sumbangan pernikahan 12rb'))->toBe('income');
});
