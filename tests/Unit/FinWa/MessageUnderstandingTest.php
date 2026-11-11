<?php

/**
 * Regression guard Prioritas 3 — "pesan sering salah dipahami".
 * Routing end-to-end diuji lewat golden set (tests/golden/messages.jsonl, id p3-*).
 */

use App\Helpers\KeywordNormalizer;
use App\Services\FinancialQueryService;
use App\Services\WhatsApp\MessageSignals;
use Carbon\Carbon;

function finwaPeriod(string $q): array
{
    $svc = new class extends FinancialQueryService
    {
        public function period(string $q): array
        {
            return $this->extractPeriod(mb_strtolower($q));
        }

        public function requested(string $q): array
        {
            return $this->extractRequestedData(mb_strtolower($q));
        }
    };

    return ['period' => $svc->period($q), 'requested' => $svc->requested($q)];
}

function finwaHasKeyword(string $text, string $keyword): bool
{
    return MessageSignals::hasKeyword($text, $keyword);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 13, 9, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── KeywordNormalizer: tidak lagi mengganti kata di tengah kalimat ──

it('tidak merusak kalimat yang hanya mengandung kata mapping', function (string $input) {
    expect(KeywordNormalizer::normalize($input))->toBe($input);
})->with([
    'hapus transaksi makan siang',
    'gimana kondisi keuangan',
    'download laporan',
    'preset',
    'tolong catat beli kopi 20rb',
]);

it('tetap memetakan perintah yang persis', function (string $input, string $expected) {
    expect(KeywordNormalizer::normalize($input))->toBe($expected);
})->with([
    ['bantuan', 'help'],
    ['reset', 'reset akun'],
    ['hapus transaksi', 'hapus transaksi terakhir'],
    ['laporan?', 'cek statistik'],
    ['lihat transaksi september', 'cek transaksi september'],
    ['dompet saya ada apa saja', 'cek dompet ada apa saja'],
]);

// ── Kata kunci pendek tidak cocok di dalam kata lain ──

it('kata kunci pendek tidak cocok di dalam kata lain', function (string $text, string $kw) {
    expect(finwaHasKeyword($text, $kw))->toBeFalse();
})->with([
    ['beli kopi di coffee shop', 'ff'],
    ['bayar kursus inggris', 'rs'],
    ['beli label nama', 'lab'],
    ['happy hour', 'app'],
    ['before lunch', 'fore'],
    ['pengeluaran laptop', 'top'],
    ['belajar html', 'ml'],
    ['pemasukan cash', 'emas'],
]);

it('kata kunci tetap cocok sebagai kata utuh / berimbuhan akhir', function (string $text, string $kw) {
    expect(finwaHasKeyword($text, $kw))->toBeTrue();
})->with([
    ['top up ff 50rb', 'ff'],
    ['periksa ke rs 200rb', 'rs'],
    ['beli kopinya 20rb', 'kopi'],
    ['makanan kucing 50rb', 'makan'],
    ['bayar listrik', 'bayar'],
]);

// ── Parser periode ──

it('membaca periode dengan benar', function (string $q, string $start, string $end) {
    $p = finwaPeriod($q)['period'];
    expect($p['start']->toDateString())->toBe($start)
        ->and($p['end']->toDateString())->toBe($end);
})->with([
    'bulan kemarin = bulan lalu, bukan kemarin' => ['pengeluaran bulan kemarin', '2026-09-01', '2026-09-30'],
    'kemarin' => ['transaksi kemarin', '2026-10-12', '2026-10-12'],
    '2 bulan lalu = Agustus saja' => ['pengeluaran 2 bulan lalu', '2026-08-01', '2026-08-31'],
    '3 bulan terakhir = rentang' => ['pengeluaran 3 bulan terakhir', '2026-07-13', '2026-10-13'],
    'nama bulan' => ['lihat transaksi september', '2026-09-01', '2026-09-30'],
    'singkatan bulan' => ['rekap okt', '2026-10-01', '2026-10-31'],
    'bulan + tahun' => ['transaksi september 2025', '2025-09-01', '2025-09-30'],
]);

it('bulan lalu tidak meloncat di akhir bulan (31 Maret → Februari)', function () {
    Carbon::setTestNow(Carbon::create(2026, 3, 31, 9, 0, 0));
    $p = finwaPeriod('ringkasan bulan lalu')['period'];
    expect($p['start']->toDateString())->toBe('2026-02-01')
        ->and($p['end']->toDateString())->toBe('2026-02-28');
});

it('membedakan permintaan daftar vs ringkasan', function (string $q, bool $isList) {
    expect(finwaPeriod($q)['requested']['list'])->toBe($isList);
})->with([
    ['lihat transaksi september', true],
    ['rinciannya bulan lalu', true],
    ['transaksi kemarin', true],
    ['ringkasan bulan lalu', false],
    ['berapa transaksi bulan ini', false],
]);

// ── MessageSignals: keputusan query vs transaksi ──

it('mengenali pesan sebagai pencatatan transaksi', function (string $text) {
    $s = MessageSignals::analyze(mb_strtolower($text));
    expect($s->hasTransactionKeyword)->toBeTrue()
        ->and($s->hasMoneyAmount)->toBeTrue()
        ->and($s->looksLikeQuery)->toBeFalse();
})->with([
    'beli pulsa total 50rb hari ini',
    'bayar daftar ulang bulan ini 500rb',
    'service motor ganti oli 50rb',
    'pemasukan cash 200000',
    'beli kopi di coffee shop 35rb',
]);

it('mengenali pesan sebagai pertanyaan/laporan', function (string $text) {
    $s = MessageSignals::analyze(mb_strtolower($text));
    expect($s->looksLikeQuery)->toBeTrue();
})->with([
    'pengeluaran bulan ini',
    'pengeluaran 500rb bulan ini',
    'lihat transaksi september',
    'rinciannya bulan lalu',
    'pengeluaran 2 bulan lalu',
    'rekap okt',
]);

it('angka bukan nominal uang tidak dianggap nominal', function (string $text) {
    expect(MessageSignals::hasMoneyAmount(mb_strtolower($text)))->toBeFalse();
})->with(['rapat tadi jam 3', 'tanggal 5', 'transaksi september 2026', '2 bulan lalu']);

it('mendeteksi nominal uang', function (string $text) {
    expect(MessageSignals::hasMoneyAmount(mb_strtolower($text)))->toBeTrue();
})->with(['25rb', '50k', '1,5jt', 'Rp 5000', '25.000', '15000', '2 juta']);

it('top up bukan permintaan statistik, laptop bukan "top"', function () {
    expect(MessageSignals::analyze('top up gopay 50rb')->hasStatisticsKeyword)->toBeFalse()
        ->and(MessageSignals::analyze('pengeluaran laptop bulan ini')->hasStatisticsKeyword)->toBeFalse()
        ->and(MessageSignals::analyze('pengeluaran terbesar bulan ini')->hasStatisticsKeyword)->toBeTrue();
});

it('mendeteksi kata hutang/piutang termasuk bentuk berimbuhan', function () {
    expect(MessageSignals::analyze('pelunasan cicilan')->hasDebtKeyword)->toBeTrue()
        ->and(MessageSignals::analyze('piutang noki 20jt')->hasDebtKeyword)->toBeTrue()
        ->and(MessageSignals::analyze('beli kopi 20rb')->hasDebtKeyword)->toBeFalse();
});
