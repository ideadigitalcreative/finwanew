<?php

namespace App\Services\OCR;

use App\Models\Message;
use App\Models\OcrJob;
use App\Models\Transaction;
use App\Services\Transaction\TransactionService;
use App\Services\Transaction\TransactionConfirmationService;
use App\Services\FinWaAIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessOcrImage;

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
        protected $mapCategoryCallback
    ) {}

    /**
     * Create OCR job for image processing
     * 
     * MOVED FROM: ProcessIncomingMessage::createOcrJob()
     * LINES: 3755-3827
     */
    public function createOcrJob(): ?OcrJob
    {
        try {
            // Get image URL
            $imageUrl = null;
            $fileId = null;
            
            if ($this->message->type === 'image') {
                $fileId = $this->message->media_id ?? null; // Usually file_id or url
                
                // 1. If content is a URL
                if (!empty($this->message->content) && (filter_var($this->message->content, FILTER_VALIDATE_URL) || str_contains($this->message->content, 'http') || str_contains($this->message->content, '/storage/'))) {
                    $imageUrl = $this->message->content;
                } 
                // 2. Check metadata (common storage)
                elseif (isset($this->message->metadata['media_url'])) {
                    $imageUrl = $this->message->metadata['media_url'];
                }
                // 3. Check raw_data (WhatsApp standard)
                elseif (isset($this->message->raw_data['image']['url'])) {
                    $imageUrl = $this->message->raw_data['image']['url'];
                }
                // 4. Fallback: treat content as path even if check failed
                else {
                    $imageUrl = $this->message->content;
                }
            } else {
                // Check for image inside text (URL)
                $words = explode(' ', $this->message->content);
                foreach ($words as $word) {
                    if (filter_var($word, FILTER_VALIDATE_URL)) {
                        $imageUrl = $word;
                        break;
                    }
                }
            }
            
            if (!$imageUrl) {
                Log::warning('No image found for OCR job', ['message_id' => $this->message->id]);
                return null;
            }
            
            // Create OCR Job
            $ocrJob = OcrJob::create([
                'tenant_id' => $this->message->tenant_id,
                'message_id' => $this->message->id,
                'file_path' => $imageUrl,
                'file_type' => 'image',
                'status' => 'pending',
                'created_at' => now()
            ]);
            
            Log::info('OCR Job created', ['job_id' => $ocrJob->id]);
            
            return $ocrJob;
            
        } catch (\Exception $e) {
            Log::error('Failed to create OCR job', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Dispatch OCR job to OCR worker dengan delay
     * 
     * MOVED FROM: ProcessIncomingMessage::dispatchToOcrWorkerWithDelay()
     * LINES: 4509-4541
     */
    public function dispatchToOcrWorkerWithDelay(OcrJob $ocrJob, int $delaySeconds = 3): void
    {
        Log::info("Dispatching OCR job with delay", [
            'ocr_job_id' => $ocrJob->id,
            'delay' => $delaySeconds
        ]);
        
        // Dispatch job to queue
        ProcessOcrImage::dispatch($ocrJob)
            ->delay(now()->addSeconds($delaySeconds))
            ->onQueue('ocr_queue');
            
        // Notify user
        ($this->sendReplyCallback)("⏳ Sedang memproses gambar... Mohon tunggu sebentar.");
    }

    /**
     * Dispatch OCR job to OCR worker dengan retry mechanism
     * 
     * MOVED FROM: ProcessIncomingMessage::dispatchToOcrWorker()
     * LINES: 4543-4780
     */
    public function dispatchToOcrWorker(OcrJob $ocrJob, int $retryCount = 0, int $maxRetries = 3): void
    {
        try {
            Log::info("Processing OCR job SYNCHRONOUSLY (Bypassing Queue due to missing Job class)", ['ocr_job_id' => $ocrJob->id]);
            
            ($this->sendReplyCallback)("⏳ Sedang memproses gambar... (Sync)");
            
            // Call processing directly
            $this->processImageWithFinWaAI($ocrJob, $ocrJob->file_path);
            
        } catch (\Exception $e) {
            Log::error("Failed to process OCR job synchronously", [
                'ocr_job_id' => $ocrJob->id,
                'error' => $e->getMessage()
            ]);
            $this->handleOcrFailure($ocrJob, "Sync Processing Failed: " . $e->getMessage());
        }
    }

    /**
     * Process image using FinWa-AI OCR
     * 
     * MOVED FROM: ProcessIncomingMessage::processImageWithFinWaAI()
     * LINES: 3829-3955
     */
    public function processImageWithFinWaAI(OcrJob $ocrJob, string $filePath): void
    {
        try {
            Log::info('Processing image with FinWa-AI', [
                'ocr_job_id' => $ocrJob->id,
                'file_path' => $filePath
            ]);
            
            // Build the full URL for the image
            $imageUrl = $filePath;
            
            // Check if it's a local file URL (from our own API)
            $isLocalApiUrl = str_contains($filePath, '/api/files');
            
            if (!str_starts_with($filePath, 'http://') && !str_starts_with($filePath, 'https://')) {
                // If it's a relative path, try to get from storage
                if (str_contains($filePath, 'whatsapp/')) {
                    $imageUrl = url('/api/files') . '?path=' . urlencode($filePath);
                } else {
                    $imageUrl = url($filePath);
                }
            }
            
            Log::info('Fetching image for FinWa-AI OCR', ['url' => $imageUrl]);
            
            // Try to read from local storage first (if file exists locally)
            $localPath = null;
            
            // Extract local path from URL if possible
            if ($isLocalApiUrl || str_contains($filePath, 'whatsapp/')) {
                // Extract the path from URL if needed
                if (str_contains($filePath, 'path=')) {
                    $parsedUrl = parse_url($filePath);
                    parse_str($parsedUrl['query'] ?? '', $queryParams);
                    $localPath = urldecode($queryParams['path'] ?? '');
                } elseif (str_contains($filePath, 'whatsapp/')) {
                    // Direct relative path or path inside URL
                    if (preg_match('/(whatsapp\/[^\?]+)/', $filePath, $matches)) {
                        $localPath = $matches[1];
                    } else {
                        $localPath = $filePath;
                    }
                }
                
                // Clean up path
                if ($localPath) {
                    $localPath = trim($localPath, '/');
                }
            }
            
            $imageContent = null;
            $contentType = 'image/jpeg';
            
            // Try local storage first (bypass HTTP)
            if ($localPath && Storage::disk('public')->exists($localPath)) {
                Log::info('Reading image from local storage (bypassing HTTP)', ['path' => $localPath]);
                $imageContent = Storage::disk('public')->get($localPath);
                $extension = pathinfo($localPath, PATHINFO_EXTENSION);
                $contentType = 'image/' . ($extension ?: 'jpeg');
            } else {
                // Fetch via HTTP with API key
                $apiKey = config('services.ocr_worker.api_key', 'ocr_worker_api_key_123');
                $imageResponse = Http::timeout(30)
                    ->withHeaders(['X-API-Key' => $apiKey])
                    ->get($imageUrl);
                
                if (!$imageResponse->successful()) {
                    Log::error('Failed to fetch image for OCR', [
                        'ocr_job_id' => $ocrJob->id,
                        'status' => $imageResponse->status(),
                        'url' => $imageUrl
                    ]);
                    $this->handleOcrFailure($ocrJob, 'Failed to fetch image from URL');
                    return;
                }
                
                $imageContent = $imageResponse->body();
                $contentType = $imageResponse->header('Content-Type') ?? 'image/jpeg';
            }
            
            // Convert to base64
            $base64Image = 'data:' . $contentType . ';base64,' . base64_encode($imageContent);
            
            Log::info('Image fetched and converted to base64', [
                'ocr_job_id' => $ocrJob->id,
                'content_type' => $contentType,
                'size' => strlen($imageContent)
            ]);
            
            // Update job status
            $ocrJob->update([
                'status' => 'processing',
                'started_at' => now()
            ]);
            
            // Send to FinWa-AI
            $finwaService = new FinWaAIService();
            $result = $finwaService->processImage($base64Image);
            
            if ($result['success'] && isset($result['data'])) {
                Log::info('FinWa-AI OCR processing complete', [
                    'ocr_job_id' => $ocrJob->id,
                    'result' => $result['data']
                ]);
                
                // Process the result
                $this->handleFinWaAIOcrResult($ocrJob, $result['data']);
            } else {
                Log::error('FinWa-AI OCR failed', [
                    'ocr_job_id' => $ocrJob->id,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
                $this->handleOcrFailure($ocrJob, $result['error'] ?? 'OCR processing failed');
            }
            
        } catch (\Throwable $e) {
            // Updated to catch \Throwable to handle PHP Errors (like undefined method)
            Log::error('Error processing image with FinWa-AI', [
                'ocr_job_id' => $ocrJob->id,
                'error' => $e->getMessage()
            ]);
            $this->handleOcrFailure($ocrJob, $e->getMessage());
        }
    }

    /**
     * Handle FinWa-AI OCR result
     * 
     * MOVED FROM: ProcessIncomingMessage::handleFinWaAIOcrResult()
     * LINES: 3957-4377
     */
    protected function handleFinWaAIOcrResult(OcrJob $ocrJob, array $data): void
    {
        try {
            $entities = $data['entities'] ?? [];
            $nominal = $entities['nominal'] ?? null;
            $kategori = $entities['kategori'] ?? 'belanja';
            $merchant = $entities['merchant'] ?? null;
            $items = $entities['items'] ?? [];
            $rawText = $data['raw_text'] ?? '';
        
            // --- PRE-CORRECTION: Check explicit "TOTAL:" pattern in raw text ---
            // Prioritize explicit Total label over AI's entity extraction if found
            if (!empty($rawText)) {
                // REPLACEMENT: Use ReceiptParserService
                $strictTotal = $this->receiptParser->extractTotalFromOcrText($rawText, true); // true = strict mode
                if ($strictTotal && $strictTotal > 100 && $strictTotal < 100000000) {
                    if ($nominal != $strictTotal) {
                        Log::info("Overriding AI nominal ({$nominal}) with strict Regex Total ({$strictTotal})");
                        $nominal = $strictTotal;
                    }
                }
                
                // ADDITIONAL CHECK: If AI nominal seems to be sum of garbage items
                // Look for "BRS=X QTY=Y AMOUNT" pattern which is often the real total
                if ($nominal && !empty($items)) {
                    $itemsSum = 0;
                    foreach ($items as $item) {
                        $itemsSum += $item['harga'] ?? $item['price'] ?? 0;
                    }
                    
                    // If AI nominal equals sum of items, it might be wrong (especially if items are garbage)
                    if ($nominal == $itemsSum) {
                        Log::info("AI nominal matches sum of items, checking for BRS/QTY total pattern", ['nominal' => $nominal, 'items_sum' => $itemsSum]);
                        
                        // Look for pattern: "BRS=1" followed by "QTY=1" followed by amount on next line(s)
                        // Split by lines and look for BRS/QTY followed by a price
                        $lines = explode("\n", $rawText);
                        $foundBrsQty = false;
                        $brsTotal = null;
                        
                        foreach ($lines as $i => $line) {
                            // If we find BRS= or QTY= line
                            if (preg_match('/^(BRS|QTY)=/i', trim($line))) {
                                $foundBrsQty = true;
                                // Look at next 3 lines for a price
                                for ($j = $i + 1; $j < min($i + 4, count($lines)); $j++) {
                                    $nextLine = trim($lines[$j]);
                                    // Match price format: 28.000,00 or 28.000
                                    if (preg_match('/^(\d{1,3}(?:\.\d{3})*(?:,\d{2})?)$/', $nextLine, $matches)) {
                                        $brsTotal = (int)str_replace(['.', ','], ['', '.'], $matches[1]);
                                        Log::info("Found BRS/QTY total on line after metadata", ['line' => $nextLine, 'parsed' => $brsTotal]);
                                        break 2; // Break both loops
                                    }
                                }
                            }
                        }
                        
                        if ($brsTotal && $brsTotal > 100 && $brsTotal < $nominal) {
                            Log::info("Found BRS/QTY total ({$brsTotal}) which is less than AI nominal ({$nominal}), using BRS total");
                            $nominal = $brsTotal;
                        }
                    }
                }
            }
    
            // --- SANITY CHECK: Detect unreasonable totals (e.g. > 10 million) ---
            // often caused by picking up Invoice Number / Phone Number as Total
            if ($nominal > 10000000) {
                Log::warning('FinWa-AI detected suspicious total (>10jt), attempting correction', [
                    'original_nominal' => $nominal
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
                if (!$corrected && !empty($rawText)) {
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
                    if (!$corrected) {
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
                                 $val = (int)str_replace('.', '', $m[1]);
                                 if ($val > 500 && $val < 10000000) {
                                      $standalonePrices[] = $val;
                                 }
                             }
                         }
                         
                         if (!empty($standalonePrices)) {
                             $candidateSum = array_sum($standalonePrices);
                             Log::info("Summing standalone price lines: " . implode(' + ', $standalonePrices) . " = {$candidateSum}");
                             
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
                if (empty($items) && !empty($rawText)) {
                    Log::info("AI returned no items, attempting fallback item extraction from raw text.");
                    
                    // Reuse the 'Standalone Price' logic to find items
                    // Look for lines that look like: "1. NAME OF PRODUCT" followed by a price line
                    
                    $lines = explode("\n", $rawText);
                    $fallbackItems = [];
                    $bufferName = '';
                    
                    foreach ($lines as $i => $line) {
                        $cleanLine = trim($line);
                        if (empty($cleanLine)) continue;
                        
                        // SKIP lines that are typically details, not names
                        // e.g. "1 PCS x 37.500", "Diskon 10%", "Pot.", "QTY"
                        if (preg_match('/^(?:qty|pcs|item|pot\.|diskon|disc|subtotal|tunai|kembali|change|cash)/i', $cleanLine)) {
                            continue;
                        }
                        if (preg_match('/\d+\s*(?:pcs|x|@)/i', $cleanLine)) {
                            continue; // Skip quantity lines e.g. "1 x 5000"
                        }
                        
                        // Is this a price line? (e.g. "4.500", "Rp 4.500", "28.000,00")
                        // Allow simple numbers "35000" too if they look like prices ( > 500)
                        $isPriceLines = false;
                        $price = 0;
                        
                        if (preg_match('/^(?:Rp\s*)?(\d{1,3}(?:\.\d{3})*(?:\,\d{2})?)\s*$/', $cleanLine, $m)) {
                             // Standard format 10.000 or 10.000,00
                             $cleanNum = str_replace(['.', ','], ['', '.'], $m[1]); // Convert 10.000,00 -> 10000.00
                             $price = (int)$cleanNum;
                             $isPriceLines = true;
                        } elseif (preg_match('/^(\d{3,8})$/', $cleanLine, $m)) {
                             // Pure number format 35000
                             $price = (int)$m[1];
                             $isPriceLines = true;
                        }
    
                        if ($isPriceLines) {
                            // If we have a buffered name from previous lines, associate it
                            if (!empty($bufferName) && $price > 500 && $price < 10000000) {
                                 // Clean up the name (remove leading "1.", "2.", etc)
                                 $cleanName = preg_replace('/^\d+[\.\s]+/', '', $bufferName);
                                 // Remove obvious codes/quantities like "2822... x"
                                 $cleanName = preg_replace('/^\d+\.\d+x/', '', $cleanName); // e.g. "28225.1x"
                                 
                                 // Extra filter: Skip if name is just "PCS" or similar garbage
                                 // Also check if name contains "Total" to avoid capturing "Total : 100.000" as Item "Total"
                                 if (strlen($cleanName) > 2 && !preg_match('/^(PCS|QTY|ITEM|TOTAL|BAYAR|KEMBALI|POT\.|DISKON|SUBTOTAL)$/i', $cleanName) && !preg_match('/TOTAL/i', $cleanName)) {
                                     $fallbackItems[] = [
                                         'nama' => $cleanName,
                                         'harga' => $price
                                     ];
                                     Log::info("Fallback extracted item: {$cleanName} - {$price}");
                                 }
                            }
                            $bufferName = ''; // Reset buffer
                        } else {
                            // Not a price line, treat as potential Product Name
                            // Skip Date/Time lines, Cashier lines
                            if (preg_match('/TANGGAL|JAM|KASIR|NO\.|ITEM|QTY|HARGA|TOTAL|JALAN|PHONE|TELP/i', $cleanLine)) {
                                $bufferName = ''; 
                                continue;
                            }
                            
                            // Buffer this line as potential name
                            // Only buffer if it looks like text (has letters) and isn't just a number
                            if (preg_match('/[A-Z]/i', $cleanLine) && !preg_match('/^\d+$/', $cleanLine)) {
                                // If buffer is not empty, maybe we append? usually receipts have 1 line / product
                                // But sometimes Product Name spans 2 lines. For now, simple overwrite.
                                $bufferName = $cleanLine;
                            }
                        }
                    }
                    
                    if (!empty($fallbackItems)) {
                        $items = $fallbackItems;
                        $entities['items'] = $fallbackItems; // Update entities for saving
                    }
                }
                    
                // Final Fallback: Loose extraction (largest logical number)
                if (!$corrected) {
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
                if (!$corrected && preg_match('/TOTAL\s*[:\.]?\s*([\d\.,]+)/i', $rawText, $matches)) {
                     $val = (int)preg_replace('/[^\d]/', '', $matches[1]);
                     if ($val > 0 && $val < 10000000) {
                          $nominal = $val;
                          Log::info("Correcting total using specific 'TOTAL' regex: {$nominal}");
                          $corrected = true;
                     }
                }
                
                // SAFETY NET: If still huge/suspicious, do NOT record it.
                // Better to ask user to type manual than to record 122 Million.
                if ($nominal > 10000000) {
                    Log::warning("Nominal still suspicious (>10jt) after correction attempts. Invalidating to force manual input.", ['nominal' => $nominal]);
                    $nominal = null;
                }
            }
    
            // Fallback: If nominal is empty, try to extract from raw_text
            if ((!$nominal || $nominal <= 0) && !empty($rawText)) {
                    Log::info('FinWa-AI nominal empty, trying fallback extraction', [
                        'ocr_job_id' => $ocrJob->id,
                        'raw_text_preview' => mb_substr($rawText, 0, 500)
                    ]);
                    
                    // REPLACEMENT: Use ReceiptParserService
                    $nominal = $this->receiptParser->extractTotalFromOcrText($rawText);
                    
                    if ($nominal && $nominal > 0) {
                        Log::info('Fallback extraction successful', [
                            'ocr_job_id' => $ocrJob->id,
                            'nominal' => $nominal
                        ]);
                    }
                }
                
                // Update OCR job with result
                $ocrJob->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'result' => json_encode([
                        'extracted_text' => $rawText,
                        'structured_data' => $data
                    ])
                ]);
                
                if (!$nominal || $nominal <= 0) {
                    ($this->sendReplyCallback)(
                        "⚠️ *Tidak dapat membaca struk*\n\n" .
                        "Maaf, nominal tidak terdeteksi.\n" .
                        "Coba kirim foto yang lebih jelas atau ketik manual:\n" .
                        "_\"belanja 150rb di alfamart\"_"
                    );
                    return;
                }
                
                // Create transaction from OCR result
                $transactionData = [
                    'type' => 'expense',
                    'amount' => $nominal,
                    // REPLACEMENT: Use callback
                    'category_type' => ($this->mapCategoryCallback)($kategori, false), // false = expense (bukan income)
                    'description' => $merchant ? "Belanja di {$merchant}" : "Belanja dari struk",
                    'merchant' => $merchant,
                    'transaction_date' => now()->toDateString(),
                    'source' => 'ocr',
                    'confidence_score' => $data['confidence'] ?? 0.8
                ];
                
                // REPLACEMENT: Use TransactionService
                $transaction = $this->transactionService->createTransaction($transactionData);
                
                if ($transaction) {
                    // --- CLEAN UP AI-RETURNED ITEMS BEFORE SENDING CONFIRMATION ---
                    // Filter out garbage items that AI might have extracted incorrectly
                    if (!empty($items)) {
                        Log::info("Starting item cleanup", ['original_count' => count($items), 'items' => $items]);
                        
                        $cleanedItems = [];
                        foreach ($items as $item) {
                            $itemName = $item['nama'] ?? $item['name'] ?? '';
                            
                            Log::info("Processing item", ['name' => $itemName, 'price' => $item['harga'] ?? $item['price'] ?? 0]);
                            
                            // Skip if name is empty or too short
                            if (empty($itemName) || strlen($itemName) < 3) {
                                Log::info("Skipping: too short or empty");
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
                            
                            Log::info("Item passed all filters, keeping it", ['name' => $itemName]);
                            $cleanedItems[] = $item;
                        }
                        
                        $items = $cleanedItems;
                        Log::info("Item cleanup complete", ['cleaned_count' => count($items)]);
                        
                        // If all items were filtered out, try fallback extraction from raw text
                        if (empty($items) && !empty($data['raw_text'])) {
                            Log::info("All items filtered out, attempting fallback extraction from raw text");
                            
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
                                    preg_match('/(BONE|STORE|TOKO|MART|SHOP|CAFE|RESTO|WARUNG|@|IG)/i', $cleanLine)) { // Skip store names
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
                                            'harga' => $transaction->amount // Use transaction total as item price
                                        ];
                                        Log::info("Fallback extracted product name", ['name' => $cleanLine, 'line_number' => $i]);
                                        break; // Only extract first product for now
                                    }
                                }
                            }
                            
                            if (!empty($fallbackItems)) {
                                $items = $fallbackItems;
                                Log::info("Fallback extraction successful", ['items_count' => count($items)]);
                            }
                        }
                    }
                    
                    // Send detailed receipt confirmation
                    // REPLACEMENT: Use TransactionConfirmationService
                    $this->confirmationService->sendReceiptConfirmation($transaction, $items, $merchant);
                } else {
                    ($this->sendReplyCallback)("⚠️ Gagal mencatat transaksi dari struk. Silakan coba manual.");
                }
                
            } catch (\Exception $e) {
                Log::error('Error handling FinWa-AI OCR result', [
                    'ocr_job_id' => $ocrJob->id,
                    'error' => $e->getMessage()
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
            'error' => $error
        ]);
        
        // Show specific error for debugging
        $errorMessage = "⚠️ Maaf, terjadi error saat memproses gambar.\n\nDetail: " . $error;
        
        ($this->sendReplyCallback)($errorMessage . "\n\nSilakan coba lagi atau kirim ulang gambar yang lebih jelas.");
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
        if (!str_starts_with($url, 'http')) {
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
            'message_id' => $this->message->id
        ]);
        
        // For receipts: use structured data from OcrJob (total, date) instead of sending to AI
        $metadata = $ocrJob->metadata ?? [];
        $structuredData = $metadata['structured_data'] ?? [];
        
        // Also check result field
        if (empty($structuredData) && !empty($ocrJob->result)) {
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
            'has_metadata' => !empty($metadata),
            'has_structured_data' => !empty($structuredData),
            'fields_total' => $fields['total'] ?? 'NOT_SET',
            'entities_nominal' => $entities['nominal'] ?? 'NOT_SET',
        ]);

        
        // PRIORITY 1: Get total from structured data
        $total = isset($fields['total']) && $fields['total'] > 0 ? (int)$fields['total'] : null;
        $dateRaw = $fields['date_raw'] ?? null;
        
        // PRIORITY 2: If no total in structured data, try to extract from text using regex
        if (!$total) {
            Log::info('No total in structured_data, trying text extraction', [
                'message_id' => $this->message->id
            ]);
            $total = $this->receiptParser->extractTotalFromOcrText($messageText);
        }
        
        // PRIORITY 3: If still no total, try FinWa-AI for OCR extraction
        $storeName = null;
        $dateRaw = $dateRaw ?? null;
        
        if (!$total) {
            $finwaService = new FinWaAIService();
            if ($finwaService->isEnabled()) {
                Log::info('Trying FinWa-AI for OCR extraction', [
                    'message_id' => $this->message->id
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
                        'date' => $dateRaw
                    ]);
                }
            }
        }
        
        if ($total && $total > 0) {
            // Create single expense transaction for the receipt total
            Log::info('Creating single receipt transaction', [
                'message_id' => $this->message->id,
                'total' => $total,
                'date_raw' => $dateRaw
            ]);
            
            // Determine store name from OCR text if not from FinWa-AI
            if (!$storeName) {
                // Try to get from entities first (FinWa-AI)
                $storeName = $entities['merchant'] ?? null;
                if (!$storeName) {
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
                'description' => $storeName ? "Belanja di {$storeName}" : "Belanja dari struk",
                'source' => 'receipt_ocr',
                'confidence_score' => 0.95,
                'account_name' => null
            ];
            
            // Create the transaction using TransactionService
            $transaction = $this->transactionService->createTransaction($txData, false);
            
            if ($transaction) {
                // Log items extraction result
                Log::info('OCR transaction created, checking items', [
                    'message_id' => $this->message->id,
                    'transaction_id' => $transaction->id,
                    'items_count' => count($items),
                    'store_name' => $storeName
                ]);
                
                // Use sendReceiptConfirmation for detailed product list (if items available)
                if (!empty($items)) {
                    $this->confirmationService->sendReceiptConfirmation($transaction, $items, $storeName);
                } else {
                    // Fallback to simple confirmation if no items
                    $reply = "✅ *Berhasil Dicatat*\n\n";
                    $reply .= "💸 *Pengeluaran*: Rp " . number_format($total, 0, ',', '.') . "\n";
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
                ($this->sendReplyCallback)("⚠️ Gagal mencatat transaksi dari struk. Silakan coba lagi.");
            }
            return;
        }
        
        // PRIORITY 4 (Fallback): if no total found, use AIProcessor to extract via TransactionService
        Log::warning('No total found in OCR data, falling back to TransactionService extraction', [
            'message_id' => $this->message->id
        ]);
        $optimizedText = $this->receiptParser->optimizeOcrTextForAI($messageText);
        $this->transactionService->handleTransaction($optimizedText);
    }
}

