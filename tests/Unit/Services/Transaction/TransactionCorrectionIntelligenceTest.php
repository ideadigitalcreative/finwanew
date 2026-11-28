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

// ==========================================
// TASK 7: Regresi — Method Kritis Harus Ada
// ==========================================
// Mencegah bug "Call to undefined method" yang membuat SEMUA pesan
// gagal diproses (job crash sebelum kirim balasan).

it('ProcessIncomingMessage memiliki method getAttributionSenderId()', function () {
    // Bug: fast-path 1.6af0 memanggil $this->getAttributionSenderId()
    // padahal method ini tidak ada → semua pesan pendek crash.
    expect((new ReflectionClass(ProcessIncomingMessage::class))->hasMethod('getAttributionSenderId'))->toBeTrue();
});

it('TransactionService memiliki method yang dipakai alur ask-back', function () {
    $reflection = new ReflectionClass(\App\Services\Transaction\TransactionService::class);

    expect($reflection->hasMethod('askBackForEdit'))->toBeTrue();
    expect($reflection->hasMethod('resolveLastTransaction'))->toBeTrue();
    expect($reflection->hasMethod('handleEditWithContext'))->toBeTrue();
});

it('ConversationContextService memiliki method state pending_edit', function () {
    $reflection = new ReflectionClass(ConversationContextService::class);

    expect($reflection->hasMethod('storePendingEdit'))->toBeTrue();
    expect($reflection->hasMethod('getPendingEdit'))->toBeTrue();
    expect($reflection->hasMethod('clearPendingEdit'))->toBeTrue();
});

// ==========================================
// TASK 8: Ops — parse "balas nomor daftar"
// ==========================================

it('parseListIndex membaca "2 ubah jadi 35rb" sebagai nomor 2', function () {
    expect(\App\Services\Transaction\TransactionService::parseListIndex('2 ubah jadi 35rb'))->toBe(2);
});

it('parseListIndex mendukung format "3." dan "1)"', function () {
    expect(\App\Services\Transaction\TransactionService::parseListIndex('3. ubah jadi 50rb'))->toBe(3);
    expect(\App\Services\Transaction\TransactionService::parseListIndex('1) ganti ke 25rb'))->toBe(1);
});

it('parseListIndex menolak pesan tanpa kata perintah (bukan balasan nomor)', function () {
    // "50rb" → nominal biasa, BUKAN nomor baris
    expect(\App\Services\Transaction\TransactionService::parseListIndex('50rb'))->toBeNull();
    expect(\App\Services\Transaction\TransactionService::parseListIndex('20000'))->toBeNull();
    expect(\App\Services\Transaction\TransactionService::parseListIndex('beli makan 2'))->toBeNull();
});

it('parseListIndex menolak nomor 0 dan pesan kosong', function () {
    expect(\App\Services\Transaction\TransactionService::parseListIndex('0 ubah jadi 5rb'))->toBeNull();
    expect(\App\Services\Transaction\TransactionService::parseListIndex(''))->toBeNull();
});

// ==========================================
// Ops — "ubah kategori nya jadi makanan"
// ==========================================

it('isCommandOnlyKeyword mengenali kata perintah tanpa deskripsi', function () {
    $service = \App\Services\Transaction\TransactionService::class;

    expect($service::isCommandOnlyKeyword('ubah'))->toBeTrue();
    expect($service::isCommandOnlyKeyword('ganti'))->toBeTrue();
    expect($service::isCommandOnlyKeyword('UBAH'))->toBeTrue();
    expect($service::isCommandOnlyKeyword(''))->toBeTrue();

    // Deskripsi asli bukan kata perintah
    expect($service::isCommandOnlyKeyword('beli susu baby'))->toBeFalse();
    expect($service::isCommandOnlyKeyword('susu'))->toBeFalse();
});

it('cleanCategoryCandidate membuang partikel dan kata penghubung', function () {
    $service = \App\Services\Transaction\TransactionService::class;

    expect($service::cleanCategoryCandidate('nya jadi makanan'))->toBe('makanan');
    expect($service::cleanCategoryCandidate('ke kesehatan'))->toBe('kesehatan');
    expect($service::cleanCategoryCandidate('jadi makanan & minuman'))->toBe('makanan & minuman');
    expect($service::cleanCategoryCandidate('makanan'))->toBe('makanan');
});

// ==========================================
// Koreksi arah transaksi tanpa kata sambung (bug log chat)
// ==========================================

it('mengenali "Termasuk pengeluaran" sebagai koreksi tipe, bukan format tak dikenal', function () {
    expect(\App\Services\Transaction\TransactionService::isTypeOnlyCorrection('Termasuk pengeluaran'))->toBeTrue();
});

it('mengenali varian koreksi tipe lain', function (string $text) {
    expect(\App\Services\Transaction\TransactionService::isTypeOnlyCorrection($text))->toBeTrue();
})->with([
    'itu pemasukan',
    'harusnya pengeluaran',
    'ini pengeluaran ya',
    'masukin ke pemasukan',
    'bukan pemasukan',
]);

it('tidak menganggap kalimat transaksi/query sebagai koreksi tipe', function (string $text) {
    expect(\App\Services\Transaction\TransactionService::isTypeOnlyCorrection($text))->toBeFalse();
})->with([
    'pengeluaran',                       // query daftar pengeluaran
    'berapa pengeluaran bulan ini',
    'Pengeluaran beli sepatu 300rb',
    'termasuk pengeluaran makan 50rb',
]);
