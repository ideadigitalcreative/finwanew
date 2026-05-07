<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OcrJobController extends Controller
{
    /**
     * Update OCR job status
     */
    public function update(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'extracted_text' => 'nullable|string',
            'confidence_score' => 'nullable|numeric|min:0|max:1',
            'processing_time_ms' => 'nullable|integer',
            'status' => 'required|in:pending,processing,completed,failed',
            'error_message' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid payload',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            // Find job without tenant scope first to ensure we can identify it
            $ocrJob = \App\Models\OcrJob::find($id);

            if (! $ocrJob && method_exists(\App\Models\OcrJob::class, 'withoutGlobalScopes')) {
                $ocrJob = \App\Models\OcrJob::withoutGlobalScopes()->find($id);
            }

            if (! $ocrJob) {
                Log::error('OCR Job not found in callback', ['job_id' => $id, 'tenant_id' => $request->input('tenant_id')]);

                return response()->json([
                    'success' => false,
                    'error' => "OCR Job #{$id} not found.",
                ], 404);
            }

            // If we have a tenant_id, we might need it for further processing
            if ($request->has('tenant_id')) {
                config(['app.current_tenant_id' => $request->input('tenant_id')]);
            }

            $baseMetadata = $ocrJob->metadata ?? [];
            $updateMetadata = [
                'confidence_score' => $request->input('confidence_score', 0),
                'processing_time_ms' => $request->input('processing_time_ms', 0),
                'updated_at' => now()->toIso8601String(),
            ];

            // Merge metadata from request if provided (for lines_data)
            if ($request->has('metadata') && is_array($request->input('metadata'))) {
                $updateMetadata = array_merge($updateMetadata, $request->input('metadata'));
            }

            $ocrJob->update([
                'extracted_text' => $request->input('extracted_text'),
                'metadata' => array_merge($baseMetadata, $updateMetadata),
                'status' => $request->input('status'),
                'error_message' => $request->input('error_message'),
                'completed_at' => $request->input('status') === 'completed' ? now() : null,
            ]);

            // If OCR completed, process extracted text with AI
            if ($request->input('status') === 'completed') {
                $extractedText = trim($request->input('extracted_text', ''));
                $message = $ocrJob->message;

                // Log metadata untuk debugging
                $metadata = $ocrJob->metadata ?? [];
                Log::info('OCR completed', [
                    'message_id' => $message->id,
                    'ocr_job_id' => $ocrJob->id,
                    'has_structured_data' => isset($metadata['structured_data']),
                    'has_lines_data' => isset($metadata['lines_data']),
                    'metadata_keys' => array_keys($metadata),
                ]);

                if (empty($extractedText)) {
                    // OCR completed but no text extracted - send error notification
                    Log::warning('OCR completed but no text extracted', [
                        'message_id' => $message->id,
                        'ocr_job_id' => $ocrJob->id,
                        'confidence_score' => $request->input('confidence_score', 0),
                    ]);

                    $this->sendOcrFailedNotification($message);

                    return response()->json([
                        'success' => true,
                        'message' => 'OCR job updated (no text extracted)',
                    ]);
                }

                // Update message with extracted text and change type to 'text'
                $message->update([
                    'type' => 'text',
                    'content' => $extractedText,
                ]);

                // Reload message to ensure fresh data
                $message->refresh();

                Log::info('Message updated with OCR extracted text', [
                    'message_id' => $message->id,
                    'ocr_job_id' => $ocrJob->id,
                    'text_length' => strlen($extractedText),
                    'extracted_text_preview' => mb_substr($extractedText, 0, 100),
                ]);

                // Get metadata if available
                $metadata = null;
                if ($ocrJob->metadata && is_array($ocrJob->metadata)) {
                    $metadata = $ocrJob->metadata;
                } elseif ($request->has('metadata') && is_array($request->input('metadata'))) {
                    $metadata = $request->input('metadata');
                }

                // Send notification that OCR is complete
                $this->sendOcrCompleteNotification($message, $extractedText, $metadata);

                // Dispatch job to process OCR text as transaction
                // Use fresh message instance
                \App\Jobs\ProcessIncomingMessage::dispatch($message->fresh());

                Log::info('ProcessIncomingMessage job dispatched after OCR completion', [
                    'message_id' => $message->id,
                    'message_type' => $message->type,
                    'content_preview' => mb_substr($message->content, 0, 100),
                ]);
            }

            // If OCR failed, send error notification (only once)
            if ($request->input('status') === 'failed') {
                $message = $ocrJob->message;
                $errorMessage = $request->input('error_message', 'Gagal memproses gambar');

                Log::error('OCR job failed', [
                    'message_id' => $message->id,
                    'ocr_job_id' => $ocrJob->id,
                    'error_message' => $errorMessage,
                ]);

                // Check if error notification was already sent
                $metadata = $ocrJob->metadata ?? [];
                if (empty($metadata['error_notification_sent'])) {
                    $this->sendOcrFailedNotification($message, $errorMessage);

                    // Mark as sent to prevent duplicate notifications
                    $ocrJob->update([
                        'metadata' => array_merge($metadata, ['error_notification_sent' => true]),
                    ]);
                } else {
                    Log::info('OCR error notification already sent, skipping', [
                        'ocr_job_id' => $ocrJob->id,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'OCR job updated',
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating OCR job', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send notification that OCR processing is complete
     */
    protected function sendOcrCompleteNotification($message, string $extractedText, ?array $metadata = null): void
    {
        try {
            Log::info('sendOcrCompleteNotification called', [
                'message_id' => $message->id,
                'extracted_text_length' => strlen($extractedText),
                'has_metadata' => ! empty($metadata),
            ]);

            $channel = Channel::find($message->channel_id);
            if (! $channel) {
                Log::warning('Channel not found for OCR notification', [
                    'message_id' => $message->id,
                    'channel_id' => $message->channel_id,
                ]);

                return;
            }

            $config = $channel->config ?? [];
            $sessionId = $config['session_id'] ?? null;

            if (! $sessionId) {
                Log::warning('Session ID not found for OCR notification', [
                    'message_id' => $message->id,
                    'channel_id' => $message->channel_id,
                ]);

                return;
            }

            // Extract sender phone number
            $senderId = $message->sender_id ?? '';
            $phoneNumber = str_replace(['@c.us', '@g.us'], '', $senderId);

            if (empty($phoneNumber)) {
                Log::warning('Phone number not found for OCR notification', [
                    'message_id' => $message->id,
                    'sender_id' => $senderId,
                ]);

                return;
            }

            Log::info('Extracting products from OCR text', [
                'message_id' => $message->id,
                'metadata_keys' => ! empty($metadata) ? array_keys($metadata) : [],
            ]);

            // Extract structured data from OCR text (fields + items)
            $ocrData = $this->extractProductsFromOcrText($extractedText, $metadata);
            $items = $ocrData['items'] ?? [];
            $fields = $ocrData['fields'] ?? [];

            Log::info('OCR data extracted', [
                'message_id' => $message->id,
                'items_count' => count($items),
                'has_fields' => ! empty($fields),
            ]);

            $reply = "✅ *Struk berhasil dibaca!*\n\n";

            // Show fields if available
            if (! empty($fields)) {
                if (! empty($fields['date_raw'])) {
                    $reply .= "📅 *Tanggal:* {$fields['date_raw']}\n";
                }
                if (! empty($fields['total'])) {
                    $total = number_format($fields['total'], 0, ',', '.');
                    $reply .= "💰 *Total:* Rp {$total}\n";
                }
                if (! empty($fields['subtotal'])) {
                    $subtotal = number_format($fields['subtotal'], 0, ',', '.');
                    $reply .= "📊 *Subtotal:* Rp {$subtotal}\n";
                }
                if (! empty($fields['discount_percent']) && $fields['discount_percent'] > 0) {
                    $reply .= "🎁 *Diskon:* {$fields['discount_percent']}%\n";
                }
                $reply .= "\n";
            }

            // Show product list if found
            if (! empty($items)) {
                $reply .= "🛒 *Daftar Produk yang Terdeteksi:*\n";
                foreach ($items as $idx => $item) {
                    $name = $item['name'] ?? 'Produk';
                    $qty = $item['qty'] ?? 1;
                    $price = isset($item['price']) ? number_format($item['price'], 0, ',', '.') : '-';
                    $lineTotal = isset($item['line_total']) ? number_format($item['line_total'], 0, ',', '.') : '-';

                    $reply .= ($idx + 1).". {$name}\n";
                    if ($qty > 1) {
                        $reply .= "   Qty: {$qty} x Rp {$price} = Rp {$lineTotal}\n";
                    } else {
                        $reply .= "   Harga: Rp {$price}\n";
                    }
                    $reply .= "\n"; // Add extra spacing between products
                }
            } else {
                // Fallback: show preview text
                $preview = mb_substr(trim($extractedText), 0, 150);
                if (mb_strlen($extractedText) > 150) {
                    $preview .= '...';
                }
                $reply .= "📄 *Teks yang terdeteksi:*\n";
                $reply .= "```\n{$preview}\n```\n\n";
            }

            $reply .= "🔄 Sedang memproses transaksi...\n";

            Log::info('Sending OCR notification via WhatsApp', [
                'message_id' => $message->id,
                'session_id' => $sessionId,
                'phone_number' => $phoneNumber,
                'reply_length' => strlen($reply),
                'reply_preview' => mb_substr($reply, 0, 200),
            ]);

            $whatsappService = new WhatsAppService;
            $result = $whatsappService->sendMessage($sessionId, $phoneNumber, $reply);

            Log::info('OCR notification sent', [
                'message_id' => $message->id,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send OCR complete notification', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Extract structured data from OCR text (fields + items)
     * Returns array with 'fields' and 'items' keys
     * Prioritizes structured_data from LLM if available
     */
    protected function extractProductsFromOcrText(string $text, ?array $metadata = null): array
    {
        $result = [
            'fields' => [],
            'items' => [],
        ];

        // Priority 1: Use structured_data from LLM if available
        if ($metadata && isset($metadata['structured_data']) && is_array($metadata['structured_data']) && ! empty($metadata['structured_data'])) {
            $structured = $metadata['structured_data'];
            $result['fields'] = $structured['fields'] ?? [];
            $result['items'] = $structured['items'] ?? [];

            // Validate and clean items (pass text for price extraction if needed)
            if (! empty($result['items'])) {
                $result['items'] = $this->validateAndCleanItems($result['items'], $text);
            }

            Log::info('Using LLM structured data', [
                'items_count' => count($result['items']),
                'fields' => $result['fields'],
                'items_preview' => array_slice($result['items'], 0, 3),
            ]);

            // If LLM didn't extract items but we have text, fallback to rule-based
            if (empty($result['items']) && ! empty($text)) {
                Log::info('LLM returned empty items, falling back to rule-based extraction');
                // Continue to rule-based extraction below
            } else {
                return $result;
            }
        }

        Log::info('LLM structured data not available, using rule-based extraction', [
            'has_metadata' => ! empty($metadata),
            'has_structured_data' => ! empty($metadata['structured_data'] ?? []),
            'structured_data_empty' => empty($metadata['structured_data'] ?? []),
            'metadata_keys' => ! empty($metadata) ? array_keys($metadata) : [],
        ]);

        // Priority 2: Use lines_data from metadata if available (more accurate)
        $lines = [];
        if ($metadata && isset($metadata['lines_data']) && is_array($metadata['lines_data'])) {
            foreach ($metadata['lines_data'] as $lineData) {
                if (isset($lineData['text'])) {
                    $lines[] = trim($lineData['text']);
                }
            }
        }

        // Fallback to text splitting if no metadata
        if (empty($lines)) {
            $lines = array_map('trim', explode("\n", $text));
        }

        // Filter out empty lines
        $lines = array_filter($lines, function ($line) {
            return ! empty($line);
        });
        $lines = array_values($lines); // Re-index

        // Extract fields (date, subtotal, total, discount)
        // Use fields from LLM if available, otherwise extract from text
        if (empty($result['fields'])) {
            $result['fields'] = $this->extractFields($text, $lines);
        } else {
            // Merge with extracted fields (LLM might have missed some)
            $extractedFields = $this->extractFields($text, $lines);
            // Use LLM fields but fill missing values from extracted
            foreach ($extractedFields as $key => $value) {
                if (empty($result['fields'][$key]) && ! empty($value)) {
                    $result['fields'][$key] = $value;
                }
            }
        }

        // Extract items (products with qty, price, line_total)
        // Only extract if LLM didn't provide items
        if (empty($result['items'])) {
            $result['items'] = $this->extractItems($lines, $text);
        }

        return $result;
    }

    /**
     * Validate and clean items from LLM
     * Also merges multi-line product names if LLM didn't merge them
     *
     * @param  array  $items  Items from LLM
     * @param  string|null  $ocrText  Original OCR text for price extraction fallback
     */
    protected function validateAndCleanItems(array $items, ?string $ocrText = null): array
    {
        $cleaned = [];
        $processed = [];

        foreach ($items as $index => $item) {
            if (in_array($index, $processed)) {
                continue;
            }

            $name = trim($item['name'] ?? '');
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $price = max(0, (int) ($item['price'] ?? 0));
            $lineTotal = max(0, (int) ($item['line_total'] ?? ($price * $qty)));

            // Skip if name is too short or empty
            if (strlen($name) < 3) {
                continue;
            }

            // Skip if looks like address or header
            if (preg_match('/^(J\.|Jl\.|Kec\.|Kota|Sulawesi|INVOICE|AZMEDIA|Nomor|Tanggal|NPWP)/i', $name)) {
                continue;
            }

            // Skip phone numbers
            if (preg_match('/.*\d{5,}.*/', $name)) {
                continue;
            }

            // Skip transaction IDs/dates
            if (preg_match('/^\d{2}[\/\-\.]\d{2}[\/\-\.]\d{2}/', $name)) {
                continue;
            }

            // Skip coupon lines
            if (stripos($name, 'kupon') !== false || stripos($name, 'coupon') !== false) {
                continue;
            }

            // Skip items with invalid prices
            if ($price <= 0 || $price >= 100000000) {
                continue;
            }

            // Try to merge with next item if it looks like a continuation
            // Pattern: "Door Frame Water" + "Tank 60X160" = "Door Frame Water Tank 60X160"
            // Pattern: "X Banner Hitam" + "Standar 60x160" = "X Banner Hitam Standar 60x160"
            $merged = false;
            if ($index < count($items) - 1) {
                $nextItem = $items[$index + 1];
                $nextName = trim($nextItem['name'] ?? '');

                // Check if current item and next item should be merged
                $shouldMerge = false;

                // Pattern 1: "Door Frame Water" + "Tank 60X160"
                if (stripos($name, 'door frame water') !== false &&
                    stripos($nextName, 'tank') !== false &&
                    stripos($nextName, '60') !== false) {
                    $shouldMerge = true;
                }

                // Pattern 2: "X Banner Hitam" + "Standar 60x160"
                if (stripos($name, 'banner hitam') !== false &&
                    stripos($nextName, 'standar') !== false &&
                    stripos($nextName, '60') !== false) {
                    $shouldMerge = true;
                }

                // Pattern 3: Generic - if current name doesn't have numbers/model and next has
                if (! $shouldMerge &&
                    ! preg_match('/\d/', $name) &&
                    preg_match('/\d/', $nextName) &&
                    strlen($name) > 5 && strlen($nextName) > 3) {
                    // Check if they're related (both contain similar keywords)
                    $nameWords = explode(' ', strtolower($name));
                    $nextWords = explode(' ', strtolower($nextName));
                    $commonWords = array_intersect($nameWords, $nextWords);
                    if (count($commonWords) > 0 ||
                        (stripos($name, 'frame') !== false && stripos($nextName, 'tank') !== false) ||
                        (stripos($name, 'banner') !== false && stripos($nextName, 'standar') !== false)) {
                        $shouldMerge = true;
                    }
                }

                if ($shouldMerge && ! in_array($index + 1, $processed)) {
                    // Merge names
                    $name = trim($name.' '.$nextName);

                    // Use qty from first item, or sum if both have qty
                    $nextQty = max(1, (int) ($nextItem['qty'] ?? 1));
                    if ($qty == 1 && $nextQty > 1) {
                        $qty = $nextQty;
                    }

                    // Use price from item that has valid price (> 0)
                    $nextPrice = max(0, (int) ($nextItem['price'] ?? 0));
                    if ($price == 0 && $nextPrice > 0) {
                        $price = $nextPrice;
                    } elseif ($nextPrice > 0 && $price == 0) {
                        $price = $nextPrice;
                    } elseif ($price > 0 && $nextPrice > 0) {
                        // If both have prices, use the larger one (usually the correct one)
                        $price = max($price, $nextPrice);
                    }

                    // Use line_total from item that has valid total (> 0)
                    $nextLineTotal = max(0, (int) ($nextItem['line_total'] ?? ($nextPrice * $nextQty)));
                    if ($lineTotal == 0 && $nextLineTotal > 0) {
                        $lineTotal = $nextLineTotal;
                    } elseif ($nextLineTotal > 0 && $lineTotal == 0) {
                        $lineTotal = $nextLineTotal;
                    } elseif ($lineTotal > 0 && $nextLineTotal > 0) {
                        // If both have totals, use the larger one
                        $lineTotal = max($lineTotal, $nextLineTotal);
                    }

                    // Recalculate line_total if we have qty and price
                    if ($lineTotal == 0 && $qty > 0 && $price > 0) {
                        $lineTotal = $qty * $price;
                    }

                    $processed[] = $index + 1; // Mark next item as processed
                    $merged = true;
                }
            }

            // If price is still 0, try to extract from name or use line_total / qty
            if ($price == 0 && $lineTotal > 0 && $qty > 0) {
                $price = (int) ($lineTotal / $qty);
            }

            // If price is still 0 and we have OCR text, try to extract from text
            if ($price == 0 && $ocrText !== null) {
                $extractedPrice = $this->extractPriceFromOcrText($name, $ocrText);
                if ($extractedPrice > 0) {
                    $price = $extractedPrice;
                    if ($lineTotal == 0 && $qty > 0) {
                        $lineTotal = $qty * $price;
                    }
                }
            }

            // Final validation: skip if price and line_total are both 0
            if ($price == 0 && $lineTotal == 0) {
                continue;
            }

            // Only add items with reasonable prices
            if ($price > 0 && $price < 100000000) {
                $cleaned[] = [
                    'name' => $name,
                    'qty' => $qty,
                    'price' => $price,
                    'line_total' => $lineTotal > 0 ? $lineTotal : ($qty * $price),
                ];
            }

            $processed[] = $index;
        }

        return $cleaned;
    }

    /**
     * Extract price from OCR text based on product name
     * Looks for product name in text and finds the price in the same row/line
     */
    protected function extractPriceFromOcrText(string $productName, string $ocrText): int
    {
        $lines = explode("\n", $ocrText);
        $productWords = array_filter(explode(' ', strtolower($productName)));

        // Find line containing product name
        foreach ($lines as $lineIndex => $line) {
            $lineLower = strtolower($line);
            $matchedWords = 0;

            // Check if this line contains product name words
            foreach ($productWords as $word) {
                if (strlen($word) > 2 && stripos($lineLower, $word) !== false) {
                    $matchedWords++;
                }
            }

            // If at least 2 words match, this is likely the product line
            if ($matchedWords >= 2) {
                // Look for price in this line or next few lines
                for ($i = 0; $i <= 2; $i++) {
                    if ($lineIndex + $i >= count($lines)) {
                        break;
                    }

                    $searchLine = $lines[$lineIndex + $i];

                    // Look for price pattern: 150.000, 150000, Rp 150.000, etc.
                    if (preg_match('/(?:rp|idr)?\s*([\d]{1,3}(?:\.\d{3})*)/i', $searchLine, $matches)) {
                        $priceStr = str_replace('.', '', $matches[1]);
                        $price = (int) $priceStr;

                        // Validate: price should be reasonable (between 1000 and 100000000)
                        if ($price >= 1000 && $price <= 100000000) {
                            return $price;
                        }
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Extract fields (date, subtotal, total, discount) from OCR text
     */
    protected function extractFields(string $text, array $lines): array
    {
        $fields = [
            'document_type' => 'invoice_or_receipt',
            'date_raw' => null,
            'subtotal' => null,
            'discount_percent' => 0,
            'total' => null,
        ];

        $textLower = mb_strtolower($text);

        // Extract date (format: DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD)
        if (preg_match('/(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}|\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2})/', $text, $matches)) {
            $fields['date_raw'] = $matches[1];
        }

        // Extract total - support both comma and dot as thousands separator
        // PRIORITAS untuk struk retail (Indomaret, Alfamart):
        // 1. TOTAL BELANJA atau NON TUNAI (jumlah yang dibayar)
        // 2. TUNAI atau CASH
        // 3. HARGA JUAL (hanya jika tidak ada opsi di atas - HARGA JUAL sudah termasuk PPN)
        // NOTE: Handle newline between label and amount (e.g., "TOTAL BELANJA :\n137,900")

        // Prioritas 1: TOTAL BELANJA atau NON TUNAI
        $totalBelanjaPatterns = [
            // Handle newline between label and amount
            '/(?:total\s*belanja|non\s*tunai)\s*[:\-]?\s*(?:rp|idr)?\s*[\r\n\s]*([\d]{1,3}(?:[,\s\.]\d{3})*)/i',
            // Same line
            '/(?:total\s*belanja|non\s*tunai)\s*[:\-]?\s*(?:rp|idr)?\s*([\d]{1,3}(?:[,\s\.]\d{3})*)/i',
        ];

        $totalFound = false;
        foreach ($totalBelanjaPatterns as $pattern) {
            if (preg_match($pattern, $textLower, $matches)) {
                $total = preg_replace('/[,\s\.]/', '', $matches[1]);
                $totalInt = (int) $total;
                // Validate: total should be reasonable (at least 100)
                if ($totalInt >= 100) {
                    $fields['total'] = $totalInt;
                    Log::info('Total extracted from OCR text (TOTAL BELANJA/NON TUNAI)', [
                        'pattern' => $pattern,
                        'matched' => $matches[0],
                        'total' => $totalInt,
                    ]);
                    $totalFound = true;
                    break;
                }
            }
        }

        // Prioritas 2: TUNAI atau CASH (hanya jika tidak ada TOTAL BELANJA/NON TUNAI)
        if (! $totalFound) {
            $tunaiPatterns = [
                // Handle newline between label and amount
                '/(?:tunai|cash)\s*[:\-]?\s*(?:rp|idr)?\s*[\r\n\s]*([\d]{1,3}(?:[,\s\.]\d{3})*)/i',
                // Same line
                '/(?:tunai|cash)\s*[:\-]?\s*(?:rp|idr)?\s*([\d]{1,3}(?:[,\s\.]\d{3})*)/i',
            ];

            foreach ($tunaiPatterns as $pattern) {
                if (preg_match($pattern, $textLower, $matches)) {
                    $total = preg_replace('/[,\s\.]/', '', $matches[1]);
                    $totalInt = (int) $total;
                    // Validate: total should be reasonable (at least 100)
                    if ($totalInt >= 100) {
                        $fields['total'] = $totalInt;
                        Log::info('Total extracted from OCR text (TUNAI/CASH)', [
                            'pattern' => $pattern,
                            'matched' => $matches[0],
                            'total' => $totalInt,
                        ]);
                        $totalFound = true;
                        break;
                    }
                }
            }
        }

        // Prioritas 3: TOTAL (generic) - JANGAN gunakan HARGA JUAL jika ada TOTAL BELANJA/NON TUNAI/TUNAI/CASH
        if (! $totalFound) {
            $totalGenericPatterns = [
                // Handle newline between label and amount
                '/(?:total(?:\s*akhir)?|amount)\s*[:\-]?\s*(?:rp|idr)?\s*[\r\n\s]*([\d]{1,3}(?:[,\s\.]\d{3})*)/i',
                // Same line
                '/(?:total(?:\s*akhir)?|amount)\s*[:\-]?\s*(?:rp|idr)?\s*([\d]{1,3}(?:[,\s\.]\d{3})*)/i',
            ];

            foreach ($totalGenericPatterns as $pattern) {
                if (preg_match($pattern, $textLower, $matches)) {
                    $total = preg_replace('/[,\s\.]/', '', $matches[1]);
                    $totalInt = (int) $total;
                    // Validate: total should be reasonable (at least 100)
                    if ($totalInt >= 100) {
                        $fields['total'] = $totalInt;
                        Log::info('Total extracted from OCR text (TOTAL generic)', [
                            'pattern' => $pattern,
                            'matched' => $matches[0],
                            'total' => $totalInt,
                        ]);
                        $totalFound = true;
                        break;
                    }
                }
            }
        }

        // Prioritas 4: HARGA JUAL (hanya jika tidak ada yang di atas - HARGA JUAL sudah termasuk PPN)
        if (! $totalFound) {
            $hargaJualPatterns = [
                // Handle newline between label and amount
                '/(?:harga\s*jual)\s*[:\-]?\s*(?:rp|idr)?\s*[\r\n\s]*([\d]{1,3}(?:[,\s\.]\d{3})*)/i',
                // Same line
                '/(?:harga\s*jual)\s*[:\-]?\s*(?:rp|idr)?\s*([\d]{1,3}(?:[,\s\.]\d{3})*)/i',
            ];

            foreach ($hargaJualPatterns as $pattern) {
                if (preg_match($pattern, $textLower, $matches)) {
                    $total = preg_replace('/[,\s\.]/', '', $matches[1]);
                    $totalInt = (int) $total;
                    // Validate: total should be reasonable (at least 100)
                    if ($totalInt >= 100) {
                        $fields['total'] = $totalInt;
                        Log::info('Total extracted from OCR text (HARGA JUAL)', [
                            'pattern' => $pattern,
                            'matched' => $matches[0],
                            'total' => $totalInt,
                        ]);
                        break;
                    }
                }
            }
        }

        // Extract subtotal - support both comma and dot as thousands separator
        $subtotalPatterns = [
            '/(?:subtotal)\s*[:\-]?\s*(?:rp|idr)?\s*([\d]{1,3}(?:[,\s\.]\d{3})*)/i',
            '/subtotal\s*:\s*([\d,\.]+)/i',
        ];

        foreach ($subtotalPatterns as $pattern) {
            if (preg_match($pattern, $textLower, $matches)) {
                $subtotal = preg_replace('/[,\s\.]/', '', $matches[1]);
                $subtotalInt = (int) $subtotal;
                if ($subtotalInt >= 100) {
                    $fields['subtotal'] = $subtotalInt;
                    break;
                }
            }
        }

        // Extract discount
        if (preg_match('/(?:diskon|discount)\s*[:\-]?\s*(\d{1,3})\s*%?/i', $textLower, $matches)) {
            $fields['discount_percent'] = (int) $matches[1];
        }

        return $fields;
    }

    /**
     * Extract items (products) from OCR lines
     * Returns array of items with name, qty, price, line_total
     *
     * @param  array  $lines  OCR lines
     * @param  string|null  $text  Full OCR text for format detection
     */
    protected function extractItems(array $lines, ?string $text = null): array
    {
        $items = [];
        $currentProduct = '';
        $productLines = [];

        // Address patterns to skip
        $addressPatterns = [
            '/^J\.|Jl\.|Jalan/i',
            '/Kec\.|Kecamatan/i',
            '/Kota|Kabupaten/i',
            '/Sulawesi|Selatan|Utara|Tengah|Timur|Barat/i',
            '/\d{5}/', // ZIP code
            '/Telp:|Telepon/i',
            '/Email:/i',
            '/Up\s*:/i',
            '/Ekspedisi:/i',
            '/Resi:/i',
            '/^J\.\s+[A-Z]/', // J. Banta, J. Toa, etc.
        ];

        $inProductSection = false;

        foreach ($lines as $idx => $line) {
            $line = trim($line);
            if (empty($line)) {
                if (! empty($currentProduct)) {
                    $productLines[] = trim($currentProduct);
                    $currentProduct = '';
                }
                $inProductSection = false;

                continue;
            }

            // Detect product section header
            if (preg_match('/^(No\.|Produk|QTY|Satuan|Harga|Diskon|Jumlah|Item)/i', $line)) {
                $inProductSection = true;

                continue;
            }

            // Skip common headers and metadata
            if (preg_match('/^(INVOICE|AZMEDIA|AZA MEDIA|Nomor|Tanggal|Surat Jalan|Pemesanan|Tgl\.|Jatuh|Tempo|Term|Cash|Delivery|Tagihan|Kepada|Dokter|Printing|Diperiksa|Diterima|Pengirim|Dengan|Hormat)/i', $line)) {
                if (! empty($currentProduct)) {
                    $productLines[] = trim($currentProduct);
                    $currentProduct = '';
                }

                continue;
            }

            // Skip invoice numbers, dates
            if (preg_match('/^(INV-|SJ-|SO-|\d{2}\/\d{2}\/\d{4})/i', $line)) {
                continue;
            }

            // Skip addresses
            $isAddress = false;
            foreach ($addressPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $isAddress = true;
                    break;
                }
            }

            if ($isAddress || preg_match('/,\s*(Kec\.|Kota|Sulawesi)/i', $line)) {
                if (! empty($currentProduct)) {
                    $productLines[] = trim($currentProduct);
                    $currentProduct = '';
                }

                continue;
            }

            // Skip totals and summary lines
            if (preg_match('/^(Total|Subtotal|Sisa Tagihan|Rp\s*\d+\.?\d*|Harga Jual|PPN|Kembali|Non Tunai|Tunai|Cash)/i', $line)) {
                if (! empty($currentProduct)) {
                    $productLines[] = trim($currentProduct);
                    $currentProduct = '';
                }

                continue;
            }

            // Skip phone numbers
            if (preg_match('/.*\d{3,}.*\d{3,}.*/', $line)) {
                continue;
            }

            // Skip coupon lines
            if (stripos($line, 'kupon') !== false || stripos($line, 'coupon') !== false) {
                continue;
            }

            // Skip single numbers (likely QTY or No.)
            if (preg_match('/^\d+$/', $line) && strlen($line) <= 2) {
                continue;
            }

            // Skip single characters or very short lines
            if (strlen($line) <= 3) {
                continue;
            }

            // Detect Indomaret format: Product name followed by qty and price on next lines
            $isIndomaretFormat = false;
            if (stripos($text, 'indomaret') !== false || stripos($text, 'indomart') !== false ||
                stripos($text, 'CV DAMAI') !== false || preg_match('/IDM\s+KTG|SAMPOERNA|SDP\s+MI|TIC\s+TAC|JULIES/i', $text)) {
                $isIndomaretFormat = true;
            }

            // Special handling for Indomaret receipts
            if ($isIndomaretFormat) {
                // Check if line looks like an Indomaret product
                if (preg_match('/^(IDM|SAMPOERNA|SDP|TIC\s+TAC|JULIES|MARIE|INDOMIE|AQUA|CLEO|ULTRA|FRISIAN|GOOD|DAY|KAPAL|API|GUDANG|GARAM|BIMOLI|LUX|REXONA|CLOSE\s+UP|PEPSODENT|LIFEBOUY|SARI\s+WANGI|TEH\s+PUCUK|TEH\s+BOTOL|FANTA|SPRITE|COCA\s+COLA|PEPES|SARDEN|ABON|SIRUP|MINYAK|SAUS|KECAP|BUBUR|SNACK|KUE|ROT|SUSU|YOGHURT|SABUN|SHAMPOO|CONDITIONER|DEO|PARFUME|TISUE|PLASTIK|KANTONG|KRESEK|KARDUS|KERTAS|PULPEN|PENSIL|BUKU|MAJALAH|KORAN|ROKOK|CIGARETTE|TEMBAKAU)/i', $line)) {
                    // This is an Indomaret product line
                    // Look for qty and price in next 2-3 lines
                    $qty = 1;
                    $price = 0;
                    $lineTotal = 0;

                    // Check next lines for qty, price, and line total
                    for ($i = 1; $i <= 5; $i++) {
                        $checkLine = isset($lines[$idx + $i]) ? trim($lines[$idx + $i]) : '';
                        if (empty($checkLine)) {
                            continue;
                        }

                        // Check for qty (usually just a number 1-99)
                        if (preg_match('/^\d{1,2}$/', $checkLine) && $qty == 1) {
                            $qty = (int) $checkLine;

                            continue;
                        }

                        // Check for price (number with or without thousands separator)
                        if (preg_match('/^(\d{1,3}(?:[,\s\.]\d{3})*)$/', $checkLine, $priceMatches)) {
                            $priceStr = preg_replace('/[,\s\.]/', '', $priceMatches[1]);
                            $price = (int) $priceStr;
                            if ($price > 0 && $lineTotal == 0) {
                                $lineTotal = $qty * $price;

                                continue;
                            }
                        }

                        // Check for line total (larger number, usually after price)
                        if (preg_match('/^(\d{1,3}(?:[,\s\.]\d{3})*)$/', $checkLine, $totalMatches)) {
                            $totalStr = preg_replace('/[,\s\.]/', '', $totalMatches[1]);
                            $potentialTotal = (int) $totalStr;
                            if ($potentialTotal > $price && $potentialTotal > 1000) {
                                $lineTotal = $potentialTotal;
                                if ($price == 0 && $qty > 0) {
                                    $price = (int) ($lineTotal / $qty);
                                }
                                break;
                            }
                        }
                    }

                    // Only add if we found a valid price or line total
                    if (($price > 0 || $lineTotal > 0) && $price < 100000000) {
                        $items[] = [
                            'name' => $line,
                            'qty' => $qty,
                            'price' => $price,
                            'line_total' => $lineTotal > 0 ? $lineTotal : ($qty * $price),
                        ];

                        continue;
                    }
                }
            }

            // Look for product names - must start with capital letter
            if (preg_match('/^[A-Z]/', $line)) {
                $cleanLine = preg_replace('/\s+/', ' ', $line);

                // Skip lines that look like addresses, phone numbers, or transaction IDs
                if (preg_match('/^[A-Z\s]+,\s*\d+/', $cleanLine) || // Address pattern
                    preg_match('/.*\d{5,}.*/', $cleanLine) || // Long number sequences
                    preg_match('/^\d{2}[\/\-\.]\d{2}[\/\-\.]\d{2}/', $cleanLine) || // Date pattern
                    stripos($cleanLine, 'npwp') !== false) {
                    continue;
                }

                // Check if it's a product-related line
                $isProductLine = preg_match('/\b(Door|Frame|Water|Tank|Banner|Hitam|Standar|X\s+Banner|\d+[Xx]\d+)\b/i', $cleanLine);

                // For Indomaret: detect product codes/names (IDM, SAMPOERNA, SDP, TIC TAC, JULIES, etc.)
                if ($isIndomaretFormat) {
                    $isIndomaretProduct = preg_match('/^(IDM|SAMPOERNA|SDP|TIC\s+TAC|JULIES|MARIE|INDOMIE|AQUA|CLEO|ULTRA|FRISIAN|GOOD|DAY|KAPAL|API|GUDANG|GARAM)/i', $cleanLine);
                    if ($isIndomaretProduct) {
                        // This is an Indomaret product line
                        // Look for qty and price in next 2-3 lines
                        $qty = 1;
                        $price = 0;
                        $lineTotal = 0;

                        for ($i = 1; $i <= 3; $i++) {
                            $checkLine = isset($lines[$idx + $i]) ? trim($lines[$idx + $i]) : '';
                            if (empty($checkLine)) {
                                continue;
                            }

                            // Check for qty (usually just a number 1-9)
                            if (preg_match('/^\d{1,2}$/', $checkLine) && $qty == 1) {
                                $qty = (int) $checkLine;

                                continue;
                            }

                            // Check for price (number with or without thousands separator)
                            if (preg_match('/^(\d{1,3}(?:[,\s\.]\d{3})*)$/', $checkLine, $priceMatches)) {
                                $priceStr = preg_replace('/[,\s\.]/', '', $priceMatches[1]);
                                $price = (int) $priceStr;
                                if ($price > 0 && $price < 100000000) {
                                    $lineTotal = $qty * $price;
                                    break;
                                }
                            }

                            // Check for line total (larger number, usually after price)
                            if (preg_match('/^(\d{1,3}(?:[,\s\.]\d{3})*)$/', $checkLine, $totalMatches)) {
                                $totalStr = preg_replace('/[,\s\.]/', '', $totalMatches[1]);
                                $potentialTotal = (int) $totalStr;
                                if ($potentialTotal > $price && $potentialTotal > 1000 && $potentialTotal < 100000000) {
                                    $lineTotal = $potentialTotal;
                                    if ($price == 0 && $qty > 0) {
                                        $price = (int) ($lineTotal / $qty);
                                    }
                                    break;
                                }
                            }
                        }

                        // Only add if we found a valid price
                        if ($price > 0 && $price < 100000000) {
                            $items[] = [
                                'name' => $cleanLine,
                                'qty' => $qty,
                                'price' => $price,
                                'line_total' => $lineTotal > 0 ? $lineTotal : ($qty * $price),
                            ];
                            // Skip the next few lines that we already processed
                            for ($i = 1; $i <= 3; $i++) {
                                if (isset($lines[$idx + $i])) {
                                    $checkLine = trim($lines[$idx + $i]);
                                    if (preg_match('/^\d+$/', $checkLine) || preg_match('/^\d+[,\s\.]\d+/', $checkLine)) {
                                        // Mark as processed by setting to empty (will be skipped)
                                        $lines[$idx + $i] = '';
                                    }
                                }
                            }

                            continue;
                        }
                    }
                }

                if ($isProductLine) {
                    $nextLine = isset($lines[$idx + 1]) ? trim($lines[$idx + 1]) : '';
                    $nextNextLine = isset($lines[$idx + 2]) ? trim($lines[$idx + 2]) : '';

                    // Check if current line is a continuation (Tank, Banner, Standar, model numbers)
                    // Continuation: starts with Tank/Banner/Standar/model number, or contains them and is short
                    $isContinuation = preg_match('/^(Tank|Banner|Standar|\d+[Xx]\d+)/i', $cleanLine) ||
                                     (preg_match('/\b(Tank|Banner|Standar|\d+[Xx]\d+)\b/i', $cleanLine) &&
                                      strlen($cleanLine) < 30 &&
                                      ! preg_match('/\b(Door|Frame|Water|X\s+Banner|Hitam)\b/i', $cleanLine));

                    // Check if current line is a product starter (Door, X Banner, Frame Water)
                    // Product starter: starts with Door/X Banner, or contains Door/Frame/Water/Banner/Hitam but NOT Tank/Standar/model numbers
                    $isProductStarter = preg_match('/^(Door|X\s+Banner|Frame)/i', $cleanLine) ||
                                       (preg_match('/\b(Door|Frame|Water|Banner|Hitam)\b/i', $cleanLine) &&
                                        ! preg_match('/\b(Tank|Standar|\d+[Xx]\d+)\b/i', $cleanLine) &&
                                        ! preg_match('/^(Tank|Banner|Standar|\d+[Xx]\d+)/i', $cleanLine));

                    // Check if line is complete (has model number)
                    $isComplete = preg_match('/\d+[Xx]\d+/i', $cleanLine);

                    if (! empty($currentProduct)) {
                        // We have a product being built
                        if ($isContinuation) {
                            // Always merge continuation with current product
                            $currentProduct .= ' '.$cleanLine;
                        } elseif ($isProductStarter) {
                            // New product starter, save previous product
                            $productLines[] = trim($currentProduct);
                            $currentProduct = $cleanLine;
                        } else {
                            // Check if this should be merged or is a new product
                            // If next line is continuation, this might be part of current product
                            $nextIsContinuation = ! empty($nextLine) && preg_match('/\b(Tank|Banner|Standar|\d+[Xx]\d+)\b/i', $nextLine);
                            if ($nextIsContinuation && ! $isComplete) {
                                // Next is continuation, so this is part of current product
                                $currentProduct .= ' '.$cleanLine;
                            } else {
                                // New product
                                $productLines[] = trim($currentProduct);
                                $currentProduct = $cleanLine;
                            }
                        }
                    } else {
                        // Start new product
                        if ($isContinuation) {
                            // Orphaned continuation, skip or save if complete
                            if (preg_match('/\b(Tank|Banner|Standar)\s+\d+[Xx]\d+/i', $cleanLine)) {
                                $productLines[] = $cleanLine;
                            }
                        } else {
                            // Product starter or complete product
                            // If not complete, wait for continuation
                            if ($isProductStarter && ! $isComplete) {
                                // Check if next line is continuation
                                $nextIsContinuation = ! empty($nextLine) && preg_match('/\b(Tank|Banner|Standar|\d+[Xx]\d+)\b/i', $nextLine);
                                if ($nextIsContinuation) {
                                    $currentProduct = $cleanLine;
                                } else {
                                    // Check next 2 lines
                                    $hasContinuation = false;
                                    for ($i = 1; $i <= 2; $i++) {
                                        $checkLine = isset($lines[$idx + $i]) ? trim($lines[$idx + $i]) : '';
                                        if (! empty($checkLine) && preg_match('/\b(Tank|Banner|Standar|\d+[Xx]\d+)\b/i', $checkLine)) {
                                            $hasContinuation = true;
                                            break;
                                        }
                                    }
                                    if ($hasContinuation) {
                                        $currentProduct = $cleanLine;
                                    } else {
                                        $productLines[] = $cleanLine;
                                    }
                                }
                            } else {
                                $currentProduct = $cleanLine;
                            }
                        }
                    }
                } elseif (! empty($currentProduct)) {
                    // Current line doesn't look like product, check if we should save previous
                    $nextLine = isset($lines[$idx + 1]) ? trim($lines[$idx + 1]) : '';
                    if (empty($nextLine) ||
                        preg_match('/^\d+$/', $nextLine) ||
                        preg_match('/^\d+[.,]\d+/', $nextLine) ||
                        preg_match('/^(Pcs|Satuan|Harga|Diskon|Jumlah|Total|Tagihan|Kepada)/i', $nextLine) ||
                        preg_match('/^(J\.|Jl\.|Kec\.|Kota|Sulawesi)/i', $nextLine)) {
                        $productLines[] = trim($currentProduct);
                        $currentProduct = '';
                    }
                }
            } elseif (! empty($currentProduct) && preg_match('/^[A-Za-z0-9]/', $line)) {
                // Continuation of product name
                $cleanLine = preg_replace('/\s+/', ' ', $line);
                $isContinuation = preg_match('/^(Tank|Banner|Standar|\d+[Xx]\d+)/i', $cleanLine);

                if ($isContinuation) {
                    // Always merge continuation
                    $currentProduct .= ' '.$cleanLine;
                } else {
                    // Might be new product or continuation
                    $nextLine = isset($lines[$idx + 1]) ? trim($lines[$idx + 1]) : '';
                    $nextIsQtyPrice = ! empty($nextLine) && (
                        preg_match('/^\d+$/', $nextLine) ||
                        preg_match('/^\d+[.,]\d+/', $nextLine) ||
                        preg_match('/^(Pcs|Satuan|Harga|Diskon|Jumlah|Total)/i', $nextLine)
                    );

                    if ($nextIsQtyPrice) {
                        $currentProduct .= ' '.$cleanLine;
                    } else {
                        $productLines[] = trim($currentProduct);
                        if (preg_match('/\b(Water|Tank|Banner|Frame|Hitam|Standar|Door|X\s+Banner|\d+[Xx]\d+)\b/i', $cleanLine)) {
                            $currentProduct = $cleanLine;
                        } else {
                            $currentProduct = '';
                        }
                    }
                }
            } elseif (! empty($currentProduct)) {
                // Non-letter line, might be end of product
                $nextLine = isset($lines[$idx + 1]) ? trim($lines[$idx + 1]) : '';
                if (preg_match('/^\d+$/', $nextLine) || preg_match('/^\d+[.,]\d+/', $nextLine)) {
                    $productLines[] = trim($currentProduct);
                    $currentProduct = '';
                }
            }
        }

        // Save last product if exists
        if (! empty($currentProduct)) {
            $productLines[] = trim($currentProduct);
        }

        // Process product lines to extract items with qty, price, line_total
        // Also look for price info in next lines
        foreach ($productLines as $idx => $line) {
            // Clean product name first
            $name = $this->cleanProductName($line);

            if (strlen($name) < 5) {
                continue;
            }

            // Try to extract qty, price, line_total from the line
            $qty = 1;
            $price = 0;
            $lineTotal = 0;

            // Pattern 1: "Product Name qty price line_total"
            // Example: "Door Frame Water Tank 60X160 1 150000 150000"
            if (preg_match('/^(.+?)\s+(\d+)\s+([\d\.]{3,})\s+([\d\.]{3,})$/i', $line, $matches)) {
                $qty = (int) $matches[2];
                $price = (int) preg_replace('/[\.]/', '', $matches[3]);
                $lineTotal = (int) preg_replace('/[\.]/', '', $matches[4]);
                $name = $this->cleanProductName($matches[1]);
            }
            // Pattern 2: "Product Name price line_total" (no qty)
            elseif (preg_match('/^(.+?)\s+([\d\.]{3,})\s+([\d\.]{3,})$/i', $line, $matches)) {
                $price = (int) preg_replace('/[\.]/', '', $matches[2]);
                $lineTotal = (int) preg_replace('/[\.]/', '', $matches[3]);
                $name = $this->cleanProductName($matches[1]);
            }
            // Pattern 3: "Product Name price" (single price)
            elseif (preg_match('/^(.+?)\s+([\d\.]{3,})$/i', $line, $matches)) {
                $price = (int) preg_replace('/[\.]/', '', $matches[2]);
                $lineTotal = $price; // Assume qty = 1
                $name = $this->cleanProductName($matches[1]);
            }
            // Pattern 4: Look for price in next lines (in original lines array)
            else {
                // Find the line index in original lines array
                $lineIndex = array_search($line, $lines);
                if ($lineIndex !== false) {
                    // Check next 3 lines for price
                    for ($i = 1; $i <= 3; $i++) {
                        if (isset($lines[$lineIndex + $i])) {
                            $checkLine = trim($lines[$lineIndex + $i]);
                            // Skip if it's another product
                            if (preg_match('/\b(Door|Frame|Water|Tank|Banner|Hitam|Standar|X\s+Banner)\b/i', $checkLine) &&
                                ! preg_match('/^\d+[.,]\d+/', $checkLine)) {
                                break;
                            }
                            // Check for price pattern: "qty price line_total" or "price line_total" or "price"
                            if (preg_match('/^(\d+)\s+([\d\.]{3,})\s+([\d\.]{3,})$/i', $checkLine, $matches)) {
                                $qty = (int) $matches[1];
                                $price = (int) preg_replace('/[\.]/', '', $matches[2]);
                                $lineTotal = (int) preg_replace('/[\.]/', '', $matches[3]);
                                break;
                            } elseif (preg_match('/^([\d\.]{3,})\s+([\d\.]{3,})$/i', $checkLine, $matches)) {
                                $price = (int) preg_replace('/[\.]/', '', $matches[1]);
                                $lineTotal = (int) preg_replace('/[\.]/', '', $matches[2]);
                                break;
                            } elseif (preg_match('/^([\d\.]{3,})$/i', $checkLine, $matches)) {
                                $price = (int) preg_replace('/[\.]/', '', $matches[1]);
                                $lineTotal = $price;
                                break;
                            }
                        }
                    }
                }
            }

            // Validate product name and price
            if (preg_match('/\b(Water|Tank|Banner|Frame|Hitam|Standar|Door|X\s+Banner|\d+[Xx]\d+)\b/i', $name) &&
                $price > 0 && $price < 100000000) {
                $items[] = [
                    'name' => $name,
                    'qty' => $qty,
                    'price' => $price,
                    'line_total' => $lineTotal > 0 ? $lineTotal : ($price * $qty),
                ];
            }
        }

        Log::info('Rule-based extraction completed', [
            'product_lines_count' => count($productLines),
            'items_extracted' => count($items),
            'items_preview' => array_slice($items, 0, 3),
        ]);

        return $items;
    }

    /**
     * Clean product name (remove price, qty, etc.)
     */
    protected function cleanProductName(string $name): string
    {
        // Extract model number first (before cleaning)
        $modelNumber = null;
        if (preg_match('/(\d+[Xx]\d+)/i', $name, $matches)) {
            $modelNumber = $matches[1];
        }

        // Remove price patterns (numbers with dots/commas at the end)
        $productName = preg_replace('/\s+\d+[.,]\d{3,}(\s*$|$)/', '', $name);

        // Remove QTY patterns
        $productName = preg_replace('/\s+\d+\s*(Pcs|pcs|PCS|Unit|unit)$/i', '', $productName);

        // Remove percentage
        $productName = preg_replace('/\s+\d+%$/', '', $productName);

        // Remove standalone numbers at the end (but keep model numbers)
        if ($modelNumber && ! preg_match('/\d+[Xx]\d+/i', $productName)) {
            $productName .= ' '.$modelNumber;
        } else {
            $productName = preg_replace('/\s+(\d+)$/', '', $productName);
            if ($modelNumber && ! preg_match('/\d+[Xx]\d+/i', $productName)) {
                $productName .= ' '.$modelNumber;
            }
        }

        // Clean up multiple spaces
        $productName = preg_replace('/\s+/', ' ', $productName);

        return trim($productName);
    }

    /**
     * Send notification that OCR processing failed or no text extracted
     */
    protected function sendOcrFailedNotification($message, ?string $errorMessage = null): void
    {
        try {
            $channel = Channel::find($message->channel_id);
            if (! $channel) {
                return;
            }

            $config = $channel->config ?? [];
            $sessionId = $config['session_id'] ?? null;

            if (! $sessionId) {
                return;
            }

            // Extract sender phone number
            $senderId = $message->sender_id ?? '';
            $phoneNumber = str_replace(['@c.us', '@g.us'], '', $senderId);

            if (empty($phoneNumber)) {
                return;
            }

            $reply = "❌ *Gagal memproses gambar*\n\n";
            $reply .= "Maaf, tidak dapat membaca teks dari gambar yang dikirim.\n";
            if ($errorMessage) {
                $reply .= "\nError: {$errorMessage}";
            }
            $reply .= "\n\nSilakan coba kirim gambar yang lebih jelas atau coba lagi.";

            $whatsappService = new WhatsAppService;
            $whatsappService->sendMessage($sessionId, $phoneNumber, $reply);

        } catch (\Exception $e) {
            Log::error('Failed to send OCR failed notification', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
