<?php

use App\Models\Message;
use App\Services\Wallet\WalletCommandService;

/**
 * extractWalletName tidak menyentuh DB — cukup instance service
 * dengan kolom minimal (Message tidak di-save).
 */
function extractWalletNameFor(string $text): ?string
{
    $service = new WalletCommandService(
        new Message(['tenant_id' => 1, 'content' => '']),
        fn (string $reply) => null,
        fn (string $txt) => 0
    );

    return $service->extractWalletName($text);
}

// Bug log chat: "ringkasan bulanan ini" dianggap nama dompet →
// "⚠️ Dompet 'Ringkasan Bulanan Ini' tidak ditemukan" + daftar saldo.
it('menganggap "ringkasan bulanan ini" sebagai query, bukan nama dompet', function () {
    expect(extractWalletNameFor('ringkasan bulanan ini'))->toBeNull();
});

it('menganggap query ringkasan bervariasi sebagai bukan nama dompet', function (string $text) {
    expect(extractWalletNameFor($text))->toBeNull();
})->with([
    'rekap bulan lalu',
    'ringkasan cashflow bulan ini',
    'laporan keuangan minggu ini',
    'summary pengeluaran',
    'statistik bulan ini',
]);

it('tetap mengekstrak nama dompet yang valid', function (string $text, string $expected) {
    expect(extractWalletNameFor($text))->toBe($expected);
})->with([
    ['saldo Bank BCA', 'Bank BCA'],
    ['cek saldo mandiri', 'Bank Mandiri'],
    ['saldo gopay', 'GoPay'],
]);

it('menganggap persis kata query sebagai null (preservation)', function () {
    expect(extractWalletNameFor('ringkasan'))->toBeNull()
        ->and(extractWalletNameFor('cashflow'))->toBeNull();
});
