<?php

/**
 * Gemini lewat endpoint OpenAI-kompatibel (mis. proxy Cloudflare Worker .../v1beta/openai):
 * Gemini 2.5 Flash "berpikir" dulu → dengan max_tokens kecil jawabannya KOSONG
 * (tombol Test Hybrid LLM: "Koneksi gagal"). Mode berpikir dimatikan otomatis.
 */

use App\Services\AI\OpenAIChatClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function geminiCompatSetup(string $model): void
{
    config([
        'services.finwa_hybrid_llm.enabled' => true,
        'services.groq_llm.base_url' => 'https://geminiworker.example.workers.dev/v1beta/openai',
        'services.groq_llm.model' => $model,
        'services.groq_llm.api_keys' => 'test-key',
        'services.finwa_llm_fallback.base_url' => '',
    ]);
}

it('model Gemini 2.5 Flash → reasoning_effort none dikirim', function (string $model) {
    geminiCompatSetup($model);
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]], 200)]);

    $reply = (new OpenAIChatClient)->complete([['role' => 'user', 'content' => 'Balas OK']], maxTokens: 20);

    expect($reply)->toBe('OK');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1beta/openai/chat/completions')
        && ($r->data()['reasoning_effort'] ?? null) === 'none'
        && $r->hasHeader('Authorization', 'Bearer test-key'));
})->with(['gemini-2.5-flash', 'gemini-2.5-flash-lite']);

it('model lain (Groq/Llama, Gemini Pro) tidak diberi reasoning_effort', function (string $model) {
    geminiCompatSetup($model);
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]], 200)]);

    (new OpenAIChatClient)->complete([['role' => 'user', 'content' => 'Balas OK']], maxTokens: 20);

    Http::assertSent(fn (Request $r) => ! array_key_exists('reasoning_effort', $r->data()));
})->with(['llama-3.1-8b-instant', 'gemini-2.5-pro', 'deepseek-chat']);

it('jawaban kosong → pesan error menyebut penyebabnya (untuk tombol Test)', function () {
    geminiCompatSetup('gemini-2.5-pro');
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => null], 'finish_reason' => 'length']]], 200)]);

    expect((new OpenAIChatClient)->complete([['role' => 'user', 'content' => 'x']], maxTokens: 20))->toBeNull()
        ->and(OpenAIChatClient::$lastError)->toContain('finish_reason=length');
});
