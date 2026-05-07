<?php

namespace App\Modules\RisenAI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    private string $apiKey;

    private string $model;

    private string $baseUrl = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('risen-ai.openrouter_api_key');
        $this->model = config('risen-ai.model');
    }

    /**
     * Kirim prompt ke OpenRouter, return array hasil parsing JSON.
     * Dipakai oleh semua modul MOD-01 sampai MOD-06.
     */
    public function complete(
        string $systemPrompt,
        string $userPrompt,
        int $maxTokens = 4000
    ): array {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])->timeout(120)->post($this->baseUrl, [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

            if ($response->failed()) {
                Log::error('RisenAI: OpenRouter API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception(
                    'OpenRouter API gagal: '.$response->status().' - '.$response->body()
                );
            }

            $content = $response->json('choices.0.message.content');

            if (! $content) {
                Log::warning('RisenAI: OpenRouter response empty content', [
                    'response' => $response->json(),
                ]);

                return [];
            }

            // Bersihkan markdown code fences jika ada
            $content = preg_replace('/```json\s*|\s*```/', '', $content);
            $content = trim($content);

            $decoded = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('RisenAI: JSON decode error', [
                    'error' => json_last_error_msg(),
                    'content' => $content,
                ]);

                return [];
            }

            return $decoded ?? [];

        } catch (\Exception $e) {
            Log::error('RisenAI: OpenRouterService error', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
