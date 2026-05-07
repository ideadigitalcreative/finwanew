<?php

namespace App\Services\OCR;

use App\Models\Message;
use App\Models\OcrJob;
use App\Models\Transaction;
use App\Services\Category\CategoryMappingService;
use App\Services\FinWaAIService;
use App\Services\GroqLLMService;
use App\Services\Transaction\TransactionConfirmationService;
use App\Services\Transaction\TransactionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * OcrProcessorService - Handles OCR related logic
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 */
class OcrProcessorService
{
    public function __construct(
        protected Message $message,
        protected TransactionService $transactionService,
        protected TransactionConfirmationService $confirmationService,
        protected ReceiptParserService $receiptParser,
        protected $sendReplyCallback,
        protected $mapCategoryCallback,
        protected ?GroqLLMService $groqService = null,
        protected ?ImagePreProcessorService $preProcessor = null
    ) {
        if (! $this->groqService) {
            $this->groqService = new GroqLLMService;
        }
        if (! $this->preProcessor) {
            $this->preProcessor = new ImagePreProcessorService;
        }
    }

    /**
     * Create OCR job for image processing
     *
     * MOVED FROM: ProcessIncomingMessage::createOcrJob()
     * LINES: 3755-3827
     */
    public function createOcrJob(?int $userId = null): ?OcrJob
    {
        try {
            // Get image URL
            $imageUrl = null;
            $fileId = null;

            if ($this->message->type === 'image') {
                $fileId = $this->message->media_id ?? null; // Usually file_id or url

                // 1. If content is a URL
                if (! empty($this->message->content) && (filter_var($this->message->content, FILTER_VALIDATE_URL) || str_contains($this->message->content, 'http') || str_contains($this->message->content, '/storage/'))) {
                    $imageUrl = $this->sanitizeMediaReference($this->message->content);
                }
                // 2. Check metadata (common storage)
                elseif (isset($this->message->metadata['media_url'])) {
                    $imageUrl = $this->sanitizeMediaReference($this->message->metadata['media_url']);
                }
                // 3. Check raw_data (WhatsApp standard)
                elseif (isset($this->message->raw_data['image']['url'])) {
                    $imageUrl = $this->sanitizeMediaReference($this->message->raw_data['image']['url']);
                }
                // 4. Fallback: treat content as path even if check failed
                else {
                    $imageUrl = $this->sanitizeMediaReference($this->message->content);
                }
            } else {
                // Check for image inside text (URL)
                $imageUrl = $this->extractFirstUrlFromText((string) $this->message->content);
            }

            if (! $imageUrl) {
                Log::warning('No image found for OCR job', ['message_id' => $this->message->id]);

                return null;
            }

            $normalizedPath = $this->normalizeToStorageRelativePath($imageUrl);

            // Create OCR Job
            $ocrJob = OcrJob::create([
                'tenant_id' => $this->message->tenant_id,
                'user_id' => $userId,
                'message_id' => $this->message->id,
                'file_path' => $normalizedPath ?? $imageUrl,
                'file_type' => 'image',
                'status' => 'pending',
                'created_at' => now(),
            ]);

            Log::info('OCR Job created', ['job_id' => $ocrJob->id]);

            return $ocrJob;

        } catch (\Exception $e) {
            Log::error('Failed to create OCR job', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Dispatch image processing — langsung kirim ke Gemini Vision (skip OCR).
     */
    public function dispatchToOcrWorker(OcrJob $ocrJob, int $retryCount = 0, int $maxRetries = 3): void
    {
        try {
            ($this->sendReplyCallback)('⏳ Sedang memproses gambar struk... Mohon tunggu sebentar.');

            $this->processImageWithGemini($ocrJob, $ocrJob->file_path);

        } catch (\Exception $e) {
            Log::error('Gemini receipt processing failed', [
                'ocr_job_id' => $ocrJob->id,
                'error' => $e->getMessage(),
            ]);
            $this->handleOcrFailure($ocrJob, 'Gemini Processing Failed: '.$e->getMessage());
        }
    }

    /**
     * Kirim gambar langsung ke Gemini 2.5 Flash untuk ekstraksi data struk.
     * Tidak melalui OCR — Gemini membaca gambar secara native.
     */
    public function processImageWithGemini(OcrJob $ocrJob, string $filePath): void
    {
        try {
            Log::info('Gemini Vision: processing receipt image directly', ['ocr_job_id' => $ocrJob->id]);

            $fileContent = $this->loadImageContent($filePath);
            $base64 = base64_encode($fileContent);

            $mimeType = 'image/jpeg';
            if (function_exists('finfo_buffer')) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detected = $finfo->buffer($fileContent);
                if ($detected && str_starts_with($detected, 'image/')) {
                    $mimeType = $detected;
                }
            }

            $dataUri = "data:{$mimeType};base64,{$base64}";

            Log::info('Sending image to Gemini Vision', [
                'ocr_job_id' => $ocrJob->id,
                'size_bytes' => strlen($fileContent),
                'mime_type' => $mimeType,
            ]);

            $gemini = app(\App\Services\GeminiAIService::class);

            if (! $gemini->isAvailable()) {
                throw new \Exception('Gemini API key belum dikonfigurasi');
            }

            $parsed = $gemini->extractReceiptData($dataUri);

            // Gambar bukan struk atau tidak terbaca — tetap potong kuota, beri edukasi
            if (! $parsed || empty($parsed['total_amount'])) {
                Log::warning('Gemini Vision: bukan struk atau tidak terbaca', [
                    'ocr_job_id' => $ocrJob->id,
                    'parsed' => $parsed,
                ]);

                $ocrJob->update([
                    'status' => 'completed',
                    'extracted_text' => null,
                    'metadata' => array_merge($ocrJob->metadata ?? [], [
                        'ai_source' => 'gemini-vision',
                        'confidence_score' => 0,
                        'not_a_receipt' => true,
                        'raw_response' => $parsed,
                    ]),
                    'completed_at' => now(),
                ]);

                ($this->sendReplyCallback)(
                    "⚠️ *Gambar Tidak Dikenali sebagai Struk*\n\n".
                    "AI tidak menemukan data transaksi pada gambar ini.\n\n".
                    "💡 _Kuota scan Anda tetap terpotong meskipun gambar bukan struk atau buram._\n\n".
                    "Pastikan gambar yang dikirim:\n".
                    "✅ Foto struk belanja yang jelas\n".
                    "✅ Tidak buram atau terpotong\n".
                    '❌ Bukan foto selfie, screenshot chat, atau gambar lainnya'
                );

                return;
            }

            Log::info('Gemini Vision extraction success', [
                'ocr_job_id' => $ocrJob->id,
                'merchant' => $parsed['merchant_name'] ?? 'N/A',
                'total' => $parsed['total_amount'],
                'items_count' => count($parsed['items'] ?? []),
            ]);

            $merchant = $parsed['merchant_name'] ?? null;
            $total = (int) $parsed['total_amount'];
            $dateRaw = $parsed['date'] ?? null;
            $items = $parsed['items'] ?? [];
            $tax = (int) ($parsed['tax'] ?? 0);

            // ── DATE VALIDATION ──────────────────────────────────────────────
            // Gemini kadang salah parse format DD-MM-YYYY jadi tahun yang aneh
            // (misal: "01-05-2026" dibaca sebagai "2001-05-26").
            // Validasi: tanggal tidak boleh >1 tahun lalu atau di masa depan >1 hari.
            if ($dateRaw) {
                try {
                    $parsedDate = \Carbon\Carbon::parse($dateRaw);
                    $oneYearAgo = now()->subYear();
                    $tomorrow = now()->addDay();

                    if ($parsedDate->lt($oneYearAgo) || $parsedDate->gt($tomorrow)) {
                        Log::warning('Gemini Vision: date looks invalid, falling back to today', [
                            'ocr_job_id' => $ocrJob->id,
                            'gemini_date' => $dateRaw,
                            'parsed_as' => $parsedDate->toDateString(),
                            'reason' => $parsedDate->lt($oneYearAgo) ? 'too_far_in_past' : 'in_future',
                        ]);
                        $dateRaw = now()->toDateString();
                    }
                } catch (\Exception $e) {
                    Log::warning('Gemini Vision: date parse error, falling back to today', [
                        'ocr_job_id' => $ocrJob->id,
                        'gemini_date' => $dateRaw,
                        'error' => $e->getMessage(),
                    ]);
                    $dateRaw = now()->toDateString();
                }
            }

            $structuredData = [
                'entities' => [
                    'merchant' => $merchant,
                    'nominal' => $total,
                    'items' => $items,
                    'tanggal' => $dateRaw,
                    'tax' => $tax,
                ],
                'fields' => [
                    'total' => $total,
                    'merchant' => $merchant,
                    'date_raw' => $dateRaw,
                ],
                'items' => $items,
            ];

            $transactionDate = $this->receiptParser->parseReceiptDate($dateRaw);
            $txData = [
                'type' => 'expense',
                'amount' => $total,
                'category_type' => 'pengeluaran_belanja',
                'transaction_date' => $transactionDate,
                'description' => $merchant ? "Belanja di {$merchant}" : 'Belanja dari struk',
                'source' => 'gemini_vision',
                'confidence_score' => 0.95,
                'account_name' => null,
            ];

            $transaction = $this->transactionService->createTransaction($txData, false);

            if (! $transaction) {
                $ocrJob->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error' => 'Failed to create transaction from receipt image',
                    'metadata' => array_merge($ocrJob->metadata ?? [], [
                        'ai_source' => 'gemini-vision',
                        'confidence_score' => 0.95,
                        'model' => config('services.gemini.model', 'gemini-2.5-flash'),
                        'entities' => $structuredData['entities'],
                        'structured_data' => $structuredData,
                        'raw_response' => $parsed,
                    ]),
                ]);

                ($this->sendReplyCallback)('⚠️ Gagal mencatat transaksi dari struk. Silakan coba lagi.');

                return;
            }

            $ocrJob->update([
                'status' => 'completed',
                'completed_at' => now(),
                'metadata' => array_merge($ocrJob->metadata ?? [], [
                    'ai_source' => 'gemini-vision',
                    'confidence_score' => 0.95,
                    'model' => config('services.gemini.model', 'gemini-2.5-flash'),
                    'entities' => $structuredData['entities'],
                    'structured_data' => $structuredData,
                    'raw_response' => $parsed,
                    'transaction_id' => $transaction->id,
                ]),
            ]);

            if (! empty($items)) {
                $this->confirmationService->sendReceiptConfirmation($transaction, $items, $merchant);
            } else {
                $reply = "✅ *Berhasil Dicatat*\n\n";
                $reply .= '💸 *Pengeluaran*: Rp '.number_format($total, 0, ',', '.')."\n";
                $reply .= "📁 Kategori: Belanja\n";
                if ($merchant) {
                    $reply .= "🏪 Toko: {$merchant}\n";
                }
                $reply .= "📅 Tanggal: {$transactionDate}\n";
                $reply .= "\n*Struk berhasil dicatat sebagai 1 transaksi pengeluaran.*";
                ($this->sendReplyCallback)($reply);
            }

        } catch (\Throwable $e) {
            Log::error('Gemini Vision process failed', [
                'ocr_job_id' => $ocrJob->id,
                'error' => $e->getMessage(),
            ]);
            $this->handleOcrFailure($ocrJob, $e->getMessage());
        }
    }

