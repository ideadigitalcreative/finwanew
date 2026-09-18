<?php

use App\Jobs\ProcessIncomingMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ConversationContextService;
use App\Services\Transaction\TransactionTypeDetector;

/**
 * Test untuk Fase 3-4: Dukungan Perubahan Tipe & Ask-Back
 *
 * Menguji skenario dari chat log:
 * - "Uang lembur pondok cabe 1,5 juta" → income (Fase 1, sudah tercover di TransactionTypeDetectorTest)
 * - "Ganti jadi 'pemasukan'" → ubah tipe expense → income (Fase 3)
 * - "Ubah kategori" → bot bertanya balik, BUKAN template (Fase 4)
 * - Jawaban ask-back: "Hiburan", "50rb", "pemasukan" (Fase 4)
 */

beforeEach(function () {
    $this->detector = app(TransactionTypeDetector::class);
});

// ==========================================
// TASK 1: Ubah Tipe Transaksi (dari chat log)
// ==========================================

it('mendeteksi "ganti jadi pemasukan" sebagai perintah ubah tipe', function () {
    $text = "ganti jadi pemasukan";
    $result = preg_match(
        '/^(?:ganti|ubah|edit|koreksi)\s+(?:jadi|ke|menjadi)\s*[\'"]?(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)[\'"]?\s*$/i',
        strtolower($text)
    );
    expect($result)->toBe(1);
});

it('mendeteksi "ubah ke pengeluaran" sebagai perintah ubah tipe', function () {
    $text = "ubah ke pengeluaran";
    $result = preg_match(
        '/^(?:ganti|ubah|edit|koreksi)\s+(?:jadi|ke|menjadi)\s*[\'"]?(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)[\'"]?\s*$/i',
        strtolower($text)
    );
    expect($result)->toBe(1);
});

it('mendeteksi "Ganti jadi \'pemasukan\'" dengan kutipan', function () {
    // Kasus eksak dari chat log
    $text = "Ganti jadi 'pemasukan'";
    $result = preg_match(
        '/^(?:ganti|ubah|edit|koreksi)\s+(?:jadi|ke|menjadi)\s*[\'"]?(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)[\'"]?\s*$/i',
        strtolower($text)
    );
    expect($result)->toBe(1);
});

it('menolak "ganti jadi pemasukan 50rb" (ada nominal, bukan murni ubah tipe)', function () {
    // Fast-path 1.6af2.4 HANYA untuk tanpa nominal
    // Dengan nominal harus ditangkap oleh fast-path 1.6af2.5 (edit with amount)
    $text = "ganti jadi pemasukan 50rb";
    $result = preg_match(
        '/^(?:ganti|ubah|edit|koreksi)\s+(?:jadi|ke|menjadi)\s*[\'"]?(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)[\'"]?\s*$/i',
        strtolower($text)
    );
    expect($result)->toBe(0); // Tidak match karena ada tambahan " 50rb"
});

// ==========================================
// TASK 2: Type Change Keywords Config
// ==========================================

it('config type_change_keywords memuat mapping yang benar', function () {
    $keywords = config('finwa_category_rules.type_change_keywords', []);

    expect($keywords)->toHaveKey('pemasukan');
    expect($keywords['pemasukan'])->toBe('income');

    expect($keywords)->toHaveKey('pengeluaran');
    expect($keywords['pengeluaran'])->toBe('expense');

    expect($keywords)->toHaveKey('income');
    expect($keywords['income'])->toBe('income');

    expect($keywords)->toHaveKey('expense');
    expect($keywords['expense'])->toBe('expense');
});

// ==========================================
// TASK 3: Regex Kategori Exclusion
// ==========================================

it('"Ubah kategori" tidak menangkap "kategori" sebagai nama kategori', function () {
    $text = "ubah kategori";
    $candidate = null;

    if (preg_match('/(?:kategori|masuk|pindah(?:in)?|ubah|ganti)\s+(?:ke\s+|jadi\s+)?([a-zA-Z\s]+)/i', $text, $catMatches)) {
        $candidate = trim($catMatches[1]);
    }

    // "kategori" harus di-exclude oleh regex guard
    $isExcluded = (bool) preg_match('/^(\d+|rp|rupiah|tanggal|tgl|harga|nominal|kategori|tipe|type|transaksi|jumlah)/i', $candidate ?? '');

    expect($isExcluded)->toBeTrue();
    expect($candidate)->toBe('kategori');
});

// ==========================================
// TASK 4: Deteksi Type Change di handleEditWithContext
// ==========================================

it('mendapatkan newType="income" dari "ganti jadi pemasukan"', function () {
    $text = "ganti jadi pemasukan";
    $textLower = strtolower($text);
    $typeChangeKeywords = config('finwa_category_rules.type_change_keywords', []);

    $newType = null;

    // Pola 1: awalan langsung
    if (preg_match(
        '/^(?:ganti|ubah|edit|koreksi|jadikan)\s+(?:jadi|ke|menjadi)?\s*[\'"]?([a-z\s]+?)[\'"]?\s*$/i',
        $textLower,
        $typeMatch
    )) {
        $typeCandidate = trim($typeMatch[1] ?? '');
        $newType = $typeChangeKeywords[$typeCandidate] ?? null;
    }

    // Pola 2: "... jadi 'pemasukan'"
    if (! $newType && preg_match(
        '/(?:jadi|ke|menjadi)\s+[\'"]?(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)[\'"]?/i',
        $textLower,
        $typeMatch2
    )) {
        $typeCandidate2 = trim($typeMatch2[1] ?? '');
        $newType = $typeChangeKeywords[$typeCandidate2] ?? null;
    }

    expect($newType)->toBe('income');
});

