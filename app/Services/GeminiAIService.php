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
 * Konfigurasi model/base_url/API key: .env + override dinamis Super Admin (GeminiConfigService).
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
                        'maxOutputTokens' => 2048,
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

            Log::debug('GeminiAIService: extractReceiptData raw response', [
                'text' => mb_substr($textResponse, 0, 800),
            ]);

            $parsed = json_decode($textResponse, true);

            if (! $parsed && preg_match('/\{.*\}/s', $textResponse, $matches)) {
                $parsed = json_decode($matches[0], true);
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
Anda adalah sistem ekstraksi data otomatis yang sangat akurat. Tugas Anda adalah membaca dan menganalisis gambar struk belanja yang diberikan, lalu mengubah informasinya menjadi data terstruktur.

KONTEKS PENTING:
- Hari ini adalah {$today}.
- Struk ini berasal dari Indonesia.
- Format tanggal Indonesia pada struk biasanya DD-MM-YYYY atau DD/MM/YYYY (hari-bulan-tahun), BUKAN MM-DD-YYYY.
- Jika Anda menemukan tanggal seperti "01-05-2026" atau "01/05/2026", itu artinya tanggal 1 Mei 2026 (DD-MM-YYYY), BUKAN 5 Januari.
- Tahun transaksi kemungkinan besar {$currentYear} atau tahun sebelumnya. Jika Anda membaca tahun yang jauh di masa lalu (misalnya 2001, 2005), kemungkinan besar itu salah baca — periksa ulang.

Keluarkan hasil ekstrak data HANYA dalam format JSON yang valid, tanpa tambahan teks pengantar atau penutup apa pun (jangan gunakan markdown seperti ```json).

Aturan Ekstraksi:
1. "merchant_name": Nama toko atau tempat transaksi.
2. "date": Tanggal transaksi dalam format "YYYY-MM-DD". PERHATIAN: Format tanggal di struk Indonesia adalah DD-MM-YYYY. Jadi "01-05-2026" harus dikonversi menjadi "2026-05-01". Jika tidak ada tanggal, isi null.
3. "time": Waktu transaksi dalam format "HH:MM". Jika tidak ada, isi null.
4. "items": Array of object berisi daftar barang yang DIBELI SAJA (line items). Terdiri dari:
   - "name": nama barang (tanpa kode baris seperti "A", "B", "C" di depannya jika itu hanya penanda kategori)
   - "qty": jumlah barang (angka; jika tidak ada, isi 1)
   - "price": harga TOTAL per baris barang tersebut (angka murni). Jika struk menulis "2x 4.000" maka qty=2 dan price=8000.
   Jangan masukkan baris non-item sebagai items, termasuk: TOTAL, SUBTOTAL, GRAND TOTAL, PAJAK/TAX, DISKON, BAYAR, TUNAI/CASH, KEMBALI/CHANGE, NOMOR/NO/REF, PASSWORD, WIFI, TERIMA KASIH, alamat, nomor telepon.
5. "tax": Jumlah pajak (jika tertera). Jika tidak ada, isi 0.
6. "total_amount": Total akhir yang harus dibayar. Harus berupa angka murni (misal: 150000). Jangan sertakan simbol mata uang ("Rp") atau titik/koma pemisah ribuan.
7. Pastikan items TIDAK mengandung baris "Total" apa pun. Total hanya ada di field total_amount.
8. Jika ada informasi yang sama sekali tidak terbaca atau tidak ada di struk, berikan nilai null.

Gunakan struktur JSON berikut:
{
  "merchant_name": "",
  "date": "",
  "time": "",
  "items": [
    {
      "name": "",
      "qty": 0,
      "price": 0
    }
  ],
  "tax": 0,
  "total_amount": 0
}
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