    /**
     * Load image content from storage path or URL.
     * Reused logic from the old processImageWithFinWaAI.
     */
    protected function loadImageContent(string $filePath): string
    {
        $filePath = $this->sanitizeMediaReference($filePath);
        $absolutePath = $filePath;
        $downloadUrl = null;

        if (str_starts_with($filePath, 'http')) {
            $urlParts = parse_url($filePath);
            if ($urlParts !== false) {
                parse_str($urlParts['query'] ?? '', $query);
                if (isset($query['path']) && is_string($query['path']) && $query['path'] !== '') {
                    $absolutePath = storage_path('app/public/'.ltrim($query['path'], '/'));
                } elseif (! empty($urlParts['path']) && is_string($urlParts['path']) && str_contains($urlParts['path'], '/storage/')) {
                    $relative = ltrim(substr($urlParts['path'], strpos($urlParts['path'], '/storage/') + strlen('/storage/')), '/');
                    $absolutePath = storage_path('app/public/'.$relative);
                } else {
                    $downloadUrl = $filePath;
                }
            } else {
                $downloadUrl = $filePath;
            }
        } elseif (! str_starts_with($filePath, '/')) {
            $absolutePath = storage_path('app/public/'.$filePath);
        }

        if ($downloadUrl) {
            Log::info('Downloading image from URL for Gemini', ['url' => $downloadUrl]);
            $http = Http::timeout(30);
            $expectedKey = config('services.ocr_worker.api_key');
            if (! empty($expectedKey) && str_contains($downloadUrl, '/api/files')) {
                $http = $http->withHeaders(['X-API-Key' => $expectedKey]);
            }
            $response = $http->get($downloadUrl);
            if (! $response->successful()) {
                throw new \Exception("Failed to fetch image (HTTP {$response->status()})");
            }

            return $response->body();
        }

        if (! file_exists($absolutePath)) {
            Log::error('Image file not found', ['path' => $absolutePath, 'original' => $filePath]);
            throw new \Exception('File struk tidak ditemukan di server: '.basename($absolutePath));
        }

        return file_get_contents($absolutePath);
    }

