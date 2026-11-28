<?php

namespace App\Services\AI;

use App\Services\HybridLLMConfigService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien tipis OpenAI Chat Completion (Hybrid.md §6A / Fase 1).
 *
 * POST {base_url}/chat/completions dengan body standar OpenAI dan header
 * Bearer. Konfigurasi prioritas: DB admin (app_settings) → env groq_llm.
 * Tidak pernah melempar exception — mengembalikan null saat gagal supaya
 * pemanggil (extractor) bisa fallback ke rules.
 */
class OpenAIChatClient
{
    /** Ringkasan kegagalan panggilan terakhir (untuk diagnosa; tanpa API key). */
    public static ?string $lastError = null;

    public function __construct(
        protected HybridLLMConfigService $configService = new HybridLLMConfigService,
        // true = mode Chat LLM: pakai flag & timeout chat, bukan flag ekstraksi hybrid
        protected bool $chatMode = false,
    ) {}

    /**
     * Panggil chat completion.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return string|null isi `choices[0].message.content`, atau null bila gagal
     */
    public function complete(
        array $messages,
        ?int $maxTokens = 500,
        float $temperature = 0.0,
        ?array $extraParams = null,
    ): ?string {
        $cfg = $this->configService->getMergedConfig();
        self::$lastError = null;

        $ready = $this->chatMode ? $this->configService->isChatReady() : $this->configService->isReady();
        if (! $ready) {
            self::$lastError = 'konfigurasi LLM belum siap';

            return null;
        }
        if ($this->chatMode) {
            $cfg['timeout'] = $this->configService->chatTimeout();
        }

        $payload = [
            'model' => $cfg['model'],
            'messages' => $messages,
            'temperature' => $temperature,
            // Beberapa proxy meng-stream SSE meski tidak diminta → buang chunk.
            'stream' => false,
        ];
        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }
        if ($extraParams !== null) {
            $payload = array_merge($payload, $extraParams);
        }

        $result = $this->send($cfg, $payload);

        // LLM utama down (5xx/429/koneksi) → coba LLM cadangan sekali.
        if ($result === null && $this->isRetryable(self::$lastError) && ($fallback = self::fallbackEndpoint($cfg)) !== null) {
            $primaryError = self::$lastError;
            Log::warning('OpenAIChatClient: LLM utama gagal, mencoba cadangan', [
                'primary_model' => $cfg['model'],
                'fallback_model' => $fallback['model'],
                'error' => mb_substr((string) $primaryError, 0, 120),
            ]);
            $payload['model'] = $fallback['model'];
            $result = $this->send(array_merge($cfg, $fallback), $payload);
            if ($result === null) {
                self::$lastError = "utama: {$primaryError} | cadangan: ".self::$lastError;
            }
        }

        return $result;
    }

    /**
     * Endpoint cadangan (config services.finwa_llm_fallback) bila lengkap & berbeda dari utama.
     *
     * @return array{base_url: string, model: string, api_key: string}|null
     */
    public static function fallbackEndpoint(array $primary): ?array
    {
        $fb = [
            'base_url' => trim((string) config('services.finwa_llm_fallback.base_url', '')),
            'model' => trim((string) config('services.finwa_llm_fallback.model', '')),
            'api_key' => trim((string) config('services.finwa_llm_fallback.api_key', '')),
        ];
        if ($fb['base_url'] === '' || $fb['model'] === '' || $fb['api_key'] === '') {
            return null;
        }
        if (rtrim($fb['base_url'], '/') === rtrim((string) $primary['base_url'], '/') && $fb['model'] === $primary['model']) {
            return null;
        }

        return $fb;
    }

    private function isRetryable(?string $error): bool
    {
        return $error !== null && (bool) preg_match('/^(?:HTTP (?:429|5\d\d)|koneksi gagal)/', $error);
    }

    private function send(array $cfg, array $payload): ?string
    {
        // Gemini 2.5 (endpoint OpenAI-kompatibel) "berpikir" dulu: token jawaban habis untuk
        // reasoning → konten KOSONG (finish_reason=length) & lambat. Matikan untuk Flash/Flash-Lite
        // (2.5 Pro tidak bisa dimatikan). Model lain tidak terpengaruh.
        if (preg_match('/^(?:models\/)?gemini-2\.5-(?!pro)/i', (string) ($payload['model'] ?? ''))
            && ! array_key_exists('reasoning_effort', $payload)) {
            $payload['reasoning_effort'] = 'none';
        }

        try {
            $response = Http::timeout($cfg['timeout'])
                ->withHeaders([
                    'Authorization' => 'Bearer '.$cfg['api_key'],
                    'Content-Type' => 'application/json',
                ])
                ->post(rtrim($cfg['base_url'], '/').'/chat/completions', $payload);

            if (! $response->successful()) {
                self::$lastError = 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300);
                Log::warning('OpenAIChatClient: HTTP error', [
                    'status' => $response->status(),
                    'base_url' => $cfg['base_url'],
                    'model' => $cfg['model'],
                    // JANGAN log api_key (§6A keamanan).
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                return null;
            }

            $content = $response->json('choices.0.message.content');
            if (is_array($content)) {
                // Beberapa provider mengirim content sebagai array part [{type:text,text:..}]
                $content = implode('', array_map(fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : (string) $p, $content));
            }

            if (! is_string($content) || trim($content) === '') {
                // 200 OK tapi tanpa jawaban (mis. model "reasoning" yang token-nya habis
                // untuk berpikir, atau format respons berbeda) — dulu gagal diam-diam.
                $finish = $response->json('choices.0.finish_reason');
                self::$lastError = 'HTTP 200 tanpa isi jawaban'
                    .($finish ? " (finish_reason={$finish})" : '')
                    .' — respons: '.mb_substr($response->body(), 0, 300);
                Log::warning('OpenAIChatClient: respons kosong', [
                    'base_url' => $cfg['base_url'],
                    'model' => $cfg['model'],
                    'finish_reason' => $finish,
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                return null;
            }

            return $content;
        } catch (\Throwable $e) {
            self::$lastError = 'koneksi gagal: '.$e->getMessage();
            Log::warning('OpenAIChatClient: connection error', [
                'error' => $e->getMessage(),
                'base_url' => $cfg['base_url'],
            ]);

            return null;
        }
    }
}
