<?php

/**
 * Fase 3 (Hybrid.md): circuit breaker LLM hybrid.
 *
 * Unit tanpa DB (Cache array store). Time-travel via Carbon::setTestNow
 * untuk membuktikan jendela 1 menit & cooldown 5 menit.
 */

use App\Services\AI\OpenAIChatClient;
use App\Services\HybridLLMConfigService;
use App\Services\Transaction\HybridCircuitBreaker;
use App\Services\Transaction\HybridExtractionResult;
use App\Services\Transaction\HybridTransactionExtractor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    Carbon::setTestNow();
    Cache::flush(); // state breaker persisten antar test dalam satu proses.
});

function hyb3BreakerConfig(): Mockery\MockInterface
{
    $cfg = Mockery::mock(HybridLLMConfigService::class);
    $cfg->shouldReceive('isReady')->andReturn(true);
    $cfg->shouldReceive('getMergedConfig')->andReturn([
        'enabled' => true,
        'base_url' => 'https://api.test/v1',
        'model' => 'test-model',
        'api_key' => 'test-key',
        'timeout' => 4,
    ]);

    return $cfg;
}

// ── Breaker murni ──────────────────────────────────────────────────

it('default tertutup (belum pernah gagal)', function () {
    expect((new HybridCircuitBreaker)->isOpen())->toBeFalse();
});

it('membuka sirkuit pada kegagalan ke-3 dalam jendela', function () {
    $breaker = new HybridCircuitBreaker;

    $breaker->recordFailure();
    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeFalse();

    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeTrue();
});

it('keberhasilan me-reset penghitung kegagalan', function () {
    $breaker = new HybridCircuitBreaker;

    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordSuccess();
    $breaker->recordFailure();

    expect($breaker->isOpen())->toBeFalse();
});

it('kegagalan di luar jendela 1 menit tidak dihitung', function () {
    $breaker = new HybridCircuitBreaker;

    $breaker->recordFailure();
    $breaker->recordFailure();
    Carbon::setTestNow(now()->addSeconds(61));
    $breaker->recordFailure(); // kegagalan pertama pada jendela baru

    expect($breaker->isOpen())->toBeFalse();

    $breaker->recordFailure();
    $breaker->recordFailure();

    expect($breaker->isOpen())->toBeTrue();
});

it('menutup kembali setelah cooldown 5 menit', function () {
    $breaker = new HybridCircuitBreaker;
    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeTrue();

    Carbon::setTestNow(now()->addSeconds(301));
    expect($breaker->isOpen())->toBeFalse();
});

// ── Integrasi extractor + breaker ──────────────────────────────────

it('extractor: 3x LLM error → panggilan ke-4 tidak menyentuh HTTP (breaker)', function () {
    Http::fake(['*' => Http::response('upstream down', 500)]);
    $cfg = hyb3BreakerConfig();
    $extractor = new HybridTransactionExtractor(
        new OpenAIChatClient($cfg),
        $cfg,
        new HybridCircuitBreaker,
    );

    foreach (range(1, 3) as $i) {
        $result = $extractor->extract('saya punya 128k');
        expect($result->note)->toBe('llm_error');
    }

    $open = $extractor->extract('saya punya 128k');
    expect($open->status)->toBe(HybridExtractionResult::STATUS_UNAVAILABLE)
        ->and($open->note)->toBe('breaker_open');
    Http::assertSentCount(3);
});

it('extractor: respons sukses mengembalikan sirkuit ke normal', function () {
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'intent' => 'transaction',
            'type' => 'income',
            'items' => [['amount' => 128000]],
            'confidence' => 0.95,
        ])]]],
    ], 200)]);
    $cfg = hyb3BreakerConfig();
    $extractor = new HybridTransactionExtractor(
        new OpenAIChatClient($cfg),
        $cfg,
        new HybridCircuitBreaker,
    );

    $extractor->extract('saya punya 128k'); // sukses
    $extractor->extract('saya punya 128k');

    Http::assertSentCount(2);
});
