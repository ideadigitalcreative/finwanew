<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIProcessorService
{
    protected $apiUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.ai_processor.url', 'http://localhost:8001');
        $this->apiKey = config('services.ai_processor.api_key', 'ai_processor_api_key_123');
    }

    /**
     * Extract transaction from text message
     */
    public function extractTransaction(int $tenantId, int $messageId, string $messageText): array
    {
        try {
            Log::info('Calling AI Processor to extract transaction', [
                'message_id' => $messageId,
                'tenant_id' => $tenantId,
                'text_length' => strlen($messageText),
                'text_preview' => mb_substr($messageText, 0, 200)
            ]);
            
            $response = Http::timeout(120) // Increase timeout to 120 seconds for LLM processing
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/extract-transaction", [
                    'tenant_id' => $tenantId,
                    'message_id' => $messageId,
                    'message_text' => $messageText,
                    'message_type' => 'text'
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Log AI processor response for debugging
                Log::info('AI Processor response received', [
                    'message_id' => $messageId,
                    'has_extracted_transactions' => isset($responseData['extracted_transactions']),
                    'transaction_count' => isset($responseData['extracted_transactions']) ? count($responseData['extracted_transactions']) : 0,
                    'needs_review' => $responseData['needs_review'] ?? false,
                    'response_keys' => array_keys($responseData),
                    'first_transaction_amount' => isset($responseData['extracted_transactions'][0]['amount']) ? $responseData['extracted_transactions'][0]['amount'] : null
                ]);
                
                return [
                    'success' => true,
                    'data' => $responseData
                ];
            }

            $errorBody = $response->body();
            Log::error('AI Processor API error', [
                'message_id' => $messageId,
                'status' => $response->status(),
                'error' => $errorBody
            ]);

            return [
                'success' => false,
                'error' => $errorBody ?: "AI Processor returned status {$response->status()}"
            ];
        } catch (\Exception $e) {
            Log::error('Error calling AI processor', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Classify message intent (transaction or query)
     */
    public function classifyIntent(string $messageText): array
    {
        // First, use fallback for quick detection (more reliable for transaction patterns)
        $fallbackResult = $this->classifyIntentFallback($messageText);
        
        // Check for strong query keywords in fallback (prioritas tinggi)
        $text = strtolower(trim($messageText));
        $hasStrongQueryKeyword = preg_match('/\b(daftar|list|sebutkan|tunjukkan|tampilkan|apa saja|berapa|saldo|ringkasan)\b/i', $text);
        $hasAmount = preg_match('/\d+/', $text);
        $hasTransactionActionKeyword = preg_match('/\b(beli|bayar|terima|gaji|bonus|belanja|makan|bensin|transport)\b/i', $text);
        
        // If fallback detects strong query keyword, use it directly (prioritas tinggi)
        if ($hasStrongQueryKeyword && $fallbackResult['data']['intent'] === 'query') {
            Log::info('Using fallback classification (strong query keyword detected)', [
                'message_text' => $messageText,
                'intent' => $fallbackResult['data']['intent']
            ]);
            return $fallbackResult;
        }
        
        // If fallback detects transaction with high confidence (has amount + transaction action), use it
        if ($fallbackResult['data']['intent'] === 'transaction' && $hasAmount && $hasTransactionActionKeyword) {
            // High confidence transaction - use fallback directly
            Log::info('Using fallback classification (high confidence transaction)', [
                'message_text' => $messageText,
                'intent' => $fallbackResult['data']['intent']
            ]);
            return $fallbackResult;
        }
        
        // For other cases, try AI Processor first, then fallback
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/classify-intent", [
                    'message_text' => $messageText
                ]);

            if ($response->successful()) {
                $aiResult = $response->json();
                $aiIntent = $aiResult['intent'] ?? 'query';
                $aiConfidence = $aiResult['confidence'] ?? 0.7;
                
                // If AI returns irrelevant, use it
                if ($aiIntent === 'irrelevant') {
                    return [
                        'success' => true,
                        'data' => $aiResult
                    ];
                }
                
                // If AI says query with high confidence, prefer AI (especially for "daftar belanjaan")
                if ($aiIntent === 'query' && $aiConfidence >= 0.85) {
                    Log::info('Using AI classification (high confidence query)', [
                        'message_text' => $messageText,
                        'ai_intent' => $aiIntent,
                        'ai_confidence' => $aiConfidence,
                        'fallback_intent' => $fallbackResult['data']['intent']
                    ]);
                    return [
                        'success' => true,
                        'data' => $aiResult
                    ];
                }
                
                // If AI says query but fallback says transaction with amount + action, prefer fallback
                if ($aiIntent === 'query' && $fallbackResult['data']['intent'] === 'transaction' && $hasAmount && $hasTransactionActionKeyword) {
                    Log::info('Overriding AI classification (fallback more confident for transaction)', [
                        'message_text' => $messageText,
                        'ai_intent' => $aiIntent,
                        'fallback_intent' => $fallbackResult['data']['intent']
                    ]);
                    return $fallbackResult;
                }
                
                // Default: use AI result
                return [
                    'success' => true,
                    'data' => $aiResult
                ];
            }

            // Fallback: simple keyword-based classification
            return $fallbackResult;
        } catch (\Exception $e) {
            Log::warning('Error classifying intent, using fallback', [
                'error' => $e->getMessage()
            ]);
            
            return $fallbackResult;
        }
    }
    
    /**
     * Fallback classification using keywords
     */
    protected function classifyIntentFallback(string $messageText): array
    {
        $text = strtolower(trim($messageText));
        
        // Strong query keywords (prioritas tinggi - jika ada, langsung query)
        $strongQueryKeywords = [
            'daftar', 'list', 'sebutkan', 'tunjukkan', 'tampilkan', 'apa saja',
            'berapa', 'saldo', 'ringkasan', 'laporan', 'total', 'jumlah',
            'riwayat', 'history', 'histori', 'cari', 'lihat', 'cek', 'check',
            'info', 'informasi', 'mana', 'dimana', 'kapan', 'siapa', 'bagaimana', 
            'kenapa', 'mengapa'
        ];
        
        // Query context keywords (kata yang menunjukkan query tentang data)
        $queryContextKeywords = [
            'belanjaan', // "belanjaan" = items yang dibeli (query), bukan perintah "belanja" (transaction)
            'pengeluaran', // dalam konteks query: "berapa pengeluaran", "daftar pengeluaran"
            'pemasukan', // dalam konteks query: "berapa pemasukan", "daftar pemasukan"
            'transaksi', // dalam konteks query: "daftar transaksi"
            'bulan ini', 'minggu ini', 'hari ini', 'kemarin', 'lalu'
        ];
        
        // Transaction action keywords (verb - perintah untuk melakukan transaksi, including informal/slang)
        $transactionActionKeywords = [
            // Aksi utama
            'beli', 'bayar', 'terima', 'gaji', 'bonus', 'transfer', 'tf', 'trf', 'tunai', 
            'debit', 'kredit', 'pembayaran', 'pembelian', 
            'uang masuk', 'uang keluar', 'belanja', 'borong', 'checkout', 'order', 'pesen', 'pesan',
            
            // Makanan - formal & informal
            'makan', 'mkn', 'maem', 'mamam', 'makan pagi', 'makan siang', 'makan malam', 
            'sarapan', 'breakfast', 'lunch', 'dinner', 'nyemil', 'ngemil',
            'jajan', 'snack', 'cemilan', 'ngopi', 'kopi', 'coffee', 'minum',
            
            // Jenis makanan & restoran
            'chicken', 'ayam', 'geprek', 'nasi', 'mie', 'indomie', 'bakso', 'sate', 'pizza', 'burger',
            'soto', 'rawon', 'rendang', 'gudeg', 'pecel', 'gado', 'martabak', 'gorengan',
            'siomay', 'batagor', 'pempek', 'cilok', 'cireng', 'nasgor', 'nasgep',
            'warteg', 'warung', 'resto', 'cafe', 'kantin', 'foodcourt',
            'mcd', 'mcdonalds', 'kfc', 'hokben', 'yoshinoya', 'solaria', 'pizza hut', 'phd', 'dominos',
            'starbucks', 'sbux', 'janji jiwa', 'kopi kenangan', 'fore', 'tomoro',
            'mixue', 'chatime', 'xiboba', 'boba', 'jco', 'dunkin',
            
            // Transport
            'bensin', 'pertamax', 'pertalite', 'solar', 'isi bensin', 'ngisi bensin',
            'parkir', 'transport', 'ongkos', 'ongkir', 'kirim', 'pengiriman',
            'grab', 'gojek', 'ojol', 'ojek', 'maxim', 'indriver',
            'taxi', 'taksi', 'bluebird', 'angkot', 'busway', 'transjakarta', 'mrt', 'lrt', 'krl', 'kereta',
            'toll', 'tol', 'etoll', 'tiket', 'pesawat', 'bus', 'travel',
            
            // Belanja online
            'shopee', 'tokped', 'tokopedia', 'lazada', 'bukalapak', 'blibli', 'olshop',
            'alfamart', 'indomaret', 'alfamidi', 'superindo', 'hypermart',
            
            // Tagihan & Pulsa
            'listrik', 'pln', 'air', 'pdam', 'internet', 'wifi', 'indihome', 'biznet',
            'pulsa', 'kuota', 'paket data', 'top up', 'topup', 'isi pulsa', 'isi kuota',
            'bpjs', 'asuransi', 'pajak', 'pbb', 'stnk', 'sim',
            
            // Hiburan
            'nonton', 'bioskop', 'cinema', 'xxi', 'cgv', 'cinepolis',
            'netflix', 'spotify', 'youtube', 'disney', 'vidio', 'viu',
            'game', 'steam', 'playstation', 'mobile legend', 'ml', 'ff', 'pubg', 'valorant',
            
            // Hunian & Cicilan
            'sewa', 'kos', 'kost', 'kontrakan', 'kontrak', 'ngontrak', 'cicilan', 'angsuran',
            
            // Sosial & Keluarga
            'ngasih', 'kasih', 'kirimin', 'kirim ke', 'transfer ke', 'ortu', 'orang tua',
            'sedekah', 'infaq', 'infak', 'zakat', 'sumbangan', 'donasi', 'amal',
            
            // Kesehatan
            'obat', 'apotek', 'dokter', 'rumah sakit', 'rs', 'puskesmas', 'klinik',
            
            // Kecantikan
            'salon', 'barbershop', 'potong rambut', 'cukur', 'facial', 'spa', 'pijat',
            'skincare', 'makeup', 'kosmetik', 'parfum',
            
            // Pendidikan
            'spp', 'uang sekolah', 'uang kuliah', 'buku', 'atk', 'kursus', 'les', 'bimbel',
            
            // Keyword informal umum
            'abis', 'habis', 'keluar', 'kluar', 'spending', 'spent'
        ];
        
        // Check if contains number (amount) - key indicator for transaction
        $hasAmount = preg_match('/\d+/', $text);
        $hasAmountWithUnit = preg_match('/\d+\s*(rb|ribu|juta|k|ratusan|ribuan)/i', $text);
        
        // Check for strong query keywords first (prioritas tertinggi)
        $hasStrongQueryKeyword = false;
        foreach ($strongQueryKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $hasStrongQueryKeyword = true;
                break;
            }
        }
        
        // Check for query context keywords
        $hasQueryContext = false;
        foreach ($queryContextKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $hasQueryContext = true;
                break;
            }
        }
        
        // Check for transaction action keywords
        $hasTransactionAction = false;
        foreach ($transactionActionKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $hasTransactionAction = true;
                break;
            }
        }
        
        // Special case: "belanjaan" vs "belanja"
        // "belanjaan" = noun (query), "belanja" = verb (transaction)
        $hasBelanjaan = str_contains($text, 'belanjaan'); // Query
        $hasBelanja = str_contains($text, 'belanja') && !$hasBelanjaan; // Transaction (jika tidak ada "belanjaan")
        
        // Check for question patterns
        $questionPatterns = ['?', 'berapa', 'mana', 'dimana', 'kapan', 'siapa', 'bagaimana', 'kenapa', 'mengapa'];
        $hasQuestionPattern = false;
        foreach ($questionPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                $hasQuestionPattern = true;
                break;
            }
        }
        
        // Decision logic dengan prioritas yang lebih jelas
        // PRIORITY 1: Strong query keywords = selalu query (KECUALI ada transaction action + amount)
        if ($hasStrongQueryKeyword && !($hasTransactionAction && $hasAmount)) {
            $intent = 'query';
        }
        // PRIORITY 2: Transaction action + amount = transaction (PRIORITAS TINGGI!)
        elseif ($hasTransactionAction && $hasAmount) {
            $intent = 'transaction';
        }
        // PRIORITY 3: Query context + no amount = query
        elseif ($hasQueryContext && !$hasAmount) {
            $intent = 'query';
        }
        // PRIORITY 4: "belanjaan" (noun) = query, "belanja" (verb) = transaction
        elseif ($hasBelanjaan) {
            $intent = 'query';
        }
        // PRIORITY 5: Question pattern = query (kecuali ada amount + transaction action)
        elseif ($hasQuestionPattern && !($hasAmount && $hasTransactionAction)) {
            $intent = 'query';
        }
        // PRIORITY 6: Transaction action tanpa amount = transaction (mungkin perlu tanya amount)
        elseif ($hasTransactionAction && !$hasAmount) {
            $intent = 'transaction';
        }
        // PRIORITY 7: Amount tanpa context = transaction
        elseif ($hasAmount && !$hasQueryContext) {
            $intent = 'transaction';
        }
        // PRIORITY 8: Query context dengan amount = query (e.g., "berapa pengeluaran bulan ini?")
        elseif ($hasQueryContext && $hasAmount) {
            $intent = 'query';
        }
        // PRIORITY 9: Default berdasarkan amount
        else {
            $intent = $hasAmount ? 'transaction' : 'query';
        }
        
        Log::info('Intent classification fallback', [
            'message_text' => $messageText,
            'intent' => $intent,
            'has_strong_query_keyword' => $hasStrongQueryKeyword,
            'has_query_context' => $hasQueryContext,
            'has_transaction_action' => $hasTransactionAction,
            'has_belanjaan' => $hasBelanjaan,
            'has_belanja' => $hasBelanja,
            'has_amount' => $hasAmount,
            'has_question_pattern' => $hasQuestionPattern,
            'confidence' => 0.8
        ]);
        
        return [
            'success' => true,
            'data' => [
                'intent' => $intent,
                'confidence' => 0.8
            ]
        ];
    }

    /**
     * Process message (orchestrator)
     */
    public function processMessage(int $tenantId, int $messageId, string $messageText, string $messageType, ?string $contentUrl = null): array
    {
        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/process-message", [
                    'tenant_id' => $tenantId,
                    'message_id' => $messageId,
                    'message_text' => $messageText,
                    'message_type' => $messageType,
                    'content_url' => $contentUrl
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            return [
                'success' => false,
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Error processing message with AI', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

