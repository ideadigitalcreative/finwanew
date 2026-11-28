<?php

/**
 * Fase 1 (Hybrid.md): unit test HybridTransactionExtractor + OpenAIChatClient.
 * Konfigurasi di-mock (tanpa DB) → folder Unit/Services tanpa RefreshDatabase aman.
 */

use App\Services\AI\OpenAIChatClient;
use App\Services\HybridLLMConfigService;
use App\Services\Transaction\HybridExtractionResult;
use App\Services\Transaction\HybridTransactionExtractor;
use Illuminate\Support\Facades\Http;

function hybridMockConfig(bool $ready = true): Mockery\MockInterface
{
    $cfg = Mockery::mock(HybridLLMConfigService::class);
    $cfg->shouldReceive('isReady')->andReturn($ready);
    $cfg->shouldReceive('getMergedConfig')->andReturn([
        'enabled' => $ready,
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'gpt-4o-mini',
        'api_key' => 'sk-test-123',
        'timeout' => 4,
    ]);

    return $cfg;
}

function hybridExtractorWith(?Mockery\MockInterface $cfg): HybridTransactionExtractor
{
    $config = $cfg ?? hybridMockConfig();

    return new HybridTransactionExtractor(new OpenAIChatClient($config), $config);
}

function hybridLlmResponse(string $content): array
{
    return [
        'choices' => [['message' => ['content' => $content]]],
    ];
}

it('mengembalikan disabled saat konfigurasi tidak siap', function () {
    Http::fake();

    $result = hybridExtractorWith(hybridMockConfig(false))->extract('makan siang 25000');

    expect($result->status)->toBe(HybridExtractionResult::STATUS_OFF)
        ->and($result->isUsable())->toBeFalse();
    Http::assertNothingSent();
});

it('mengekstrak transaksi dari respons LLM yang valid', function () {
    Http::fake([
        '*' => Http::response(hybridLlmResponse(json_encode([
            'intent' => 'transaction',
            'type' => 'expense',
            'items' => [['amount' => 24000, 'label' => 'rokok'], ['amount' => 6000, 'label' => 'jajan']],
            'wallet' => 'Cash',
            'category' => 'Lainnya',
            'confidence' => 0.9,
        ])), 200),
    ]);

    $result = hybridExtractorWith(null)->extract('Beli rokok 24000 sama jajan 6000');

    expect($result->status)->toBe('ok')
        ->and($result->isUsable())->toBeTrue()
        ->and($result->intent)->toBe('transaction')
        ->and($result->type)->toBe('expense')
        ->and($result->amount)->toBe(30000)
        ->and($result->items)->toHaveCount(2)
        ->and($result->wallet)->toBe('Cash');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
        && $request['model'] === 'gpt-4o-mini');
});

it('membuang code fence pada respons LLM', function () {
    Http::fake([
        '*' => Http::response(hybridLlmResponse("```json\n".json_encode([
            'intent' => 'transaction',
            'type' => 'income',
            'items' => [['amount' => 150000, 'label' => null]],
            'confidence' => 0.8,
        ])."\n```"), 200),
    ]);

    $result = hybridExtractorWith(null)->extract('pemasukan cash 150000');

    expect($result->status)->toBe('ok')
        ->and($result->type)->toBe('income')
        ->and($result->amount)->toBe(150000);
});

it('mengembalikan unavailable saat JSON rusak', function () {
    Http::fake(['*' => Http::response(hybridLlmResponse('maaf saya tidak bisa'), 200)]);

    $result = hybridExtractorWith(null)->extract('makan siang 25000');

    expect($result->status)->toBe(HybridExtractionResult::STATUS_UNAVAILABLE)
        ->and($result->note)->toBe('invalid_json');
});

it('mengembalikan unavailable saat HTTP error', function () {
    Http::fake(['*' => Http::response('upstream error', 500)]);

    $result = hybridExtractorWith(null)->extract('makan siang 25000');

    expect($result->status)->toBe(HybridExtractionResult::STATUS_UNAVAILABLE)
        ->and($result->note)->toBe('llm_error');
});

it('mengembalikan unavailable saat intent transaction tanpa nominal', function () {
    Http::fake([
        '*' => Http::response(hybridLlmResponse(json_encode([
            'intent' => 'transaction',
            'type' => 'expense',
            'items' => [],
            'confidence' => 0.5,
        ])), 200),
    ]);

    $result = hybridExtractorWith(null)->extract('beli sesuatu');

    expect($result->status)->toBe(HybridExtractionResult::STATUS_UNAVAILABLE)
        ->and($result->note)->toBe('no_amount');
});

it('mengembalikan unavailable saat intent tidak dikenali', function () {
    Http::fake([
        '*' => Http::response(hybridLlmResponse(json_encode([
            'intent' => 'sirkulasi',
            'type' => 'expense',
            'items' => [['amount' => 1000, 'label' => null]],
        ])), 200),
    ]);

    $result = hybridExtractorWith(null)->extract('makan siang 25000');

    expect($result->status)->toBe(HybridExtractionResult::STATUS_UNAVAILABLE)
        ->and($result->note)->toBe('bad_intent');
});

it('mengembalikan unavailable saat koneksi gagal (exception HTTP)', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout');
    });

    $result = hybridExtractorWith(null)->extract('makan siang 25000');

    expect($result->status)->toBe(HybridExtractionResult::STATUS_UNAVAILABLE)
        ->and($result->note)->toBe('llm_error');
});

it('fallback ke field amount tunggal bila items kosong', function () {
    Http::fake([
        '*' => Http::response(hybridLlmResponse(json_encode([
            'intent' => 'transaction',
            'type' => 'expense',
            'items' => [],
            'amount' => 45000,
            'confidence' => 0.7,
        ])), 200),
    ]);

    $result = hybridExtractorWith(null)->extract('bayar listrik 45000');

    expect($result->status)->toBe('ok')
        ->and($result->amount)->toBe(45000)
        ->and($result->items)->toHaveCount(1);
});
