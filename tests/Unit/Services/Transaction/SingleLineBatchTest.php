<?php

use App\Helpers\BatchTransactionHelper;
use App\Models\Message;
use App\Services\Transaction\BatchTransactionService;

/**
 * Bug log chat: "Beli rokok 24000 sama jajan 6000" hanya tercatat Rp 6.000.
 * Akar: angka polos tanpa suffix/pemisah tidak dikenali extractor → batch
 * tidak terdeteksi → hanya nominal terakhir yang masuk.
 */

function singleLineBatchMessage(): Message
{
    return new Message(['tenant_id' => 1, 'sender_id' => '628111111111', 'content' => '']);
}

/**
 * Service batch dengan callback perekam — tanpa DB.
 *
 * @param  array<int, array{type: string, amount: int, description: string}>  $created
 */
function singleLineBatchService(array &$created, array &$replies): BatchTransactionService
{
    $extractor = new \App\Services\Transaction\TransactionExtractorService;

    return new BatchTransactionService(
        singleLineBatchMessage(),
        function (string $reply) use (&$replies) {
            $replies[] = $reply;
        },
        fn (string $txt) => $extractor->extractAmountFromText($txt),
        fn (string $txt) => $extractor->extractDescriptionFromLine($txt),
        fn (string $desc, bool $isIncome) => $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya',
        function (array $tx) use (&$created) {
            $created[] = $tx;

            return (object) ['category' => (object) ['name' => 'Lainnya']];
        }
    );
}

it('extractAllAmounts mengenali angka polos tanpa suffix', function () {
    $amounts = BatchTransactionHelper::extractAllAmounts('Beli rokok 24000 sama jajan 6000');

    expect($amounts)->toBe([24000, 6000]);
});

it('isBatchTransaction mendeteksi batch satu baris dengan 2 angka polos', function () {
    expect(BatchTransactionHelper::isBatchTransaction('Beli rokok 24000 sama jajan 6000'))->toBeTrue();
});

it('isBatchTransactionFormat menangkap pesan satu baris multi-nominal', function () {
    $svc = new BatchTransactionService(singleLineBatchMessage(), fn () => null);

    expect($svc->isBatchTransactionFormat('Beli rokok 24000 sama jajan 6000'))->toBeTrue();
});

it('pesan "Beli rokok 24000 sama jajan 6000" tercatat 2 transaksi (bug log chat)', function () {
    $created = [];
    $replies = [];
    $svc = singleLineBatchService($created, $replies);

    $svc->handleBatchTransactions('Beli rokok 24000 sama jajan 6000');

    expect($created)->toHaveCount(2)
        ->and($created[0]['amount'])->toBe(24000)
        ->and($created[1]['amount'])->toBe(6000)
        ->and($created[0]['type'])->toBe('expense')
        ->and($created[1]['type'])->toBe('expense')
        ->and(implode(' ', array_column($created, 'description')))->not->toContain('sama');
});

it('angka polos 1 digit tidak dianggap nominal (guard)', function () {
    // "beli 2 dus 5000" → hanya 5000, "2" bukan nominal.
    $amounts = BatchTransactionHelper::extractAllAmounts('beli 2 dus 5000');

    expect($amounts)->toBe([5000]);
});

it('nomor HP tidak dianggap nominal (guard leading zero)', function () {
    $amounts = BatchTransactionHelper::extractAllAmounts('bayar 50000 ke 081234567890');

    expect($amounts)->toBe([50000]);
});

it('tanggal compact 8 digit tidak ikut dijumlahkan (guard)', function () {
    $amounts = BatchTransactionHelper::extractAllAmounts('belanja 150000 27092026');

    expect($amounts)->toBe([150000]);
});

it('preservation: transaksi tunggal dengan angka polos tetap satu nominal', function () {
    // Tidak boleh berubah jadi batch/dijumlahkan.
    expect(BatchTransactionHelper::extractAmount('makan 50000'))->toBe(50000)
        ->and(BatchTransactionHelper::isBatchTransaction('makan 50000'))->toBeFalse();
});

it('preservation: angka dengan suffix tetap diprioritaskan di atas angka polos', function () {
    // "Rp 50.000" tidak boleh di-split dengan angka lain di teks.
    $amounts = BatchTransactionHelper::extractAllAmounts('makan siang Rp 50.000');

    expect($amounts)->toBe([50000]);
});