it('mendapatkan newType="expense" dari "ubah ke pengeluaran"', function () {
    $text = "ubah ke pengeluaran";
    $textLower = strtolower($text);
    $typeChangeKeywords = config('finwa_category_rules.type_change_keywords', []);

    $newType = null;

    if (preg_match(
        '/^(?:ganti|ubah|edit|koreksi|jadikan)\s+(?:jadi|ke|menjadi)?\s*[\'"]?([a-z\s]+?)[\'"]?\s*$/i',
        $textLower,
        $typeMatch
    )) {
        $typeCandidate = trim($typeMatch[1] ?? '');
        $newType = $typeChangeKeywords[$typeCandidate] ?? null;
    }

    if (! $newType && preg_match(
        '/(?:jadi|ke|menjadi)\s+[\'"]?(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)[\'"]?/i',
        $textLower,
        $typeMatch2
    )) {
        $typeCandidate2 = trim($typeMatch2[1] ?? '');
        $newType = $typeChangeKeywords[$typeCandidate2] ?? null;
    }

    expect($newType)->toBe('expense');
});

// ==========================================
// TASK 5: Fase 4 — Deteksi Perintah Ambigu (Ask-Back)
// ==========================================

it('mendeteksi "ubah kategori" sebagai perintah ambigu (ask-back)', function () {
    $text = "ubah kategori";
    $patterns = [
        '/^(ubah|ganti|edit|pindah(?:in)?)\s+(?:ke\s+)?kategori\s*$/i' => 'category',
    ];

    $matched = false;
    foreach ($patterns as $pattern => $field) {
        if (preg_match($pattern, strtolower($text))) {
            $matched = true;
            expect($field)->toBe('category');
            break;
        }
    }

    expect($matched)->toBeTrue();
});

it('mendeteksi "ganti nominal" sebagai perintah ambigu untuk amount', function () {
    $text = "ganti nominal";
    $patterns = [
        '/^(ubah|ganti|edit)\s+(?:ke\s+)?(?:nominal|jumlah|harga)\s*$/i' => 'amount',
    ];

    $matched = false;
    foreach ($patterns as $pattern => $field) {
        if (preg_match($pattern, strtolower($text))) {
            $matched = true;
            expect($field)->toBe('amount');
            break;
        }
    }

    expect($matched)->toBeTrue();
});

it('mendeteksi "edit tanggal" sebagai perintah ambigu untuk date', function () {
    $text = "edit tanggal";
    $patterns = [
        '/^(ubah|ganti|edit)\s+(?:ke\s+)?(?:tanggal|tgl)\s*$/i' => 'date',
    ];

    $matched = false;
    foreach ($patterns as $pattern => $field) {
        if (preg_match($pattern, strtolower($text))) {
            $matched = true;
            expect($field)->toBe('date');
            break;
        }
    }

    expect($matched)->toBeTrue();
});

it('menolak "ubah kategori jadi hiburan" sebagai perintah ambigu (ada nilai)', function () {
    // Ini BUKAN ambigu karena user sudah kasih nilai → harus diproses langsung
    $text = "ubah kategori jadi hiburan";
    $result = preg_match(
        '/^(ubah|ganti|edit|pindah(?:in)?)\s+(?:ke\s+)?kategori\s*$/i',
        strtolower($text)
    );

    expect($result)->toBe(0); // Tidak match karena ada " jadi hiburan"
});

// ==========================================
// TASK 6: Fase 4 — Helper isAnswerOnly()
// ==========================================

it('menganggap "Hiburan" sebagai jawaban valid (isAnswerOnly)', function () {
    // Simulasikan logika isAnswerOnly
    $text = "Hiburan";
    $textLower = strtolower(trim($text));

    $isTooLong = strlen($textLower) > 60;
    $commandKeywords = [
        'hapus', 'delete', 'batal', 'tambah', 'baru', 'buat',
        'transfer', 'kirim', 'bayar', 'beli', 'cek', 'lihat',
        'laporan', 'ringkasan', 'saldo', 'budget', 'anggaran',
    ];

    $hasCommand = false;
    foreach ($commandKeywords as $keyword) {
        if (str_starts_with($textLower, $keyword)) {
            $hasCommand = true;
            break;
        }
    }

    $isAnswerOnly = ! $isTooLong && ! $hasCommand;

    expect($isAnswerOnly)->toBeTrue();
});

it('menganggap "50rb" sebagai jawaban valid (isAnswerOnly)', function () {
    $text = "50rb";
    $textLower = strtolower(trim($text));

    $isTooLong = strlen($textLower) > 60;

    expect($isTooLong)->toBeFalse();
});

it('menolak "hapus transaksi ini" sebagai jawaban (ada kata perintah)', function () {
    $text = "hapus transaksi ini";
    $textLower = strtolower(trim($text));

    $commandKeywords = [
        'hapus', 'delete', 'batal', 'tambah', 'baru', 'buat',
        'transfer', 'kirim', 'bayar', 'beli', 'cek', 'lihat',
        'laporan', 'ringkasan', 'saldo', 'budget', 'anggaran',
    ];

    $hasCommand = false;
    foreach ($commandKeywords as $keyword) {
        if (str_starts_with($textLower, $keyword)) {
            $hasCommand = true;
            break;
        }
    }

    expect($hasCommand)->toBeTrue(); // Ada kata "hapus" di awal
});
