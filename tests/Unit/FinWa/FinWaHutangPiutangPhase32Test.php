<?php

use App\Services\DebtReceivable\CounterpartyExtractor;
use App\Services\DebtReceivable\FinWaDebtReceivableResponseNormalizer;
use App\Services\FinWaAIService;
use App\Services\Transaction\TransactionExtractorService;
use Illuminate\Support\Facades\Http;

test('CounterpartyExtractor mengenali terima pelunasan nama sebelum nominal', function () {
    expect(CounterpartyExtractor::extract('Terima pelunasan budi 150 rb'))->toBe('budi');
});

test('normalizer memaksa category_type dan slug selaras intent hutang piutang', function () {
    $out = FinWaDebtReceivableResponseNormalizer::normalize([
        'intent' => 'bayar_hutang',
        'confidence' => 0.9,
        'entities' => [
            'nominal' => 100_000,
            'category_type' => 'pengeluaran_pinjaman',
            'kategori' => 'cicilan',
        ],
    ]);

    expect($out['entities']['category_type'])->toBe('pengeluaran_bayar_hutang')
        ->and($out['entities']['category_slug'])->toBe('bayar_hutang');
});

test('normalizer tidak mengubah intent transaksi biasa', function () {
    $out = FinWaDebtReceivableResponseNormalizer::normalize([
        'intent' => 'catat_pengeluaran',
        'entities' => ['nominal' => 50_000, 'category_type' => 'pengeluaran_makanan'],
    ]);

    expect($out['entities']['category_type'])->toBe('pengeluaran_makanan');
});

test('ekstraksi lokal WA memetakan bayar hutang ke pengeluaran_bayar_hutang', function () {
    $svc = app(TransactionExtractorService::class);
    // Urutan "ke {nama} {nominal}" agar CounterpartyExtractor menangkap pihak lawan
    $row = $svc->extractTransactionLocally('bayar hutang ke budi 100rb');

    expect($row)->not->toBeNull()
        ->and($row['category_type'])->toBe('pengeluaran_bayar_hutang')
        ->and($row['type'])->toBe('expense')
        ->and($row['metadata']['counterparty'] ?? '')->toBe('budi');
});

test('ekstraksi lokal WA memetakan terima pinjaman ke pendapatan_hutang', function () {
    $svc = app(TransactionExtractorService::class);
    $row = $svc->extractTransactionLocally('terima pinjaman 2jt dari pak agus');

    expect($row)->not->toBeNull()
        ->and($row['category_type'])->toBe('pendapatan_hutang')
        ->and($row['type'])->toBe('income');
});

test('FinWaAIService mengirim core_api_contract dan menormalisasi respons', function () {
    config([
        'services.finwa_ai.url' => 'http://finwa-ai.test',
        'services.finwa_ai.enabled' => true,
        'finwa_ai_hutang_piutang.send_with_request' => true,
    ]);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        expect($request->url())->toBe('http://finwa-ai.test/process/text');
        $body = json_decode($request->body(), true);
        expect($body)->toHaveKey('core_api_contract')
            ->and($body['core_api_contract'])->toHaveKey('category_types');

        return Http::response([
            'intent' => 'terima_piutang',
            'confidence' => 0.85,
            'entities' => [
                'nominal' => 500_000,
                'category_type' => 'pendapatan_lainnya',
                'catatan' => 'pelunasan',
            ],
        ], 200);
    });

    $svc = new FinWaAIService;
    $res = $svc->processText('terima piutang 500rb dari customer');

    expect($res['success'])->toBeTrue()
        ->and($res['data']['entities']['category_type'])->toBe('pendapatan_terima_piutang')
        ->and($res['data']['entities']['category_slug'])->toBe('terima_piutang');
});

test('classifyIntent mewarisi entities ternormalisasi', function () {
    config([
        'services.finwa_ai.url' => 'http://finwa-ai.test',
        'services.finwa_ai.enabled' => true,
    ]);

    Http::fake([
        'http://finwa-ai.test/process/text' => Http::response([
            'intent' => 'catat_hutang',
            'confidence' => 0.9,
            'entities' => ['nominal' => 1_000_000, 'category_type' => 'pengeluaran_pinjaman'],
        ], 200),
    ]);

    $svc = new FinWaAIService;
    $cls = $svc->classifyIntent('pinjam dari bank 1jt');

    expect($cls['data']['entities']['category_type'])->toBe('pendapatan_hutang');
});
