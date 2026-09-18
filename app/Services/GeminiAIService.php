<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeminiAIService - Handles interaction with Google Gemini API
 *
 * Used for:
 * 1. Direct receipt image extraction (Gemini Vision) — primary
 * 2. Receipt parsing fallback
 *
 * Konfigurasi model/base_url/API key: .env + overraide dinamis Super Admin (GeminiConfigService).
 */
class GeminiAIService
{
    public function __construct(
        protected GeminiConfigService $config
    ) {}

    /**
     * Check if service is available
     */
    public function isAvailable(): bool
    {
        return $this->config->hasAnyApiKey();
    }

    protected function model(): string
    {
        return $this->config->getMergedConfig()['model'];
    }

    protected function timeout(): int
    {
        return $this->config->getMergedConfig()['timeout'];
    }

    protected function customBaseUrl(): string
    {
        return $this->config->getMergedConfig()['base_url'];
    }

    /**
     * Build the Gemini API base URL.
     *
     * Priority:
     * 1. Custom proxy URL from GEMINI_BASE_URL env (untuk bypass region block)
     * 2. Auto-detect: gemini-2.x → v1beta, lainnya → v1
     *
     * Contoh proxy URL:
     *   https://your-proxy.example.com/v1beta/models
     *   https://generativelanguage.googleapis.com/v1beta/models (default)
     */
    protected function getBaseUrl(): string
    {
        $custom = $this->customBaseUrl();
        if (! empty($custom)) {
            return rtrim($custom, '/');
        }

        $model = $this->model();
        if (str_starts_with($model, 'gemini-2')) {
            return 'https://generativelanguage.googleapis.com/v1beta/models';
        }

        return 'https://generativelanguage.googleapis.com/v1/models';
    }