    /**
     * Process image using FinWa-AI OCR (Port 8000)
     */
    public function processImageWithFinWaAI(OcrJob $ocrJob, string $filePath): void
    {
        try {
            Log::info('Step 1-3: Using ai-finwa (Port 8000) for fast OCR', ['ocr_job_id' => $ocrJob->id]);

            // Get the correct local path for the image
            $filePath = $this->sanitizeMediaReference($filePath);
            $absolutePath = $filePath;
            $downloadUrl = null;

            // Handle URL: extract path parameter if exists
            if (str_starts_with($filePath, 'http')) {
                $urlParts = parse_url($filePath);
                if ($urlParts !== false) {
                    parse_str($urlParts['query'] ?? '', $query);
                    if (isset($query['path']) && is_string($query['path']) && $query['path'] !== '') {
                        $absolutePath = storage_path('app/public/'.ltrim($query['path'], '/'));
                    } elseif (! empty($urlParts['path']) && is_string($urlParts['path']) && str_contains($urlParts['path'], '/storage/')) {
                        $relative = ltrim(substr($urlParts['path'], strpos($urlParts['path'], '/storage/') + strlen('/storage/')), '/');
                        $absolutePath = storage_path('app/public/'.$relative);
                    } else {
                        $downloadUrl = $filePath;
                    }
                } else {
                    $downloadUrl = $filePath;
                }
            } elseif (! str_starts_with($filePath, '/')) {
                // If it's a relative path from DB (e.g. whatsapp/...)
                $absolutePath = storage_path('app/public/'.$filePath);
            }

            // Only read from disk if we don't have fileContent yet (from fallback download)
            if (! isset($fileContent) && $downloadUrl) {
                Log::info('Downloading image from URL', ['url' => $downloadUrl, 'ocr_job_id' => $ocrJob->id]);
                $http = Http::timeout(30);
                $expectedKey = config('services.ocr_worker.api_key');
                if (! empty($expectedKey) && str_contains($downloadUrl, '/api/files')) {
                    $http = $http->withHeaders(['X-API-Key' => $expectedKey]);
                }
                $response = $http->get($downloadUrl);
                if (! $response->successful()) {
                    throw new \Exception("Failed to fetch image (HTTP {$response->status()})");
                }
                $fileContent = $response->body();
                $mimeType = $response->header('Content-Type') ?: null;
            }

            if (! isset($fileContent)) {
                if (! file_exists($absolutePath)) {
                    Log::error('OCR source file not found', ['path' => $absolutePath, 'original' => $filePath]);
                    throw new \Exception('File struk tidak ditemukan di server: '.basename($absolutePath));
                }
                $fileContent = file_get_contents($absolutePath);
            }
            $base64 = base64_encode($fileContent);
            $size = strlen($fileContent);
            Log::info('Prepared image for ai-finwa', ['size_bytes' => $size, 'path' => basename($absolutePath)]);

            $mimeType = $mimeType ?? (file_exists($absolutePath) ? (mime_content_type($absolutePath) ?: 'image/jpeg') : 'image/jpeg');
            $dataUri = "data:{$mimeType};base64,{$base64}";

            $finwaAI = app(\App\Services\FinWaAIService::class);
            $result = $finwaAI->processImage($dataUri);

            Log::info('FinWa-AI API Call Result', [
                'success' => $result['success'] ?? false,
                'ocr_job_id' => $ocrJob->id,
                'response_data' => $result['data'] ?? 'No data',
            ]);

            if ($result['success']) {
                $data = $result['data'];
                $entities = $data['entities'] ?? [];
                $nominal = $entities['nominal'] ?? null;
                $rawText = $data['raw_text'] ?? ($data['text'] ?? '');

                $merchant = $entities['merchant'] ?? null;

                // REFINE MERCHANT: If AI returns an address as merchant, try to find a better one
                if ($merchant && (preg_match('/^(Jl\.?|Jalan|Km\.?\s?\d+)/i', $merchant) || strlen($merchant) > 50)) {
                    Log::info('Merchant looks like an address, attempting to find a better name', ['original' => $merchant]);
                    $betterMerchant = $this->receiptParser->extractStoreNameFromOcrText($rawText);
                    if ($betterMerchant) {
                        Log::info('Found better merchant name', ['new' => $betterMerchant]);
                        $merchant = $betterMerchant;
                        $entities['merchant'] = $merchant;
                    }
                }

                // FALLBACK: If ai-finwa failed to find nominal but we have raw text, try local parser
                if ($nominal === null && ! empty($rawText)) {
                    Log::info('ai-finwa nominal is null, attempting local extraction from raw_text', [
                        'text_preview' => mb_substr($rawText, 0, 100),
                    ]);
                    $nominal = $this->receiptParser->extractTotalFromOcrText($rawText);
                    if ($nominal) {
                        Log::info('Local extraction SUCCESS (Fallback)', ['nominal' => $nominal]);
                        $entities['nominal'] = $nominal; // Update for storing in metadata
                    }
                }

                // FALLBACK 2: If items are empty, try a simple manual extraction from raw text
                if (empty($entities['items']) && ! empty($rawText)) {
                    Log::info('ai-finwa items are empty, attempting manual item extraction from raw_text');
                    $lines = explode("\n", $rawText);
                    $manualItems = [];
                    foreach ($lines as $line) {
                        $line = trim($line);
                        // Pattern: Name ... Price (e.g., "SGF JUN HC 8.400")
                        if (preg_match('/^(.+?)\s+([\d\.,=]{4,10})$/u', $line, $itemMatch)) {
                            $itemName = trim($itemMatch[1]);
                            $itemPrice = $this->receiptParser->extractTotalFromOcrText($itemMatch[2], true);

                            if ($itemPrice && $itemPrice < ($nominal ?? 9999999) && ! $this->receiptParser->isPaymentMethodOrNonProductLine($itemName)) {
                                $manualItems[] = [
                                    'name' => $itemName,
                                    'qty' => 1,
                                    'price' => $itemPrice,
                                    'total' => $itemPrice,
                                ];
                            }
                        }
                    }
                    if (! empty($manualItems)) {
                        Log::info('Manual item extraction SUCCESS', ['count' => count($manualItems)]);
                        $entities['items'] = $manualItems;
                    }
                }

                // FINAL FALLBACK: If items still empty OR nominal is null, use Groq (The advanced LLM)
                if ((empty($entities['items']) || $nominal === null) && ! empty($rawText) && $this->groqService && $this->groqService->isAvailable()) {
                    Log::info('Proceeding to GROQ fallback for deep receipt parsing...');
                    try {
                        $groqResult = $this->groqService->parseReceiptFromOCR($rawText, [
                            'merchant' => $merchant,
                            'nominal' => $nominal,
                        ]);

                        if ($groqResult && ! empty($groqResult['is_receipt'])) {
                            Log::info('GROQ fallback SUCCESS', [
                                'merchant' => $groqResult['merchant'] ?? 'N/A',
                                'nominal' => $groqResult['nominal'] ?? 'N/A',
                                'items_count' => count($groqResult['items'] ?? []),
                            ]);

                            // Merge/Update with Groq results
                            if ($nominal === null && isset($groqResult['nominal'])) {
                                $nominal = $groqResult['nominal'];
                                $entities['nominal'] = $nominal;
                            }

                            if (empty($entities['items']) && ! empty($groqResult['items'])) {
                                $entities['items'] = $groqResult['items'];
                            }

                            // Update merchant from Groq (even if we had one before, Groq might be better)
                            if (isset($groqResult['merchant'])) {
                                $merchant = $groqResult['merchant'];
                                $entities['merchant'] = $merchant;
                            }

                            if (isset($groqResult['category'])) {
                                $entities['kategori'] = $groqResult['kategori'] ?? $groqResult['category'];
                            }

                            // REFINE MERCHANT AGAIN: Check if Groq's merchant is an address
                            if ($merchant && (preg_match('/^([JI1LS!]{1,2}[ln1]?\.?|Jalan|Jl|Km\.?\s?\d+)/i', $merchant) || strlen($merchant) > 50)) {
                                Log::info('Groq merchant looks like an address, trying local refinement', ['original' => $merchant]);
                                $betterMerchant = $this->receiptParser->extractStoreNameFromOcrText($rawText);
                                if ($betterMerchant) {
                                    $merchant = $betterMerchant;
                                    $entities['merchant'] = $merchant;
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning('Groq fallback failed', ['error' => $e->getMessage()]);
                    }
                }

                Log::info('FinWa-AI Extracted Entities', [
                    'merchant' => $entities['merchant'] ?? 'N/A',
                    'nominal' => $nominal,
                    'items_count' => count($entities['items'] ?? []),
                ]);

                // Try to get raw text, if missing but we have nominal, synthesize it
                $extractedText = $data['ocr_text'] ?? $rawText;

                if (empty($extractedText) && $nominal) {
                    $merchant = $entities['merchant'] ?? 'Struk Belanja';
                    $extractedText = "{$merchant}";

                    // Add items if available to show the shopping list
                    if (! empty($entities['items'])) {
                        $itemList = [];
                        foreach ($entities['items'] as $item) {
                            $name = $item['name'] ?? ($item['description'] ?? 'Item');
                            $price = isset($item['price']) ? ' Rp '.number_format($item['price'], 0, ',', '.') : '';
                            $itemList[] = "- {$name}{$price}";
                        }
                        if (! empty($itemList)) {
                            $extractedText .= "\n".implode("\n", $itemList);
                        }
                    } else {
                        // If no items, include nominal in description
                        $extractedText .= ' Rp '.number_format($nominal, 0, ',', '.');
                    }

                    Log::info('Synthesized clean message content with items', ['content' => $extractedText]);
                }

                // If STILL empty, use a generic fallback
                if (empty($extractedText)) {
                    $extractedText = 'Transaksi Struk';
                }

                // Update the OCR job immediately
                $ocrJob->update([
                    'status' => 'completed',
                    'extracted_text' => $extractedText,
                    'confidence_score' => $data['confidence'] ?? 0.95,
                    'metadata' => array_merge($ocrJob->metadata ?? [], [
                        'ai_source' => isset($groqResult) ? 'groq-hybrid' : 'ai-finwa',
                        'entities' => $entities,
                        'structured_data' => [
                            'entities' => $entities,
                            'fields' => [
                                'total' => $nominal,
                                'merchant' => $merchant,
                                'date' => $entities['tanggal'] ?? null,
                            ],
                            'items' => $entities['items'] ?? [],
                        ],
                        'raw_response' => $data, // For debugging
                    ]),
                    'completed_at' => now(),
                ]);

                // Update message
                $message = $ocrJob->message;
                $message->update([
                    'type' => 'text',
                    'content' => $extractedText,
                ]);

                Log::info('ai-finwa success - dispatching message process', [
                    'message_id' => $message->id,
                    'content_preview' => substr($extractedText, 0, 50),
                ]);

                \App\Jobs\ProcessIncomingMessage::dispatch($message->fresh());
            } else {
                throw new \Exception($result['error'] ?? 'Gagal memproses gambar dengan ai-finwa');
            }

        } catch (\Throwable $e) {
            Log::error('ai-finwa process failed', [
                'ocr_job_id' => $ocrJob->id,
                'error' => $e->getMessage(),
            ]);
            $this->handleOcrFailure($ocrJob, $e->getMessage());
        }
    }

    /**
     * Get basic heuristic data (Baseline) to help AI
     */
    protected function getHeuristicBaseline(string $rawText): array
    {
        return [
            'merchant' => $this->receiptParser->extractStoreNameFromOcrText($rawText),
            'nominal' => $this->receiptParser->extractTotalFromOcrText($rawText),
        ];
    }

    protected function sanitizeMediaReference(?string $value): string
    {
        $value = (string) ($value ?? '');
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        $value = preg_replace('/^`+|`+$/', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B\"'<>");

        if (preg_match('/(https?:\/\/[^\s`<>]+|\/storage\/[^\s`<>]+)/', $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }

    protected function extractFirstUrlFromText(string $text): ?string
    {
        $text = $this->sanitizeMediaReference($text);
        if (filter_var($text, FILTER_VALIDATE_URL)) {
            return $text;
        }

        if (preg_match('/https?:\/\/[^\s`<>]+/', $text, $matches)) {
            return $matches[0];
        }

        return null;
    }

    protected function normalizeToStorageRelativePath(string $value): ?string
    {
        $value = $this->sanitizeMediaReference($value);

        if (str_starts_with($value, '/storage/')) {
            return ltrim(substr($value, strlen('/storage/')), '/');
        }

        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $urlParts = parse_url($value);
        if ($urlParts === false) {
            return null;
        }

        parse_str($urlParts['query'] ?? '', $query);
        if (isset($query['path']) && is_string($query['path']) && $query['path'] !== '') {
            return ltrim($query['path'], '/');
        }

        if (! empty($urlParts['path']) && is_string($urlParts['path']) && str_contains($urlParts['path'], '/storage/')) {
            return ltrim(substr($urlParts['path'], strpos($urlParts['path'], '/storage/') + strlen('/storage/')), '/');
        }

        return null;
    }

    /**
     * ORIGINAL HEURISTIC LOGIC (v1) - Backup
     *
     * MOVED FROM: ProcessIncomingMessage::handleFinWaAIOcrResult()
     */
    protected function processOcrWithHeuristics(OcrJob $ocrJob, array $data): void
    {
        try {
            $entities = $data['entities'] ?? [];
            $nominal = $entities['nominal'] ?? null;
            $kategori = $entities['kategori'] ?? 'belanja';
            $merchant = $entities['merchant'] ?? null;
            $items = $entities['items'] ?? [];
            $rawText = $data['raw_text'] ?? '';

            // ================================================================================
            // DETECTION: Transfer Proof vs Receipt
            // Bukti transfer dari mobile banking/e-wallet should NOT be processed as transactions.
            // Common indicators: "berhasil", "transfer ke", "bukti transfer", bank app names, etc.
            // ================================================================================
            if (! empty($rawText) && $this->isTransferProof($rawText)) {
                Log::info('Detected transfer proof image (not receipt)', [
                    'ocr_job_id' => $ocrJob->id,
                    'raw_text_preview' => mb_substr($rawText, 0, 200),
                ]);

                $ocrJob->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'result' => json_encode([
                        'extracted_text' => $rawText,
                        'structured_data' => $data,
                        'detection' => 'transfer_proof_rejected',
                    ]),
                ]);

                ($this->sendReplyCallback)(
                    "ℹ️ *Bukti Transfer Terdeteksi*\n\n".
                    "Gambar yang Anda kirim terdeteksi sebagai *bukti transfer*, bukan struk belanja.\n\n".
                    "📸 *Gambar yang bisa diproses:*\n".
                    "✅ Struk belanja (Indomaret, Alfamart, dll)\n".
                    "✅ Nota pembelian\n".
                    "✅ Invoice/faktur\n\n".
                    "❌ *Gambar yang tidak diproses:*\n".
                    "• Bukti transfer bank (BCA, BRI, Mandiri, dll)\n".
                    "• Screenshot pembayaran QRIS\n".
                    "• Bukti top-up e-wallet\n\n".
                    "💡 *Untuk mencatat transfer/pembayaran, ketik manual:*\n".
                    "_transfer 500rb ke rekening adi_\n".
                    '_bayar listrik 350rb_'
                );

                return;
            }

            // --- PRE-CORRECTION: ALWAYS check explicit "TOTAL BELANJA:" pattern in raw text ---
            // This is MORE reliable than AI's entity extraction because AI often:
            // 1. Combines QTY column with price (e.g., "1 24000" becomes "124000")
            // 2. Sums up wrong item prices instead of reading the actual total line
            if (! empty($rawText)) {
                // First try strict mode to get TOTAL BELANJA / NON TUNAI
                $strictTotal = $this->receiptParser->extractTotalFromOcrText($rawText, true);

                if ($strictTotal && $strictTotal > 100 && $strictTotal < 100000000) {
                    // ALWAYS override AI nominal with explicit total if found
                    if ($nominal != $strictTotal) {
                        Log::info("Overriding AI nominal ({$nominal}) with strict Regex Total ({$strictTotal})", [
                            'ai_nominal' => $nominal,
                            'regex_total' => $strictTotal,
                            'difference' => abs($nominal - $strictTotal),
                        ]);
                        $nominal = $strictTotal;
                    }
                }

                // ADDITIONAL CHECK: Detect if AI's item prices are inflated
                // This happens when OCR reads "1 24000" (QTY + Price) as "124000"
                // Telltale sign: Multiple items have prices starting with "1" and > 100,000
                // while the actual receipt items are typically < 50,000 each
                if (! empty($items)) {
                    $inflatedCount = 0;
                    $itemsSum = 0;

                    foreach ($items as $item) {
                        $price = $item['harga'] ?? $item['price'] ?? 0;
                        $itemsSum += $price;

                        // Check if price looks inflated (starts with 1 and > 100,000)
                        // Normal Indomaret items are usually < 100,000
                        if ($price >= 100000 && substr((string) $price, 0, 1) === '1') {
                            $inflatedCount++;
                        }
                    }

                    // If multiple items look inflated, try to re-extract total
                    if ($inflatedCount >= 2) {
                        Log::warning('Detected potentially inflated item prices (OCR QTY+Price merge)', [
                            'inflated_count' => $inflatedCount,
                            'items_sum' => $itemsSum,
                            'ai_nominal' => $nominal,
                        ]);

                        // Re-try extraction in non-strict mode
                        $looseTotal = $this->receiptParser->extractTotalFromOcrText($rawText, false);

                        if ($looseTotal && $looseTotal > 100 && $looseTotal < $itemsSum) {
                            Log::info('Using loose extraction total which is lower than inflated sum', [
                                'loose_total' => $looseTotal,
                                'inflated_sum' => $itemsSum,
                            ]);
                            $nominal = $looseTotal;
                        }
                    }

                    // If AI nominal equals sum of items AND sum seems too high,
                    // check for BRS/QTY pattern
                    if ($nominal == $itemsSum && $itemsSum > 500000) {
                        Log::info('AI nominal matches high sum of items, checking for BRS/QTY pattern', [
                            'nominal' => $nominal,
                            'items_sum' => $itemsSum,
                        ]);

                        $lines = explode("\n", $rawText);
                        $foundBrsQty = false;
                        $brsTotal = null;

                        foreach ($lines as $i => $line) {
                            if (preg_match('/^(BRS|QTY)=/i', trim($line))) {
                                $foundBrsQty = true;
                                for ($j = $i + 1; $j < min($i + 4, count($lines)); $j++) {
                                    $nextLine = trim($lines[$j]);
                                    if (preg_match('/^(\d{1,3}(?:\.\d{3})*(?:,\d{2})?)$/', $nextLine, $matches)) {
                                        $brsTotal = (int) str_replace(['.', ','], ['', '.'], $matches[1]);
                                        Log::info('Found BRS/QTY total', ['line' => $nextLine, 'parsed' => $brsTotal]);
                                        break 2;
                                    }
                                }
                            }
                        }

                        if ($brsTotal && $brsTotal > 100 && $brsTotal < $nominal) {
                            Log::info('Using BRS/QTY total which is less than AI nominal', [
                                'brs_total' => $brsTotal,
                                'ai_nominal' => $nominal,
                            ]);
                            $nominal = $brsTotal;
                        }
                    }
                }
            }

            // --- SANITY CHECK: Detect unreasonable totals (e.g. > 10 million) ---
            // often caused by picking up Invoice Number / Phone Number as Total
            if ($nominal > 10000000) {
                Log::warning('FinWa-AI detected suspicious total (>10jt), attempting correction', [
                    'original_nominal' => $nominal,
                ]);

                $corrected = false;

                // Correction 1: Trust Sum of Items if available and reasonable
                $itemsSum = 0;
                foreach ($items as $item) {
                    $price = $item['harga'] ?? $item['price'] ?? 0;
                    $itemsSum += $price;
                }

                if ($itemsSum > 0 && $itemsSum < 10000000) {
                    Log::info("Correcting total using Items Sum: {$itemsSum}");
                    $nominal = $itemsSum;
                    $corrected = true;
                }

                // Correction 2: Re-scan raw text if items didn't help
                if (! $corrected && ! empty($rawText)) {
                    // Try strictly first
                    // REPLACEMENT: Use ReceiptParserService
                    $extracted = $this->receiptParser->extractTotalFromOcrText($rawText, true);
                    if ($extracted && $extracted > 0 && $extracted < 10000000) {
                        Log::info("Correcting total using Strict Total extraction: {$extracted}");
                        $nominal = $extracted;
                        $corrected = true;
                    }

                    // If strict failed, try "Sum of Prices" heuristic
                    // Find all money-like lines and see if they sum up to something reasonable
                    if (! $corrected) {
                        // Regex to find numbers. We want to avoid "1x 4.500" (unit price) if possible.
                        // But raw text might be "282... x 4.500".
                        // Strategy: Find numbers that are NOT immediately preceded by 'x' or 'X'

                        // preg_match_all('/(?<![xX\s][\s])(?:Rp\s*)?(\d{1,3}(?:\.\d{3})+)/', $rawText, $priceMatches);

                        // If that regex is too complex/brittle, let's look at lines.
                        // Better strategy: Split into lines. If a line is JUST a number (or "Rp Number"), it's likely a line total.
                        // If a line is "Code x Price", it's details.

                        $lines = explode("\n", $rawText);
                        $standalonePrices = [];

                        foreach ($lines as $line) {
                            $cleanLine = trim($line);
                            // Check if line is basically just a price "4.500" or "Rp 49.000"
                            if (preg_match('/^(?:Rp\s*)?(\d{1,3}(?:\.\d{3})+)\s*$/', $cleanLine, $m)) {
                                $val = (int) str_replace('.', '', $m[1]);
                                if ($val > 500 && $val < 10000000) {
                                    $standalonePrices[] = $val;
                                }
                            }
                        }

                        if (! empty($standalonePrices)) {
                            $candidateSum = array_sum($standalonePrices);
                            Log::info('Summing standalone price lines: '.implode(' + ', $standalonePrices)." = {$candidateSum}");

                            // Sanity check the sum
                            if ($candidateSum > 1000 && $candidateSum < 10000000) {
                                $nominal = $candidateSum;
                                $corrected = true;
                            }
                        }
                    }
                }

                // --- FALLBACK ITEM EXTRACTION ---
                // If AI returned NO items (but we have raw text), try to build the item list ourselves.
                // This ensures the user sees the "Daftar Belanja" section even if AI parsing failed.
                if (empty($items) && ! empty($rawText)) {
                    Log::info('AI returned no items, attempting fallback item extraction from raw text.');

                    // Reuse the 'Standalone Price' logic to find items
                    // Look for lines that look like: "1. NAME OF PRODUCT" followed by a price line

                    $lines = explode("\n", $rawText);
                    $fallbackItems = [];
                    $bufferName = '';

                    foreach ($lines as $i => $line) {
                        $cleanLine = trim($line);
                        if (empty($cleanLine)) {
                            continue;
                        }

                        // SKIP lines that are typically details, not names
                        // e.g. "1 PCS x 37.500", "Diskon 10%", "Pot.", "QTY"
                        if (preg_match('/^(?:qty|pcs|item|pot\.|diskon|disc|subtotal|tunai|kembali|change|cash)/i', $cleanLine)) {
                            continue;
                        }
                        // SKIP metode pembayaran / label (QRIS Yokee, OVO, AMOUNT, dll)
                        if ($this->receiptParser->isPaymentMethodOrNonProductLine($cleanLine)) {
                            continue;
                        }
                        if (preg_match('/\d+\s*(?:pcs|x|@)/i', $cleanLine)) {
                            continue; // Skip quantity lines e.g. "1 x 5000"
                        }

                        // --- ADVANCED PRICE DETECTION (Price at end of line or quantity lines) ---
                        $isPriceLines = false;
                        $price = 0;

                        // 1. Check if line is ONLY a price (e.g. "4.500", "Rp 4.500")
                        if (preg_match('/^(?:Rp\s*)?(\d{1,3}(?:\.\d{3})*(?:\,\d{2})?)\s*$/', $cleanLine, $m)) {
                            $cleanNum = str_replace(['.', ','], ['', '.'], $m[1]);
                            $price = (int) $cleanNum;
                            $isPriceLines = true;
                        }
                        // 2. Check if line ENDS with a price (e.g. "JAGUNG PIPIL 19000" or "PCS X 19,000 19000")
                        elseif (preg_match('/(?:\s+|Rp\s*)([\d]{1,3}(?:\.[\d]{3})+(?:,[\d]{2})?)$|(?:\s+)([\d]{3,8})$/', $cleanLine, $m)) {
                            $rawMatchedPrice = $m[1] ?: $m[2];
                            $cleanNum = str_replace(['.', ','], ['', '.'], $rawMatchedPrice);
                            $price = (int) $cleanNum;
                            // Verify it's a reasonable price (e.g., > 500 and not a year)
                            if ($price > 500 && ! preg_match('/^20[0-9]{2}$/', (string) $price)) {
                                $isPriceLines = true;
                            }
                        }

                        if ($isPriceLines) {
                            // If we have a buffered name from previous lines, associate it
                            if (! empty($bufferName) && $price > 500 && $price < 10000000) {
                                // Clean up the name
                                $cleanName = preg_replace('/^\d+[\.\s]+/', '', $bufferName);
                                $cleanName = preg_replace('/^\d+\.\d+x.*/i', '', $cleanName); // e.g. "28225.1x"

                                // If name itself contains the price at the end, clean it
                                $cleanName = preg_replace('/\s+\d+[\.\d]*$/', '', $cleanName);

                                if (strlen($cleanName) > 2 &&
                                    ! preg_match('/^(PCS|QTY|ITEM|TOTAL|BAYAR|KEMBALI|POT\.|DISKON|SUBTOTAL)$/i', $cleanName) &&
                                    ! preg_match('/TOTAL/i', $cleanName) &&
                                    ! $this->receiptParser->isPaymentMethodOrNonProductLine($cleanName)) {

                                    $fallbackItems[] = [
                                        'nama' => trim($cleanName),
                                        'harga' => $price,
                                    ];
                                    Log::info('Fallback extracted item: '.trim($cleanName).' - '.$price);
                                }
                            }
                            $bufferName = ''; // Reset buffer
                        } else {
                            // Not a price line, treat as potential Product Name
                            // Skip Date/Time lines, Cashier lines, Invoice/metadata
                            if (preg_match('/TANGGAL|JAM|KASIR|NO\.|ITEM|QTY|HARGA|TOTAL|JALAN|PHONE|TELP|INVOICE/i', $cleanLine)) {
                                $bufferName = '';

                                continue;
                            }
                            // Jangan buffer metadata/metode pembayaran (INVOICE NO.535642, QRIS, dll)
                            if ($this->receiptParser->isPaymentMethodOrNonProductLine($cleanLine)) {
                                $bufferName = '';

                                continue;
                            }

                            // Buffer this line as potential name
                            // Only buffer if it looks like text (has letters) and isn't just a number
                            if (preg_match('/[A-Z]/i', $cleanLine) && ! preg_match('/^\d+$/', $cleanLine)) {
                                // If buffer is not empty, maybe we append? usually receipts have 1 line / product
                                // But sometimes Product Name spans 2 lines. For now, simple overwrite.
                                $bufferName = $cleanLine;
                            }
                        }
                    }

                    if (! empty($fallbackItems)) {
                        $items = $fallbackItems;
                        $entities['items'] = $fallbackItems; // Update entities for saving
                    }
                }

                // Final Fallback: Loose extraction (largest logical number)
                if (! $corrected) {
                    // REPLACEMENT: Use ReceiptParserService
                    $extracted = $this->receiptParser->extractTotalFromOcrText($rawText, false);
                    if ($extracted && $extracted > 0 && $extracted < 10000000) {
                        Log::info("Correcting total using Loose extraction: {$extracted}");
                        $nominal = $extracted;
                        $corrected = true;
                    }
                }

                // Correction 3: If still huge, check if it matches a regex for "JL-3-122508270" pattern
                // and try to find the real total line "TOTAL : 53.500"
                if (! $corrected && preg_match('/TOTAL\s*[:\.]?\s*([\d\.,]+)/i', $rawText, $matches)) {
                    $val = (int) preg_replace('/[^\d]/', '', $matches[1]);
                    if ($val > 0 && $val < 10000000) {
                        $nominal = $val;
                        Log::info("Correcting total using specific 'TOTAL' regex: {$nominal}");
                        $corrected = true;
                    }
                }

                // SAFETY NET: If still huge/suspicious, do NOT record it.
                // Better to ask user to type manual than to record 122 Million.
                if ($nominal > 10000000) {
                    Log::warning('Nominal still suspicious (>10jt) after correction attempts. Invalidating to force manual input.', ['nominal' => $nominal]);
                    $nominal = null;
                }
            }

            // Fallback: If nominal is empty, try to extract from raw_text
            if ((! $nominal || $nominal <= 0) && ! empty($rawText)) {
                Log::info('FinWa-AI nominal empty, trying fallback extraction', [
                    'ocr_job_id' => $ocrJob->id,
                    'raw_text_preview' => mb_substr($rawText, 0, 500),
                ]);

                // REPLACEMENT: Use ReceiptParserService
                $nominal = $this->receiptParser->extractTotalFromOcrText($rawText);

                if ($nominal && $nominal > 0) {
                    Log::info('Fallback extraction successful', [
                        'ocr_job_id' => $ocrJob->id,
                        'nominal' => $nominal,
                    ]);
                }
            }

            // Update OCR job with result
            $ocrJob->update([
                'status' => 'completed',
                'completed_at' => now(),
                'result' => json_encode([
                    'extracted_text' => $rawText,
                    'structured_data' => $data,
                ]),
            ]);

            if (! $nominal || $nominal <= 0) {
                ($this->sendReplyCallback)(
                    "⚠️ *Tidak dapat membaca struk*\n\n".
                    "Maaf, nominal tidak terdeteksi.\n".
                    "Coba kirim foto yang lebih jelas atau ketik manual:\n".
                    '_"belanja 150rb di alfamart"_'
                );

                return;
            }

            // Create transaction from OCR result
            $transactionData = [
                'type' => 'expense',
                'amount' => $nominal,
                // REPLACEMENT: Use callback
                'category_type' => ($this->mapCategoryCallback)($kategori, false), // false = expense (bukan income)
                'description' => $merchant ? "Belanja di {$merchant}" : 'Belanja dari struk',
                'merchant' => $merchant,
                'transaction_date' => now()->toDateString(),
                'source' => 'ocr',
                'confidence_score' => $data['confidence'] ?? 0.8,
            ];

            // If category is generic and merchant is available, try merchant-based refinement
            if ($merchant && in_array($transactionData['category_type'], ['pengeluaran_belanja', 'pengeluaran_lainnya'])) {
                $merchantCategory = CategoryMappingService::resolveByMerchant($merchant);
                if ($merchantCategory !== null) {
                    $transactionData['category_type'] = $merchantCategory;
                }
            }

            // REPLACEMENT: Use TransactionService
            $transaction = $this->transactionService->createTransaction($transactionData);

            if ($transaction) {
                // --- CLEAN UP AI-RETURNED ITEMS BEFORE SENDING CONFIRMATION ---
                // Filter out garbage items that AI might have extracted incorrectly
                if (! empty($items)) {
                    Log::info('Starting item cleanup', ['original_count' => count($items), 'items' => $items]);

                    $cleanedItems = [];
                    foreach ($items as $item) {
                        $itemName = $item['nama'] ?? $item['name'] ?? '';

                        Log::info('Processing item', ['name' => $itemName, 'price' => $item['harga'] ?? $item['price'] ?? 0]);

                        // Skip if name is empty or too short
                        if (empty($itemName) || strlen($itemName) < 3) {
                            Log::info('Skipping: too short or empty');

                            continue;
                        }

                        // Skip if name is just garbage keywords
                        if (preg_match('/^(PCS|QTY|ITEM|POT\\.|DISKON|DISC|BRS|TUNAI|CASH|KEMBALI|CHANGE)$/i', $itemName)) {
                            Log::info("Filtering out garbage item: {$itemName}");

                            continue;
                        }

                        // Skip if name contains "Pot.:" pattern (discount notation)
                        if (preg_match('/^Pot\\.:/i', $itemName)) {
                            Log::info("Filtering out discount line as item: {$itemName}");

                            continue;
                        }

                        // Skip if name looks like "QTY=1" or "BRS=1"
                        if (preg_match('/^(QTY|BRS)=/i', $itemName)) {
                            Log::info("Filtering out metadata line as item: {$itemName}");

                            continue;
                        }

                        // Skip payment methods / aplikasi pembayaran (bukan produk): QRIS Yokee, OVO, AMOUNT, dll
                        if ($this->receiptParser->isPaymentMethodOrNonProductLine($itemName)) {
                            Log::info("Filtering out payment method / non-product line: {$itemName}");

                            continue;
                        }

                        Log::info('Item passed all filters, keeping it', ['name' => $itemName]);
                        $cleanedItems[] = $item;
                    }

                    $items = $cleanedItems;
                    Log::info('Item cleanup complete', ['cleaned_count' => count($items)]);

                    // If all items were filtered out, try fallback extraction from raw text
                    if (empty($items) && ! empty($data['raw_text'])) {
                        Log::info('All items filtered out, attempting fallback extraction from raw text');

                        $rawText = $data['raw_text'];
                        $lines = explode("\n", $rawText);
                        $fallbackItems = [];

                        // Look for product name pattern: usually appears before price lines
                        // Skip first 10 lines (usually store name, address, date, etc.)
                        // In this receipt: "CELAMIS KAOS JUMBO KARET" appears after store info
                        for ($i = 10; $i < count($lines); $i++) {
                            $cleanLine = trim($lines[$i]);

                            // Skip empty, metadata, or system lines
                            if (empty($cleanLine) ||
                                preg_match('/^(PCS|QTY|BRS|POT\.|DISKON|TUNAI|KEMBALI|KASIR|NO\.|TANGGAL|JAM|TELP|FAX|WA|EMAIL|JALAN|JL\.|PELANGGAN|TRANSAKSI|KARYAWAN)/i', $cleanLine) ||
                                preg_match('/\d+\s*x\s*\d+/i', $cleanLine) || // Skip "35000 x 1"
                                preg_match('/^\d+$/i', $cleanLine) || // Skip pure numbers
                                preg_match('/^[\d\.,]+$/i', $cleanLine) || // Skip price-only lines
                                preg_match('/(BONE|STORE|TOKO|MART|SHOP|CAFE|RESTO|WARUNG|@|IG)/i', $cleanLine) || // Skip store names
                                $this->receiptParser->isPaymentMethodOrNonProductLine($cleanLine)) { // Skip QRIS, OVO, AMOUNT, dll
                                continue;
                            }

                            // If line looks like a product name (has letters, reasonable length)
                            // Must have at least 2 words or be longer than 8 chars
                            if (preg_match('/[A-Z]{3,}/i', $cleanLine) && strlen($cleanLine) > 8 && strlen($cleanLine) < 100) {
                                // Check if next few lines contain a price
                                $hasPrice = false;
                                for ($j = $i + 1; $j < min($i + 5, count($lines)); $j++) {
                                    if (preg_match('/\d{3,}/', $lines[$j])) {
                                        $hasPrice = true;
                                        break;
                                    }
                                }

                                if ($hasPrice) {
                                    $fallbackItems[] = [
                                        'nama' => $cleanLine,
                                        'harga' => $transaction->amount, // Use transaction total as item price
                                    ];
                                    Log::info('Fallback extracted product name', ['name' => $cleanLine, 'line_number' => $i]);
                                    break; // Only extract first product for now
                                }
                            }
                        }

                        if (! empty($fallbackItems)) {
                            $items = $fallbackItems;
                            Log::info('Fallback extraction successful', ['items_count' => count($items)]);
                        }
                    }
                }

                // Send detailed receipt confirmation
                // REPLACEMENT: Use TransactionConfirmationService
                $this->confirmationService->sendReceiptConfirmation($transaction, $items, $merchant);
            } else {
                ($this->sendReplyCallback)('⚠️ Gagal mencatat transaksi dari struk. Silakan coba manual.');
            }

        } catch (\Exception $e) {
            Log::error('Error handling FinWa-AI OCR result', [
                'ocr_job_id' => $ocrJob->id,
                'error' => $e->getMessage(),
            ]);
            $this->handleOcrFailure($ocrJob, $e->getMessage());
        }
    }

    /**
     * Handle OCR failure
     *
     * MOVED FROM: ProcessIncomingMessage::handleOcrFailure()
     * LINES: 4379-4397
     */
    protected function handleOcrFailure(OcrJob $ocrJob, string $error): void
    {
        $ocrJob->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error' => $error,
        ]);

        // Log technical error for debugging (not shown to user)
        Log::error('OCR Processing failed', [
            'ocr_job_id' => $ocrJob->id,
            'error' => $error,
        ]);

        // User-friendly error message (hide technical details)
        $userMessage = "⚠️ Maaf, gambar tidak dapat diproses saat ini.\n\n";

        if (str_contains($error, 'cURL error') || str_contains($error, 'Empty reply')) {
            $userMessage .= 'Layanan OCR sedang sibuk. Silakan coba kirim ulang gambar dalam beberapa detik.';
        } elseif (str_contains($error, 'Failed to fetch image')) {
            $userMessage .= 'Gambar tidak dapat diambil. Silakan kirim ulang foto yang lebih jelas.';
        } else {
            $userMessage .= 'Silakan coba lagi atau kirim ulang gambar yang lebih jelas.';
        }

        ($this->sendReplyCallback)($userMessage);
    }

    /**
     * Detect if OCR text is from a bank transfer proof (not a receipt)
     *
     * Uses a weighted scoring system to avoid false positives:
     * - Strong indicators (3 pts): phrases unique to transfer proofs
     * - Medium indicators (2 pts): bank app names, reference numbers
     * - Weak indicators (1 pt): generic words that could appear in receipts too
     *
     * Threshold: score >= 3 = transfer proof
     */
    protected function isTransferProof(string $rawText): bool
    {
        $textLower = strtolower($rawText);
        $score = 0;

        // ==========================================
        // STRONG INDICATORS (3 points each)
        // Phrases that almost certainly indicate a transfer proof
        // Uses REGEX for flexible matching (e.g. "transaksi kamu berhasil")
        // ==========================================
        $strongRegexPatterns = [
            '/transaksi\s+\w*\s*berhasil/i',      // "transaksi kamu berhasil", "transaksi berhasil"
            '/transfer\s+\w*\s*berhasil/i',        // "transfer Anda berhasil", "transfer berhasil"
            '/pembayaran\s+\w*\s*berhasil/i',      // "pembayaran kamu berhasil"
            '/berhasil\s+(di)?kirim/i',             // "berhasil dikirim", "berhasil kirim"
            '/berhasil\s+di\s*(transfer|bayar)/i',  // "berhasil ditransfer"
            '/successfully\s+transferred/i',
            '/transfer\s+successful/i',
            '/payment\s+successful/i',
        ];

        foreach ($strongRegexPatterns as $regex) {
            if (preg_match($regex, $rawText)) {
                $score += 3;
                Log::debug("Transfer proof strong regex match: {$regex}");
                break;
            }
        }

        $strongExactPatterns = [
            'nominal transfer',     // BSI Mobile: "Nominal Transfer Rp 50.000"
            'nama penerima',        // BSI/BCA/BRI: "Nama Penerima"
            'bukti transfer',
            'bukti pembayaran',
            'bukti transaksi',
            'transfer ke ',         // "transfer ke rekening..."
            'kirim uang',
            'pengiriman uang',
            'dana telah dikirim',
            'uang telah dikirim',
            'dikirim ke',
            'terkirim ke',
            'detail transaksi',     // BSI: "Detail Transaksi" dropdown
        ];

        if ($score < 3) { // Only check if no strong regex matched
            foreach ($strongExactPatterns as $pattern) {
                if (str_contains($textLower, $pattern)) {
                    $score += 3;
                    Log::debug("Transfer proof strong exact match: {$pattern}");
                    break;
                }
            }
        }

        // ==========================================
        // MEDIUM INDICATORS (2 points each)
        // Bank/e-wallet app-specific text
        // ==========================================
        $mediumPatterns = [
            // Reference & account info
            'nomor referensi',
            'no referensi',
            'no. referensi',
            'reference number',
            'rekening tujuan',
            'rekening penerima',
            'rekening sumber',
            'nomor rekening',
            'bank penerima',
            'bank tujuan',
            'biaya admin',
            'biaya transfer',
            'saldo rekening',
            'setelah transaksi',

            // Bank app names
            'm-banking',
            'mobile banking',
            'internet banking',
            'livin\' by mandiri',
            'livin by mandiri',
            'bca mobile',
            'myBCA',
            'brimo',
            'bri mobile',
            'bsi mobile',
            'bni mobile',
            'cimb niaga',
            'octo mobile',
            'digibank',
            'permatabank',
            'jago',
            'bank jago',
            'jenius',
            'blu by bca',
            'sea bank',
            'seabank',
            'bank neo',
            'superbank',
            'mytelkomsel',

            // E-wallet specific
            'ovo cash',
            'gopay balance',
            'gopay coins',
            'shopeepay',
            'shopee pay',
            'dana balance',
            'saldo dana',
            'flip transfer',
            'saldo gopay',
            'saldo ovo',

            // BSI specific
            'bank syariah indonesia',
            'alhamdulillah',         // BSI uses "Alhamdulillah, transaksi kamu berhasil!"
        ];

        $mediumMatched = 0;
        foreach ($mediumPatterns as $pattern) {
            if (str_contains($textLower, $pattern)) {
                $mediumMatched++;
                Log::debug("Transfer proof medium match: {$pattern}");
            }
        }
        $score += min($mediumMatched * 2, 6); // Cap at 6 points from medium

        // ==========================================
        // WEAK INDICATORS (1 point each)
        // Could appear in receipts too, but together with others suggest transfer
        // ==========================================
        $weakPatterns = [
            'penerima',
            'pengirim',
            'berhasil',
            'tanggal transaksi',
            'tujuan transfer',
            'sumber dana',
            'catatan transfer',
            'keterangan',
            'lanjutkan',             // BSI: "Lanjutkan" button
            'share',                 // Share/Download buttons on transfer proof
        ];

        $weakMatched = 0;
        foreach ($weakPatterns as $pattern) {
            if (str_contains($textLower, $pattern)) {
                $weakMatched++;
            }
        }
        $score += min($weakMatched, 3); // Cap at 3 points from weak

        // ==========================================
        // NEGATIVE INDICATORS (receipt-specific text that REDUCES score)
        // If these are present, it's more likely a receipt
        // ==========================================
        $receiptPatterns = [
            'total belanja',
            'subtotal',
            'kembalian',
            'kembali',
            'kasir',
            'struk',
            'nota',
            'qty',
            'pcs',
            'item',
            'diskon',
            'ppn',
            'pajak',
            'harga satuan',
        ];

        $receiptMatched = 0;
        foreach ($receiptPatterns as $pattern) {
            if (str_contains($textLower, $pattern)) {
                $receiptMatched++;
            }
        }
        if ($receiptMatched >= 2) {
            $score -= 4; // Strong receipt signals reduce the transfer score
        }

        $isTransfer = $score >= 3;

        Log::info('Transfer proof detection result', [
            'score' => $score,
            'is_transfer' => $isTransfer,
            'text_preview' => mb_substr($rawText, 0, 200),
        ]);

        return $isTransfer;
    }

    /**
     * Extract file path from URL
     *
     * MOVED FROM: ProcessIncomingMessage::extractFilePathFromUrl()
     * LINES: 4782-4826
     */
    public function extractFilePathFromUrl(string $url): string
    {
        // Handle local paths
        if (! str_starts_with($url, 'http')) {
            return $url;
        }

        // Handle public disk URLs
        if ($this->isPublicDiskUrl($url)) {
            $path = parse_url($url, PHP_URL_PATH);
            // Remove /storage prefix if present
            if (str_starts_with($path, '/storage')) {
                return substr($path, 8); // Remove /storage
            }

            return trim($path, '/');
        }

        return $url;
    }

    /**
     * Check if URL is from public disk
     *
     * MOVED FROM: ProcessIncomingMessage::isPublicDiskUrl()
     * LINES: 4828-4848
     */
    /**
     * Check if URL is from public disk
     *
     * MOVED FROM: ProcessIncomingMessage::isPublicDiskUrl()
     * LINES: 4828-4848
     */
    public function isPublicDiskUrl(string $url): bool
    {
        $appUrl = config('app.url');

        return str_starts_with($url, $appUrl);
    }

    /**
     * Process OCR job result (async flow)
     * Handles logic when a message is flagged as coming from OCR queue
     *
     * MOVED FROM: ProcessIncomingMessage::handle() (OCR block)
     * LINES: 1335-1490
     */
    public function processOcrJobResult(OcrJob $ocrJob, string $messageText): void
    {
        Log::info('Message is from OCR, treating as receipt transaction', [
            'message_id' => $this->message->id,
        ]);

        // For receipts: use structured data from OcrJob (total, date) instead of sending to AI
        $metadata = $ocrJob->metadata ?? [];
        $structuredData = $metadata['structured_data'] ?? [];

        // Also check result field
        if (empty($structuredData) && ! empty($ocrJob->result)) {
            $resultData = is_string($ocrJob->result) ? json_decode($ocrJob->result, true) : $ocrJob->result;
            if ($resultData && isset($resultData['structured_data'])) {
                $structuredData = $resultData['structured_data'];
            }
        }

        $fields = $structuredData['fields'] ?? [];
        $entities = $structuredData['entities'] ?? [];

        // Debug logging
        Log::info('OCR structured data for receipt', [
            'message_id' => $this->message->id,
            'ocr_job_id' => $ocrJob->id,
            'has_metadata' => ! empty($metadata),
            'has_structured_data' => ! empty($structuredData),
            'fields_total' => $fields['total'] ?? 'NOT_SET',
            'entities_nominal' => $entities['nominal'] ?? 'NOT_SET',
        ]);

        // PRIORITY 1: Get total from structured data
        $total = isset($fields['total']) && $fields['total'] > 0 ? (int) $fields['total'] : null;
        $dateRaw = $fields['date_raw'] ?? null;

        // PRIORITY 2: If no total in structured data, try to extract from text using regex
        if (! $total) {
            Log::info('No total in structured_data, trying text extraction', [
                'message_id' => $this->message->id,
            ]);
            $total = $this->receiptParser->extractTotalFromOcrText($messageText);
        }

        // PRIORITY 3: If still no total, try FinWa-AI for OCR extraction
        $storeName = null;
        $dateRaw = $dateRaw ?? null;

        if (! $total) {
            $finwaService = new FinWaAIService;
            if ($finwaService->isEnabled()) {
                Log::info('Trying FinWa-AI for OCR extraction', [
                    'message_id' => $this->message->id,
                ]);

                $finwaResult = $finwaService->processOCR($messageText);
                if ($finwaResult['success']) {
                    $finwaEntities = $finwaResult['data']['entities'] ?? [];
                    $total = $finwaEntities['nominal'] ?? null;
                    $storeName = $finwaEntities['merchant'] ?? null;
                    $dateRaw = $dateRaw ?? $finwaEntities['tanggal'] ?? null;

                    Log::info('FinWa-AI OCR extraction result', [
                        'message_id' => $this->message->id,
                        'total' => $total,
                        'store_name' => $storeName,
                        'date' => $dateRaw,
                    ]);
                }
            }
        }

        if ($total && $total > 0) {
            // Create single expense transaction for the receipt total
            Log::info('Creating single receipt transaction', [
                'message_id' => $this->message->id,
                'total' => $total,
                'date_raw' => $dateRaw,
            ]);

            // Determine store name from OCR text if not from FinWa-AI
            if (! $storeName) {
                // Try to get from entities first (FinWa-AI)
                $storeName = $entities['merchant'] ?? null;
                if (! $storeName) {
                    $storeName = $this->receiptParser->extractStoreNameFromOcrText($messageText);
                }
            }

            // Extract items from structured data if available
            $items = $entities['items'] ?? $structuredData['items'] ?? $fields['items'] ?? [];

            // Create transaction data
            $txData = [
                'type' => 'expense',
                'amount' => $total,
                'category_type' => 'pengeluaran_belanja',
                'transaction_date' => $this->receiptParser->parseReceiptDate($dateRaw),
                'description' => $storeName ? "Belanja di {$storeName}" : 'Belanja dari struk',
                'source' => 'receipt_ocr',
                'confidence_score' => 0.95,
                'account_name' => null,
            ];

            // Create the transaction using TransactionService
            $transaction = $this->transactionService->createTransaction($txData, false);

            if ($transaction) {
                // Log items extraction result
                Log::info('OCR transaction created, checking items', [
                    'message_id' => $this->message->id,
                    'transaction_id' => $transaction->id,
                    'items_count' => count($items),
                    'store_name' => $storeName,
                ]);

                // Use sendReceiptConfirmation for detailed product list (if items available)
                if (! empty($items)) {
                    $this->confirmationService->sendReceiptConfirmation($transaction, $items, $storeName);
                } else {
                    // Fallback to simple confirmation if no items
                    $reply = "✅ *Berhasil Dicatat*\n\n";
                    $reply .= '💸 *Pengeluaran*: Rp '.number_format($total, 0, ',', '.')."\n";
                    $reply .= "📁 Kategori: Belanja\n";
                    if ($storeName) {
                        $reply .= "🏪 Toko: {$storeName}\n";
                    }
                    if ($dateRaw) {
                        $reply .= "📅 Tanggal: {$dateRaw}\n";
                    }
                    $reply .= "\n*Struk berhasil dicatat sebagai 1 transaksi pengeluaran.*";

                    ($this->sendReplyCallback)($reply);
                }
            } else {
                ($this->sendReplyCallback)('⚠️ Gagal mencatat transaksi dari struk. Silakan coba lagi.');
            }

            return;
        }

        // PRIORITY 4 (Fallback): if no total found, use AIProcessor to extract via TransactionService
        Log::warning('No total found in OCR data, falling back to TransactionService extraction', [
            'message_id' => $this->message->id,
        ]);
        $optimizedText = $this->receiptParser->optimizeOcrTextForAI($messageText);
        $this->transactionService->handleTransaction($optimizedText);
    }
}
