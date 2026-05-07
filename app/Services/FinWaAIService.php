<?php

namespace App\Services;

use App\Services\DebtReceivable\FinWaDebtReceivableResponseNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FinWa-AI v2.1 Service
 *
 * Lightweight, rule-based NLU engine for processing WhatsApp finance messages.
 * This service provides fast, deterministic intent classification and entity extraction
 * without requiring external LLM APIs.
 *
 * Features:
 * - Intent Classification (catat_pengeluaran, catat_pemasukan, cek_cashflow, etc.)
 * - Named Entity Recognition (nominal, kategori, merchant, tanggal)
 * - OCR Receipt Parsing
 * - Number normalization (25rb → 25000)
 * - Date normalization (kemarin → YYYY-MM-DD)
 */
class FinWaAIService
{
    protected string $baseUrl;

    protected int $timeout;

    protected bool $enabled;

    public function __construct()
    {
        $this->baseUrl = config('services.finwa_ai.url', 'http://localhost:8000');
        $this->timeout = (int) config('services.finwa_ai.timeout', 30);
        $this->enabled = config('services.finwa_ai.enabled', true);
    }

    /**
     * Check if FinWa-AI service is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Health check - verify FinWa-AI service is running
     */
    public function healthCheck(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/health");

            return $response->successful() && ($response->json()['status'] ?? '') === 'healthy';
        } catch (\Exception $e) {
            Log::warning('FinWa-AI health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Process text message from WhatsApp
     *
     * @param  string  $message  The user's WhatsApp message
     * @param  string|null  $userId  Unique user identifier for context
     * @return array Structured result with intent and entities
     */
    public function processText(string $message, ?string $userId = null, ?string $conversationContext = null): array
    {
        try {
            Log::info('FinWa-AI processing text', [
                'message_preview' => mb_substr($message, 0, 100),
                'user_id' => $userId,
                'has_context' => ! empty($conversationContext),
            ]);

            $payload = [
                'message' => $message,
                'user_id' => $userId,
            ];

            if (! empty($conversationContext)) {
                $payload['context'] = $conversationContext;
            }

            if (config('finwa_ai_hutang_piutang.send_with_request', true)) {
                $payload['core_api_contract'] = array_merge(
                    config('finwa_ai_hutang_piutang.remote_contract', []),
                    [
                        'nlu_prompt_snippet' => config('finwa_ai_hutang_piutang.nlu_prompt_snippet'),
                    ]
                );
            }

            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/process/text", $payload);

            if ($response->successful()) {
                $result = $response->json();
                if (is_array($result)) {
                    $result = FinWaDebtReceivableResponseNormalizer::normalize($result);
                }

                Log::info('FinWa-AI text processing complete', [
                    'intent' => $result['intent'] ?? 'unknown',
                    'confidence' => $result['confidence'] ?? 0,
                    'nominal' => $result['entities']['nominal'] ?? null,
                    'has_suggestion' => isset($result['suggestion']),
                ]);

                return [
                    'success' => true,
                    'data' => $result,
                ];
            }

            Log::error('FinWa-AI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $this->errorResponse('FinWa-AI returned status '.$response->status());

        } catch (\Exception $e) {
            Log::error('FinWa-AI connection error', ['error' => $e->getMessage()]);

            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Process image for OCR and entity extraction
     *
     * @param  string  $base64Image  Base64 encoded image
     * @return array Processing result
     */
    public function processImage(string $base64Image): array
    {
        try {
            Log::info('FinWa-AI processing image (size: '.strlen($base64Image).' bytes)...');

            // Some APIs expect raw base64 without the data URI scheme header
            $rawBase64 = $base64Image;
            if (str_contains($base64Image, 'base64,')) {
                $rawBase64 = explode('base64,', $base64Image)[1];
            }

            $response = Http::timeout($this->timeout * 2)
                ->post("{$this->baseUrl}/process/image", [
                    'image' => $base64Image,       // Format 1: Full Data URI
                    'image_base64' => $rawBase64,  // Format 2: Raw Base64
                    'file' => $rawBase64,          // Format 3: Common alias
                    'ocr_engine' => 'paddle',       // Request PaddleOCR specifically
                ]);

            if ($response->successful()) {
                $result = $response->json();
                Log::info('FinWa-AI image processing successful', [
                    'items_count' => count($result['entities']['items'] ?? []),
                    'total' => $result['entities']['nominal'] ?? null,
                ]);

                return [
                    'success' => true,
                    'data' => $result,
                ];
            }

            Log::error('FinWa-AI image processing API error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);

            return $this->errorResponse('FinWa-AI returned status '.$response->status());

        } catch (\Throwable $e) {
            // Catch both Exception and Error (e.g. fatal PHP errors)
            Log::error('FinWa-AI image processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Process raw OCR text
     *
     * @param  string  $text  OCR text
     * @return array Processing result
     */
    public function processOCR(string $text): array
    {
        try {
            Log::info('FinWa-AI processing OCR text...');

            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/process/ocr", [
                    'text' => $text,
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            Log::error('FinWa-AI OCR processing API error', [
                'status' => $response->status(),
            ]);

            return $this->errorResponse('Status '.$response->status());

        } catch (\Exception $e) {
            Log::error('FinWa-AI OCR processing error', ['error' => $e->getMessage()]);

            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Classify intent from message text
     * Maps FinWa-AI intents to the existing application's intent types
     *
     * @param  string  $messageText  The user's message
     * @param  string|null  $userId  Unique user identifier for context
     * @return array Intent classification result
     */
    public function classifyIntent(string $messageText, ?string $userId = null, ?string $conversationContext = null): array
    {
        $result = $this->processText($messageText, $userId, $conversationContext);

        if (! $result['success']) {
            // Fallback to unknown
            return [
                'success' => true,
                'data' => [
                    'intent' => 'transaction', // Default fallback
                    'confidence' => 0.5,
                ],
            ];
        }

        $finwaIntent = $result['data']['intent'] ?? 'unknown';
        $confidence = $result['data']['confidence'] ?? 0.5;

        // Map FinWa-AI intents to existing app intents
        $mappedIntent = $this->mapIntent($finwaIntent);

        return [
            'success' => true,
            'data' => [
                'intent' => $mappedIntent,
                'finwa_intent' => $finwaIntent, // Original FinWa intent for reference
                'confidence' => $confidence,
                'entities' => $result['data']['entities'] ?? [],
                'sentiment' => $result['data']['sentiment'] ?? ($result['sentiment'] ?? null),
                'suggestion' => $result['data']['suggestion'] ?? ($result['suggestion'] ?? null),
            ],
        ];
    }

    /**
     * Extract transaction data from message
     * Compatible with existing AIProcessorService format
     *
     * @param  int  $tenantId  The tenant ID
     * @param  int  $messageId  The message ID
     * @param  string  $messageText  The message text
     * @return array Extracted transaction data
     */
    public function extractTransaction(int $tenantId, int $messageId, string $messageText): array
    {
        $result = $this->processText($messageText);

        if (! $result['success']) {
            return $result;
        }

        $data = $result['data'];
        $entities = $data['entities'] ?? [];
        $intent = $data['intent'] ?? 'unknown';

        // Determine transaction type
        $type = 'expense';
        if ($intent === 'catat_pemasukan') {
            $type = 'income';
        }

        // Refine category based on specific keywords (Fix for AI misclassification)
        $description = $entities['catatan'] ?? $messageText;
        $category = $entities['kategori'] ?? null;

        // Fix: "Jonson" (local boat transport) is often misclassified as "Hunian" due to "Sewa" keyword
        if ($category && (stripos($description, 'jonson') !== false || stripos($description, 'johnson') !== false || stripos($description, 'perahu') !== false || stripos($description, 'speed boat') !== false)) {
            $category = 'transportasi';
        }

        // Build transaction data in existing format
        $transaction = [
            'type' => $type,
            'amount' => $entities['nominal'] ?? null,
            'category' => $category,
            'description' => $description,
            'merchant' => $entities['merchant'] ?? null,
            'account_name' => null, // Will be filled by ProcessIncomingMessage
            'date' => $entities['tanggal'] ?? now()->toDateString(),
            'items' => $entities['items'] ?? [],
        ];

        // Only return transaction if it's a transaction intent
        if (! in_array($intent, ['catat_pengeluaran', 'catat_pemasukan'])) {
            return [
                'success' => true,
                'data' => [
                    'extracted_transactions' => [],
                    'needs_review' => false,
                    'intent' => $intent,
                    'message' => $this->getIntentMessage($intent),
                ],
            ];
        }

        return [
            'success' => true,
            'data' => [
                'extracted_transactions' => [$transaction],
                'needs_review' => $entities['nominal'] === null,
                'confidence' => $data['confidence'] ?? 0.8,
                'finwa_intent' => $intent,
            ],
        ];
    }

    /**
     * Extract transaction from OCR text
     *
     * @param  int  $tenantId  The tenant ID
     * @param  int  $messageId  The message ID
     * @param  string  $ocrText  The OCR text
     * @return array Extracted transaction data
     */
    public function extractTransactionFromOCR(int $tenantId, int $messageId, string $ocrText): array
    {
        $result = $this->processOCR($ocrText);

        if (! $result['success']) {
            return $result;
        }

        $data = $result['data'];
        $entities = $data['entities'] ?? [];

        // Build transaction from OCR
        $transaction = [
            'type' => 'expense', // OCR receipts are typically expenses
            'amount' => $entities['nominal'] ?? null,
            'category' => $entities['kategori'] ?? 'belanja',
            'description' => $entities['catatan'] ?? 'Belanja dari struk',
            'merchant' => $entities['merchant'] ?? null,
            'account_name' => null,
            'date' => $entities['tanggal'] ?? now()->toDateString(),
            'items' => $entities['items'] ?? [],
        ];

        return [
            'success' => true,
            'data' => [
                'extracted_transactions' => [$transaction],
                'needs_review' => $entities['nominal'] === null,
                'confidence' => $data['confidence'] ?? 0.8,
                'source' => 'ocr',
            ],
        ];
    }

    /**
     * Map FinWa-AI intent to existing application intent types
     */
    protected function mapIntent(string $finwaIntent): string
    {
        $mapping = [
            // Core transactions
            'catat_pengeluaran' => 'transaction',
            'catat_pemasukan' => 'transaction',

            // Debt & Receivables (NEW) - treated as special transactions
            'catat_hutang' => 'debt',
            'catat_piutang' => 'receivable',
            'bayar_hutang' => 'pay_debt',
            'terima_piutang' => 'receive_payment',

            // View & Analysis (NEW)
            'cek_cashflow' => 'query',
            'cek_saldo' => 'query',
            'cek_budget' => 'query_budget',
            'cek_statistik' => 'query_stats',
            'cek_target' => 'query_target',

            // Management
            'lihat_transaksi' => 'query',
            'hapus_transaksi' => 'delete',
            'edit_transaksi' => 'edit',

            // Settings (NEW)
            'set_budget' => 'set_budget',
            'set_target' => 'set_target',
            'set_reminder' => 'set_reminder',
            'export_laporan' => 'export',

            // Utilities
            'sapa' => 'greeting',
            'help' => 'help',
            'tanya_finwa' => 'faq',  // Questions about FinWa app
            'unknown' => 'query',
        ];

        return $mapping[$finwaIntent] ?? 'query';
    }

    /**
     * Get appropriate message for non-transaction intents
     */
    protected function getIntentMessage(string $intent): string
    {
        $messages = [
            'sapa' => 'Halo! 👋 Saya adalah asisten keuangan Anda. Kirimkan pesan seperti "catat makan 25rb di KFC" untuk mencatat transaksi.',
            'help' => "📱 *Cara Penggunaan FinWa:*\n\n".
                     "✅ *Catat Pengeluaran:*\n".
                     "   → catat makan 25rb di KFC\n".
                     "   → beli bensin 50rb\n\n".
                     "✅ *Catat Pemasukan:*\n".
                     "   → terima gaji 5jt\n".
                     "   → dapat bonus 500rb\n\n".
                     "✅ *Lihat Ringkasan:*\n".
                     "   → cek cashflow\n".
                     "   → ringkasan bulan ini\n\n".
                     '📸 Anda juga bisa kirim foto struk!',
            'cek_cashflow' => 'Menampilkan ringkasan cashflow...',
            'lihat_transaksi' => 'Menampilkan daftar transaksi...',
            'unknown' => 'Maaf, saya tidak mengerti. Ketik "help" untuk panduan penggunaan.',
        ];

        return $messages[$intent] ?? $messages['unknown'];
    }

    /**
     * Generate narrative report from financial data
     *
     * @param  array  $data  Financial data for comparison
     * @return string Narrative insight
     */
    public function generateReport(array $data): string
    {
        try {
            Log::info('FinWa-AI generating report...');

            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/generate/report", $data);

            if ($response->successful()) {
                $result = $response->json();

                return $result['insight'] ?? '';
            }

            Log::error('FinWa-AI report API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return '';

        } catch (\Exception $e) {
            Log::error('FinWa-AI report generation error', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Generate error response structure
     */
    protected function errorResponse(string $error = 'Unknown error'): array
    {
        return [
            'success' => false,
            'error' => $error,
            'data' => [
                'intent' => 'unknown',
                'entities' => [
                    'kategori' => null,
                    'nominal' => null,
                    'merchant' => null,
                    'tanggal' => null,
                    'catatan' => null,
                    'items' => [],
                ],
                'confidence' => 0.0,
            ],
        ];
    }
}