    /**
     * Extract structured receipt data directly from image using Gemini Vision.
     * Skips OCR entirely — sends the raw image to Gemini and gets structured JSON back.
     *
     * @param  string  $base64Image  Raw base64 string or data-URI
     * @return array|null Parsed receipt data or null on failure
     */
    public function extractReceiptData(string $base64Image): ?array
    {
        if (! $this->isAvailable()) {
            Log::warning('GeminiAIService: API key not configured');

            return null;
        }

        try {
            $mimeType = 'image/jpeg';
            $data = $base64Image;

            if (preg_match('/^data:(image\/[a-z]+);base64,(.*)$/i', $base64Image, $matches)) {
                $mimeType = $matches[1];
                $data = $matches[2];
            }

            $model = $this->model();
            $fullUrl = "{$this->getBaseUrl()}/{$model}:generateContent";

            Log::info('GeminiAIService: extractReceiptData called', [
                'model' => $model,
                'image_size' => strlen($data),
                'base_url' => $this->getBaseUrl(),
                'custom_base_url' => $this->customBaseUrl(),
                'full_url' => $fullUrl,
            ]);

            $prompt = $this->getReceiptExtractionPrompt();

            $response = Http::timeout($this->timeout())
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->config->nextRotatedApiKey(),
                ])
                ->post("{$this->getBaseUrl()}/{$model}:generateContent", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                                [
                                    'inline_data' => [
                                        'mime_type' => $mimeType,
                                        'data' => $data,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'maxOutputTokens' => 8192,
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('GeminiAIService: extractReceiptData API failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $result = $response->json();
            $textResponse = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $finishReason = $result['candidates'][0]['finishReason'] ?? 'STOP';

            Log::debug('GeminiAIService: extractReceiptData raw response', [
                'text' => mb_substr($textResponse, 0, 1500),
                'finish_reason' => $finishReason,
                'text_length' => strlen($textResponse),
            ]);

            // Warn if output was truncated
            if ($finishReason === 'MAX_TOKENS') {
                Log::warning('GeminiAIService: response truncated (MAX_TOKENS) — receipt may be too long', [
                    'text_length' => strlen($textResponse),
                ]);
            }

            $parsed = json_decode($textResponse, true);

            if (! $parsed && preg_match('/\{.*\}/s', $textResponse, $matches)) {
                $parsed = json_decode($matches[0], true);
            }

            // If JSON is truncated (usually from MAX_TOKENS), try to repair it
            if (! $parsed && ! empty($textResponse)) {
                $parsed = $this->repairTruncatedJson($textResponse);
                if ($parsed) {
                    Log::info('GeminiAIService: recovered data from truncated JSON response');
                }
            }

            if ($parsed) {
                Log::info('GeminiAIService: extractReceiptData success', [
                    'merchant' => $parsed['merchant_name'] ?? 'N/A',
                    'total' => $parsed['total_amount'] ?? 0,
                    'items_count' => count($parsed['items'] ?? []),
                ]);
            }

            return $parsed;

        } catch (\Exception $e) {
            Log::error('GeminiAIService: extractReceiptData error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Prompt khusus untuk ekstraksi data struk — output JSON murni tanpa markdown.
     */
    protected function getReceiptExtractionPrompt(): string
    {
        $today = now()->format('Y-m-d');
        $currentYear = now()->format('Y');

        return <<<PROMPT
Ekstrak data dari gambar dokumen keuangan Indonesia (struk/bukti transfer) ke JSON.
KONTEKS: Hari ini {$today}, tahun {$currentYear}. Format tanggal DD-MM-YYYY.
Output HANYA JSON, tanpa teks lain.

STRUKTUR JSON:
{
  "document_type": "receipt|bank_transfer|unknown",
  "merchant_name": "",
  "date": "YYYY-MM-DD|null",
  "time": "HH:MM|null",
  "items": [{"name":"","qty":1,"price":0}],
  "tax": 0,
  "total_amount": 0,
  "bank_name": null,
  "sender_name": null,
  "sender_account": null,
  "recipient_name": null,
  "recipient_account": null,
  "reference_number": null,
  "transfer_note": null
}

ATURAN:
- receipt: nama toko, items barang dibeli saja, total akhir
- bank_transfer: nama bank/e-wallet, nama penerima WAJIB, nominal transfer, merchant_name = recipient_name, items = []
- unknown: semua field null/kosong
PROMPT;
    }

    /**
     * Parse receipt using Gemini Vision (legacy — from Image or Text)
     */
    public function parseReceipt(?string $base64Image = null, ?string $rawText = null): ?array
    {
        if (! $this->isAvailable()) {
            Log::warning('GeminiAIService is not configured with API key');

            return null;
        }

        try {
            $prompt = $this->getLegacyReceiptPrompt($rawText);

            $parts = [['text' => $prompt]];

            if ($base64Image) {
                $mimeType = 'image/jpeg';
                $data = $base64Image;

                if (preg_match('/^data:(image\/[a-z]+);base64,(.*)$/i', $base64Image, $matches)) {
                    $mimeType = $matches[1];
                    $data = $matches[2];
                }

                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => $data,
                    ],
                ];
            }

            $model = $this->model();

            $response = Http::timeout($this->timeout())
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->config->nextRotatedApiKey(),
                ])
                ->post("{$this->getBaseUrl()}/{$model}:generateContent", [
                    'contents' => [['parts' => $parts]],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'maxOutputTokens' => 1000,
                    ],
                ]);

            if ($response->successful()) {
                $result = $response->json();
                $textResponse = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                $parsed = json_decode($textResponse, true);
                if ($parsed) {
                    return $parsed;
                }

                if (preg_match('/\{.*\}/s', $textResponse, $matches)) {
                    return json_decode($matches[0], true);
                }
            } else {
                Log::error('Gemini API call failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('GeminiAIService error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Attempt to repair a truncated JSON response from Gemini.
     * Common when MAX_TOKENS is hit on long receipts — JSON gets cut mid-array.
     *
     * Strategy:
     * 1. Extract the main JSON object
     * 2. Close any open arrays/objects
     * 3. Re-parse
     */
    protected function repairTruncatedJson(string $text): ?array
    {
        try {
            // Extract from first { to end
            $start = strpos($text, '{');
            if ($start === false) {
                return null;
            }
            $json = substr($text, $start);

            // Remove any trailing markdown/text after the last meaningful char
            $json = rtrim($json, "` \t\n\r");

            // Count unmatched braces and brackets
            $openBraces = substr_count($json, '{') - substr_count($json, '}');
            $openBrackets = substr_count($json, '[') - substr_count($json, ']');

            // If we're inside a string value, try to close it
            // Simple heuristic: count unescaped quotes
            $quoteCount = preg_match_all('/(?<!\\\\)"/', $json);
            if ($quoteCount % 2 !== 0) {
                $json .= '"';
            }

            // Remove any trailing comma before we close brackets/braces
            $json = preg_replace('/,\s*$/', '', $json);

            // Close open brackets and braces
            $json .= str_repeat(']', max(0, $openBrackets));
            $json .= str_repeat('}', max(0, $openBraces));

            $parsed = json_decode($json, true);
            if ($parsed && ! empty($parsed['total_amount'])) {
                Log::info('GeminiAIService: truncated JSON repair succeeded', [
                    'merchant' => $parsed['merchant_name'] ?? 'N/A',
                    'total' => $parsed['total_amount'],
                    'items_count' => count($parsed['items'] ?? []),
                ]);

                return $parsed;
            }

            return null;
        } catch (\Throwable $e) {
            Log::debug('GeminiAIService: JSON repair failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Validate and correct transaction category using Gemini AI
     *
     * @param string $transactionText Raw transaction text from WhatsApp
     * @param string $intentType 'income' or 'expense'
     * @param string|null $suggestedCategoryType Locally suggested category type
     * @param array $availableCategories Available category configuration
     * @return array{category_type: string, confidence: float, corrected: bool, reason: string}|null
     */
    public function validateCategory(string $transactionText, string $intentType, ?string $suggestedCategoryType, array $availableCategories): ?array
    {
        if (!$this->isAvailable()) {
            Log::warning('GeminiAIService: validateCategory - API not available');
            return null;
        }

        try {
            // Filter categories by intent type to reduce token usage
            $filteredCategories = [];
            foreach ($availableCategories as $type => $config) {
                if ($config['type'] === $intentType) {
                    $filteredCategories[$type] = $config;
                }
            }

            if (empty($filteredCategories)) {
                Log::warning('GeminiAIService: No categories available for intent type', ['intent' => $intentType]);
                return null;
            }

            $model = $this->model();
            $prompt = $this->getCategoryValidationPrompt($transactionText, $intentType, $suggestedCategoryType, $filteredCategories);

            Log::info('GeminiAIService: validateCategory called', [
                'transaction_text' => $transactionText,
                'intent_type' => $intentType,
                'suggested_category' => $suggestedCategoryType,
                'available_categories_count' => count($filteredCategories),
            ]);

            $response = Http::timeout($this->timeout())
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->config->nextRotatedApiKey(),
                ])
                ->post("{$this->getBaseUrl()}/{$model}:generateContent", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'maxOutputTokens' => 1024,
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('GeminiAIService: validateCategory API failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $result = $response->json();
            $textResponse = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $finishReason = $result['candidates'][0]['finishReason'] ?? 'STOP';

            Log::debug('GeminiAIService: validateCategory raw response', [
                'text' => mb_substr($textResponse, 0, 1500),
                'finish_reason' => $finishReason,
            ]);

            $parsed = json_decode($textResponse, true);

            if (!$parsed && preg_match('/\{.*\}/s', $textResponse, $matches)) {
                $parsed = json_decode($matches[0], true);
            }

            if (!$parsed || !isset($parsed['category_type'])) {
                Log::warning('GeminiAIService: Could not parse valid JSON response', ['response' => $textResponse]);
                return null;
            }

            Log::info('GeminiAIService: validateCategory success', [
                'original_category' => $suggestedCategoryType,
                'final_category' => $parsed['category_type'],
                'corrected' => $parsed['corrected'] ?? false,
                'confidence' => $parsed['confidence'] ?? 0.5,
            ]);

            return [
                'category_type' => $parsed['category_type'],
                'confidence' => $parsed['confidence'] ?? 0.5,
                'corrected' => $parsed['corrected'] ?? false,
                'reason' => $parsed['reason'] ?? '',
            ];

        } catch (\Exception $e) {
            Log::error('GeminiAIService: validateCategory error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Get category validation prompt for Gemini
     */
    protected function getCategoryValidationPrompt(string $transactionText, string $intentType, ?string $suggestedCategoryType, array $availableCategories): string
    {
        $categoryList = [];
        foreach ($availableCategories as $type => $config) {
            $keywords = !empty($config['keywords']) ? $config['keywords'] : ['-'];
            $categoryList[] = "- {$type}: {$config['name']} (keywords: " . implode(', ', $keywords) . ")";
        }

        $categoryListStr = implode("\n", $categoryList);
        $intentTypeLabel = $intentType === 'income' ? 'pendapatan' : 'pengeluaran';
        $suggestedCategoryDisplay = $suggestedCategoryType ? $suggestedCategoryType : 'Tidak ada';

        $prompt = <<<PROMPT
Anda adalah klasifikasi transaksi keuangan Indonesia. Pilih kategori paling tepat dari daftar berdasarkan makna teks, bukan keyword literal.

Teks: {$transactionText}
Tipe: {$intentTypeLabel}
Saran lokal: {$suggestedCategoryDisplay}

Kategori:
{$categoryListStr}

Aturan penting:
- Nama makanan/minuman Indonesia → pengeluaran_makanan
- Komponen kendaraan (oli, ban, aki) → pengeluaran_otomotif
- Jika saran lokal salah, koreksi dengan corrected:true
- Jika saran lokal benar, corrected:false

Output JSON saja:
{"category_type":"...","confidence":0.95,"corrected":true,"reason":"alasan singkat"}
PROMPT;

        return $prompt;
    }

    /**
     * Legacy prompt (kept for backward compatibility)
     */
    protected function getLegacyReceiptPrompt(?string $rawText = null): string
    {
        $prompt = 'Extract transaction data from this Indonesian shopping receipt. '
            .'Output ONLY valid JSON with these fields: '
            .'is_receipt (boolean), '
            .'merchant (string, store name), '
            .'nominal (integer, TOTAL amount paid), '
            .'date (string YYYY-MM-DD), '
            .'items (array of {name: string, price: integer}), '
            .'category (string: belanja|makanan|transportasi|hiburan|kesehatan|pendidikan|tagihan|lainnya), '
            .'confidence (float 0.0-1.0). ';

        if ($rawText) {
            $prompt .= "\n\nRaw text context from OCR:\n".$rawText;
        }

        return $prompt;
    }
}
