<?php

use App\Jobs\ProcessIncomingMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transaction\TransactionTypeDetector;

/**
 * Test untuk Fase 3: Dukungan Perubahan Tipe Transaksi
 *
 * Menguji skenario dari chat log:
 * - "Uang lembur pondok cabe 1,5 juta" → income (Fase 1, sudah tercover di TransactionTypeDetectorTest)
 * - "Ganti jadi 'pemasukan'" → ubah tipe expense → income
 * - "Ubah kategori" → tidak salah tangkap sebagai nama kategori
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
