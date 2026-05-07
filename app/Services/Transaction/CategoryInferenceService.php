<?php

namespace App\Services\Transaction;

use Illuminate\Support\Facades\Log;

/**
 * CategoryInferenceService - Expert pipeline untuk kategorisasi transaksi
 * 
 * Pipeline Steps:
 * 1. Preprocessing - Normalisasi teks
 * 2. Intent Detection - Tentukan income/expense/debt
 * 3. Entity Extraction - Extract amount, date, merchant
 * 4. Category Matching - Weighted keyword matching
 * 5. Context Resolution - Resolve konflik antar keyword
 * 6. Confidence Scoring - Beri skor keyakinan
 * 7. Final Decision - Keputusan akhir kategori
 */
class CategoryInferenceService
{
    protected string $messageText;
    protected string $messageLower;
    protected array $result = [];
    protected array $pipelineData = [];

    // Category configuration dengan priority dan context rules
    protected array $categoryConfig = [
        'pengeluaran_acara' => [
            'name' => 'Acara & Hajatan',
            'icon' => '🎊',
            'keywords' => ['undangan', 'hajatan', 'acara', 'wedding', 'nikah', 'pernikahan', 'syukuran', 'walimatul'],
            'context_rules' => [
                'kasih' => ['amplop', 'sumbangan'], // "kasih amplop" → pengeluaran_sosial, tapi "kasih undangan" → pengeluaran_acara
                'beli' => ['undangan', 'kartu'], // "beli undangan" → pengeluaran_acara
            ],
            'priority' => 10,
            'type' => 'expense',
        ],
        'pengeluaran_sosial' => [
            'name' => 'Sosial & Kondangan',
            'icon' => '🤝',
            'keywords' => ['kondangan', 'amplop', 'sumbangan', 'sedekah', 'infaq', 'zakat'],
            'context_rules' => [
                'kasih' => ['amplop'], // "kasih amplop" → pengeluaran_sosial
                'terima' => ['amplop'], // "terima amplop" → pendapatan_lainnya
            ],
            'priority' => 9,
            'type' => 'expense',
        ],
        'pengeluaran_makanan' => [
            'name' => 'Makanan & Minuman',
            'icon' => '🍽️',
            'keywords' => ['makan', 'minum', 'sarapan', 'lunch', 'dinner', 'kopi', 'kafe', 'warung', 'restoran'],
            'context_rules' => [],
            'priority' => 8,
            'type' => 'expense',
        ],
        'pengeluaran_transport' => [
            'name' => 'Transportasi',
            'icon' => '🚗',
            'keywords' => ['bensin', 'bbm', 'parkir', 'tol', 'taksi', 'grab', 'gojek', 'maxim', 'ojek'],
            'context_rules' => [],
            'priority' => 8,
            'type' => 'expense',
        ],
        'pengeluaran_gaji' => [
            'name' => 'Gaji Karyawan',
            'icon' => '👷',
            'keywords' => ['gaji', 'upah', 'honor', 'gaji karyawan'],
            'context_rules' => [
                'kasih' => ['gaji'], // "kasih gaji" → pengeluaran_gaji
                'ambil' => ['gaji'], // "ambil gaji" → pengeluaran_gaji
            ],
            'priority' => 15,
            'type' => 'expense',
        ],
        'pengeluaran_bayar_hutang' => [
            'name' => 'Bayar Hutang',
            'icon' => '�',
            'keywords' => ['bayar hutang', 'bayar utang', 'lunasi hutang'],
            'context_rules' => [],
            'priority' => 20,
            'type' => 'expense',
        ],
        'pengeluaran_piutang' => [
            'name' => 'Piutang / Pinjaman Keluar',
            'icon' => '💸',
            'keywords' => ['kasih pinjam', 'pinjamkan', 'piutang'],
            'context_rules' => [],
            'priority' => 20,
            'type' => 'expense',
        ],
        'pendapatan_gaji' => [
            'name' => 'Gaji',
            'icon' => '💰',
            'keywords' => ['gajian', 'gaji bulan', 'gaji'],
            'context_rules' => [
                'terima' => ['gaji'], // "terima gaji" → pendapatan_gaji
            ],
            'priority' => 15,
            'type' => 'income',
        ],
        'pendapatan_bonus' => [
            'name' => 'Bonus',
            'icon' => '🎁',
            'keywords' => ['bonus', 'thr', 'insentif', 'komisi', 'uang lembur'],
            'context_rules' => [
                'terima' => ['bonus', 'thr', 'insentif', 'komisi'],
            ],
            'priority' => 14,
            'type' => 'income',
        ],
        'pendapatan_investasi' => [
            'name' => 'Investasi',
            'icon' => '�',
            'keywords' => ['dividen', 'bunga', 'hasil investasi', 'profit', 'cuan', 'jual saham', 'cair reksadana', 'capital gain'],
            'context_rules' => [
                'terima' => ['dividen', 'bunga'],
            ],
            'priority' => 13,
            'type' => 'income',
        ],
        'pendapatan_transfer' => [
            'name' => 'Transfer Masuk',
            'icon' => '📥',
            'keywords' => ['transfer masuk', 'tf masuk', 'dari', 'kiriman', 'transfer'],
            'context_rules' => [
                'transfer' => ['dari', 'masuk'],
            ],
            'priority' => 12,
            'type' => 'income',
        ],
        'pendapatan_usaha' => [
            'name' => 'Pendapatan Usaha',
            'icon' => '🏪',
            'keywords' => ['penjualan', 'jualan', 'laku', 'omset', 'bayar pesanan', 'invoice paid', 'dp', 'pelunasan'],
            'context_rules' => [
                'terima' => ['dp', 'pelunasan'],
            ],
            'priority' => 13,
            'type' => 'income',
        ],
        'pendapatan_sewa' => [
            'name' => 'Pendapatan Sewa',
            'icon' => '🏘️',
            'keywords' => ['sewa', 'kontrakan', 'kost', 'kos'],
            'context_rules' => [
                'terima' => ['sewa', 'kontrakan', 'kost', 'kos'],
            ],
            'priority' => 11,
            'type' => 'income',
        ],
        'pendapatan_refund' => [
            'name' => 'Refund & Cashback',
            'icon' => '💸',
            'keywords' => ['refund', 'cashback', 'kembalian', 'pengembalian', 'retur', 'rebate'],
            'context_rules' => [
                'terima' => ['refund', 'cashback', 'kembalian', 'pengembalian'],
            ],
            'priority' => 12,
            'type' => 'income',
        ],
        'pendapatan_hutang' => [
            'name' => 'Terima Hutang (Pinjaman Masuk)',
            'icon' => '�',
            'keywords' => ['dapat pinjaman', 'pinjaman dari', 'hutang dari'],
            'context_rules' => [],
            'priority' => 20,
            'type' => 'income',
        ],
        'pendapatan_terima_piutang' => [
            'name' => 'Terima Pelunasan Piutang',
            'icon' => '✅',
            'keywords' => ['piutang lunas', 'terima piutang', 'terima pelunasan'],
            'context_rules' => [],
            'priority' => 20,
            'type' => 'income',
        ],
        'pengeluaran_hunian' => [
            'name' => 'Hunian',
            'icon' => '🏠',
            'keywords' => ['sewa', 'kost', 'kos', 'rumah', 'apartemen', 'kontrakan'],
            'context_rules' => [
                'bayar' => ['sewa', 'kost', 'kos'],
            ],
            'priority' => 12,
            'type' => 'expense',
        ],
        'pengeluaran_utilitas' => [
            'name' => 'Utilitas',
            'icon' => '⚡',
            'keywords' => ['listrik', 'air', 'pln', 'pdam', 'internet', 'wifi', 'token', 'pulsa', 'paket data'],
            'context_rules' => [
                'bayar' => ['listrik', 'air', 'token', 'internet'],
            ],
            'priority' => 12,
            'type' => 'expense',
        ],
        'pengeluaran_kesehatan' => [
            'name' => 'Kesehatan',
            'icon' => '🏥',
            'keywords' => ['obat', 'dokter', 'rumah sakit', 'klinik', 'apotek', 'vitamin', 'suntik', 'cedera'],
            'context_rules' => [],
            'priority' => 11,
            'type' => 'expense',
        ],
        'pengeluaran_pendidikan' => [
            'name' => 'Pendidikan',
            'icon' => '📚',
            'keywords' => ['buku', 'sekolah', 'kuliah', 'kursus', 'les', 'sp', 'uas', 'uts'],
            'context_rules' => [],
            'priority' => 11,
            'type' => 'expense',
        ],
        'pengeluaran_belanja' => [
            'name' => 'Belanja',
            'icon' => '🛒',
            'keywords' => ['belanja', 'beli', 'shopee', 'tokopedia', 'lazada', 'market', 'supermarket'],
            'context_rules' => [],
            'priority' => 7,
            'type' => 'expense',
        ],
        'pengeluaran_hiburan' => [
            'name' => 'Hiburan',
            'icon' => '🎬',
            'keywords' => ['bioskop', 'nonton', 'film', 'game', 'steam', 'playstation', 'ps'],
            'context_rules' => [],
            'priority' => 8,
            'type' => 'expense',
        ],
        'pengeluaran_pulsa_token' => [
            'name' => 'Pulsa & Token',
            'icon' => '📱',
            'keywords' => ['pulsa', 'token listrik', 'voucher', 'paket data'],
            'context_rules' => [
                'beli' => ['pulsa', 'token'],
            ],
            'priority' => 10,
            'type' => 'expense',
        ],
        'pengeluaran_tagihan' => [
            'name' => 'Tagihan',
            'icon' => '📄',
            'keywords' => ['tagihan', 'bill', 'invoice', 'cicilan', 'angsuran'],
            'context_rules' => [
                'bayar' => ['tagihan', 'bill'],
            ],
            'priority' => 12,
            'type' => 'expense',
        ],
        'pengeluaran_investasi' => [
            'name' => 'Investasi',
            'icon' => '💼',
            'keywords' => ['investasi', 'reksadana', 'saham', 'crypto', 'bitcoin', 'eth', 'ethereum', 'emas', 'logam mulia'],
            'context_rules' => [
                'beli' => ['reksadana', 'saham', 'crypto', 'emas', 'logam mulia'],
                'top up' => ['reksadana'],
            ],
            'priority' => 12,
            'type' => 'expense',
        ],
        'pengeluaran_pinjaman' => [
            'name' => 'Pinjaman',
            'icon' => '💳',
            'keywords' => ['pinjaman', 'pinjol', 'paylater', 'kredivo', 'akulaku', 'kredit'],
            'context_rules' => [
                'bayar' => ['pinjaman', 'pinjol', 'paylater', 'kredivo', 'akulaku', 'kredit'],
            ],
            'priority' => 13,
            'type' => 'expense',
        ],
        'pengeluaran_cicilan' => [
            'name' => 'Cicilan',
            'icon' => '🏦',
            'keywords' => ['cicilan', 'angsuran', 'cicil', 'kpr', 'leasing'],
            'context_rules' => [
                'bayar' => ['cicilan', 'angsuran', 'kpr', 'leasing'],
            ],
            'priority' => 13,
            'type' => 'expense',
        ],
        'pengeluaran_asuransi' => [
            'name' => 'Asuransi',
            'icon' => '🛡️',
            'keywords' => ['asuransi', 'premi', 'bpjs', 'prudential', 'allianz'],
            'context_rules' => [
                'bayar' => ['asuransi', 'premi', 'bpjs'],
            ],
            'priority' => 12,
            'type' => 'expense',
        ],
        'pengeluaran_pajak' => [
            'name' => 'Pajak',
            'icon' => '📊',
            'keywords' => ['pajak', 'pph', 'ppn', 'pbb', 'samsat', 'stnk'],
            'context_rules' => [
                'bayar' => ['pajak', 'pph', 'ppn', 'pbb', 'samsat', 'stnk'],
            ],
            'priority' => 12,
            'type' => 'expense',
        ],
        'pengeluaran_donasi' => [
            'name' => 'Donasi',
            'icon' => '❤️',
            'keywords' => ['donasi', 'sedekah', 'infaq', 'infak', 'zakat', 'sumbangan', 'amal', 'santunan', 'wakaf', 'qurban'],
            'context_rules' => [],
            'priority' => 10,
            'type' => 'expense',
        ],
        'pengeluaran_keluarga' => [
            'name' => 'Keluarga',
            'icon' => '👨‍👩‍👧‍👦',
            'keywords' => ['keluarga', 'orang tua', 'ibu', 'bapak', 'anak', 'adik', 'kakak', 'saudara'],
            'context_rules' => [
                'kasih' => ['keluarga', 'ibu', 'bapak', 'anak'],
            ],
            'priority' => 9,
            'type' => 'expense',
        ],
        'pengeluaran_langganan' => [
            'name' => 'Langganan',
            'icon' => '🔄',
            'keywords' => ['netflix', 'spotify', 'youtube premium', 'disney', 'langganan'],
            'context_rules' => [
                'bayar' => ['langganan', 'netflix', 'spotify'],
            ],
            'priority' => 10,
            'type' => 'expense',
        ],
        'pengeluaran_pakaian' => [
            'name' => 'Pakaian & Fashion',
            'icon' => '👕',
            'keywords' => ['pakaian', 'fashion', 'baju', 'celana', 'sepatu', 'jaket', 'uniqlo', 'zara', 'h&m', 'hm'],
            'context_rules' => [
                'beli' => ['baju', 'celana', 'sepatu', 'jaket', 'pakaian'],
            ],
            'priority' => 10,
            'type' => 'expense',
        ],
        'pengeluaran_perawatan_diri' => [
            'name' => 'Perawatan Diri',
            'icon' => '💇',
            'keywords' => ['salon', 'barber', 'potong rambut', 'spa', 'skincare', 'treatment', 'makeup'],
            'context_rules' => [
                'beli' => ['skincare', 'makeup'],
            ],
            'priority' => 10,
            'type' => 'expense',
        ],
        'pengeluaran_otomotif' => [
            'name' => 'Otomotif',
            'icon' => '🔧',
            'keywords' => ['bengkel', 'servis', 'service', 'oli', 'ban', 'aki', 'sparepart', 'spare part', 'tune up'],
            'context_rules' => [
                'beli' => ['oli', 'ban', 'aki', 'sparepart', 'spare part'],
                'servis' => ['motor', 'mobil'],
            ],
            'priority' => 11,
            'type' => 'expense',
        ],
        'pengeluaran_hadiah' => [
            'name' => 'Hadiah & Bingkisan',
            'icon' => '🎁',
            'keywords' => ['hadiah', 'kado', 'bingkisan', 'parcel', 'hampers'],
            'context_rules' => [
                'beli' => ['hadiah', 'kado', 'bingkisan', 'parcel', 'hampers'],
            ],
            'priority' => 9,
            'type' => 'expense',
        ],
        'pengeluaran_modal' => [
            'name' => 'Modal & Stok',
            'icon' => '📦',
            'keywords' => ['modal', 'stok', 'stock', 'restock', 'kulakan', 'bahan baku', 'supplier', 'grosir', 'kulak'],
            'context_rules' => [
                'beli' => ['stok', 'stock', 'bahan baku', 'kulakan', 'supplier', 'grosir'],
            ],
            'priority' => 12,
            'type' => 'expense',
        ],
        'pengeluaran_operasional' => [
            'name' => 'Operasional',
            'icon' => '⚙️',
            'keywords' => ['operasional', 'packing', 'packaging', 'ekspedisi', 'ongkir', 'shipping', 'admin', 'biaya admin', 'fee', 'komisi marketplace'],
            'context_rules' => [
                'bayar' => ['operasional', 'admin', 'biaya admin', 'fee'],
            ],
            'priority' => 11,
            'type' => 'expense',
        ],
        'pengeluaran_transfer' => [
            'name' => 'Transfer Keluar',
            'icon' => '📤',
            'keywords' => ['transfer keluar', 'tf keluar', 'kirim', 'transfer', 'tf'],
            'context_rules' => [
                'transfer' => ['ke', 'keluar'],
            ],
            'priority' => 11,
            'type' => 'expense',
        ],
        'pengeluaran_lainnya' => [
            'name' => 'Pengeluaran Lainnya',
            'icon' => '📝',
            'keywords' => [],
            'context_rules' => [],
            'priority' => 1,
            'type' => 'expense',
        ],
        'pendapatan_lainnya' => [
            'name' => 'Pendapatan Lainnya',
            'icon' => '💵',
            'keywords' => [],
            'context_rules' => [],
            'priority' => 1,
            'type' => 'income',
        ],
    ];

    /**
     * Main entry point - proses seluruh pipeline
     */
    public function infer(string $messageText): array
    {
        $this->messageText = $messageText;
        $this->messageLower = mb_strtolower($messageText);
        $this->pipelineData = [
            'original' => $messageText,
            'lower' => $this->messageLower,
            'scores' => [], // category_type => score
            'detected_intent' => null,
            'entities' => [],
        ];

        Log::info('CategoryInference: Starting pipeline', [
            'message' => $messageText,
        ]);

        // Step 1: Preprocessing
        $this->stepPreprocessing();

        // Step 2: Intent Detection
        $this->stepIntentDetection();

        // Step 3: Entity Extraction
        $this->stepEntityExtraction();

        // Step 4: Category Matching
        $this->stepCategoryMatching();

        // Step 5: Context Resolution
        $this->stepContextResolution();

        // Step 6: Confidence Scoring
        $this->stepConfidenceScoring();

        // Step 7: Final Decision
        $result = $this->stepFinalDecision();

        Log::info('CategoryInference: Pipeline complete', [
            'message' => $messageText,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Step 1: Preprocessing - Normalisasi teks
     */
    protected function stepPreprocessing(): void
    {
        // Remove extra whitespace
        $normalized = preg_replace('/\s+/', ' ', $this->messageLower);
        $this->pipelineData['normalized'] = trim($normalized);
        
        // Remove common prefixes/suffixes that don't affect categorization
        $cleaned = preg_replace('/(^ya\s+|^ok\s+|^makasih\s+|^terima kasih\s+)/i', '', $normalized);
        $this->pipelineData['cleaned'] = $cleaned;
    }

    /**
     * Step 2: Intent Detection - Tentukan income/expense/debt
     */
    protected function stepIntentDetection(): void
    {
        $text = $this->pipelineData['cleaned'];
        
        // Detect debt flow (priority tinggi)
        $debtPatterns = [
            'pengeluaran_bayar_hutang' => ['bayar hutang', 'bayar utang', 'lunasi hutang', 'lunasi utang'],
            'pendapatan_hutang' => ['dapat pinjaman', 'pinjaman dari', 'hutang dari', 'dipinjemin'],
            'pengeluaran_piutang' => ['kasih pinjam', 'pinjamkan', 'piutang ke', 'pijemin ke'],
            'pendapatan_terima_piutang' => ['piutang lunas', 'terima piutang', 'terima pelunasan'],
        ];

        foreach ($debtPatterns as $categoryType => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($text, $pattern)) {
                    $this->pipelineData['detected_intent'] = $categoryType;
                    $this->pipelineData['intent_type'] = str_contains($categoryType, 'pengeluaran') ? 'expense' : 'income';
                    $this->pipelineData['scores'][$categoryType] = 100; // Max confidence
                    Log::debug('Intent: Debt flow detected', ['type' => $categoryType]);
                    return;
                }
            }
        }

        // Detect income keywords
        $incomeKeywords = ['gajian', 'terima', 'masuk', 'dapat', 'pemasukan', 'pendapatan', 'bonus', 'thr', 'insentif', 'komisi', 'cashback', 'refund', 'dividen', 'bunga', 'penjualan', 'jualan', 'omset', 'laku'];
        $isIncome = false;
        foreach ($incomeKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $isIncome = true;
                break;
            }
        }

        // Detect expense keywords
        $expenseKeywords = ['bayar', 'beli', 'belanja', 'keluar', 'dibayar', 'cicil', 'cicilan', 'angsuran', 'kpr', 'asuransi', 'premi', 'pajak', 'kulakan', 'restock', 'servis', 'service'];
        $isExpense = false;
        foreach ($expenseKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $isExpense = true;
                break;
            }
        }

        if (preg_match('/\b(transfer|tf|kirim)\b/u', $text)) {
            if (preg_match('/\b(ke|keluar)\b/u', $text)) {
                $isExpense = true;
            } elseif (preg_match('/\b(dari|masuk)\b/u', $text)) {
                $isIncome = true;
            }
        }

        // Priority: if both detected, expense wins (more specific)
        if ($isExpense) {
            $this->pipelineData['intent_type'] = 'expense';
        } elseif ($isIncome) {
            $this->pipelineData['intent_type'] = 'income';
        } else {
            // Default: assume expense for messages like "kasih undangan hajatan"
            $this->pipelineData['intent_type'] = 'expense';
        }

        Log::debug('Intent: Type detected', ['type' => $this->pipelineData['intent_type']]);
    }

    /**
     * Step 3: Entity Extraction - Extract amount, date, merchant
     */
    protected function stepEntityExtraction(): void
    {
        $text = $this->pipelineData['cleaned'];
        
        // Extract amount (simplified - bisa gunakan helper yang sudah ada)
        $amount = $this->extractAmount($text);
        $this->pipelineData['entities']['amount'] = $amount;

        // Extract date
        $date = $this->extractDate($text);
        $this->pipelineData['entities']['date'] = $date;

        Log::debug('Entity extraction complete', $this->pipelineData['entities']);
    }

    /**
     * Step 4: Category Matching - Weighted keyword matching
     */
    protected function stepCategoryMatching(): void
    {
        $text = $this->pipelineData['cleaned'];
        $intentType = $this->pipelineData['intent_type'] ?? 'expense';

        foreach ($this->categoryConfig as $categoryType => $config) {
            // Skip jika tipe berbeda (income vs expense)
            if ($config['type'] !== $intentType) {
                continue;
            }

            $score = 0;
            $matchedKeywords = [];

            // Check keywords
            foreach ($config['keywords'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score += 10; // Base score per keyword match
                    $matchedKeywords[] = $keyword;
                    
                    // Bonus: exact word boundary match
                    if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $text)) {
                        $score += 5;
                    }
                }
            }

            if ($score > 0) {
                // Apply priority multiplier
                $score *= ($config['priority'] / 10);
                $this->pipelineData['scores'][$categoryType] = $score;
                $this->pipelineData['matched_keywords'][$categoryType] = $matchedKeywords;
            }
        }

        Log::debug('Category matching scores', [
            'scores' => $this->pipelineData['scores'],
            'matched_keywords' => $this->pipelineData['matched_keywords'] ?? [],
        ]);
    }

    /**
     * Step 5: Context Resolution - Resolve konflik antar keyword
     * Enhanced dengan semantic understanding
     */
    protected function stepContextResolution(): void
    {
        $text = $this->pipelineData['cleaned'];

        // Apply semantic context detection
        $this->applySemanticContext();

        // Check context rules untuk setiap kategori yang terdeteksi
        foreach ($this->categoryConfig as $categoryType => $config) {
            if (!isset($this->pipelineData['scores'][$categoryType])) {
                continue;
            }

            // Apply context rules
            foreach ($config['context_rules'] as $contextKeyword => $relatedKeywords) {
                if (str_contains($text, $contextKeyword)) {
                    foreach ($relatedKeywords as $related) {
                        if (str_contains($text, $related)) {
                            // Context match! Boost score significantly
                            $this->pipelineData['scores'][$categoryType] += 30;
                            $this->pipelineData['context_boost'][$categoryType] = "{$contextKeyword} + {$related}";
                            Log::debug('Context boost applied', [
                                'category' => $categoryType,
                                'context' => $contextKeyword,
                                'related' => $related,
                            ]);
                        }
                    }
                }
            }
        }

        // Advanced context: Detect verb-noun combinations
        $this->resolveVerbNounContext();

        arsort($this->pipelineData['scores']);
    }

    /**
     * Apply semantic context understanding
     */
    protected function applySemanticContext(): void
    {
        $text = $this->pipelineData['cleaned'];
        $intentType = $this->pipelineData['intent_type'] ?? 'expense';

        // Context 1: "kasih" (memberi) - pahami tujuan pemberian
        if ($intentType === 'expense' && str_contains($text, 'kasih')) {
            if (str_contains($text, 'undangan') || str_contains($text, 'hajatan') || str_contains($text, 'acara')) {
                // Kasih terkait acara → pengeluaran_acara
                $this->boostCategory('pengeluaran_acara', 50, 'semantic: kasih + undangan/hajatan');
            } elseif (str_contains($text, 'amplop') || str_contains($text, 'sumbangan')) {
                // Kasih amplop/sumbangan → pengeluaran_sosial
                $this->boostCategory('pengeluaran_sosial', 45, 'semantic: kasih + amplop/sumbangan');
            } elseif (str_contains($text, 'gaji') || str_contains($text, 'upah')) {
                // Kasih gaji → pengeluaran_gaji
                $this->boostCategory('pengeluaran_gaji', 55, 'semantic: kasih + gaji/upah');
            } elseif (str_contains($text, 'pinjam') || str_contains($text, 'hutang')) {
                // Kasih pinjaman → pengeluaran_piutang
                $this->boostCategory('pengeluaran_piutang', 50, 'semantic: kasih + pinjam/hutang');
            }
        }

        // Context 2: "terima" (menerima) - pahami sumber penerimaan
        if ($intentType === 'income' && str_contains($text, 'terima')) {
            if (str_contains($text, 'gaji') || str_contains($text, 'honor')) {
                // Terima gaji → pendapatan_gaji
                $this->boostCategory('pendapatan_gaji', 55, 'semantic: terima + gaji/honor');
            } elseif (str_contains($text, 'piutang')) {
                // Terima piutang → pendapatan_terima_piutang
                $this->boostCategory('pendapatan_terima_piutang', 50, 'semantic: terima + piutang');
            } elseif (str_contains($text, 'amplop')) {
                // Terima amplop → pendapatan_lainnya (income)
                $this->boostCategory('pendapatan_lainnya', 40, 'semantic: terima + amplop');
            } elseif (str_contains($text, 'bonus') || str_contains($text, 'thr') || str_contains($text, 'insentif') || str_contains($text, 'komisi')) {
                $this->boostCategory('pendapatan_bonus', 50, 'semantic: terima + bonus/thr/insentif/komisi');
            } elseif (str_contains($text, 'refund') || str_contains($text, 'cashback')) {
                $this->boostCategory('pendapatan_refund', 50, 'semantic: terima + refund/cashback');
            }
        }

        // Context 3: "beli" (membeli) - pahami objek pembelian
        if ($intentType === 'expense' && str_contains($text, 'beli')) {
            if (str_contains($text, 'undangan') || str_contains($text, 'kartu') || str_contains($text, 'buku tamu')) {
                // Beli terkait acara → pengeluaran_acara
                $this->boostCategory('pengeluaran_acara', 45, 'semantic: beli + undangan/kartu');
            } elseif (str_contains($text, 'obat') || str_contains($text, 'vitamin')) {
                // Beli obat → pengeluaran_kesehatan
                $this->boostCategory('pengeluaran_kesehatan', 45, 'semantic: beli + obat/vitamin');
            } elseif (str_contains($text, 'buku') || str_contains($text, 'alat tulis')) {
                // Beli buku → pengeluaran_pendidikan
                $this->boostCategory('pengeluaran_pendidikan', 45, 'semantic: beli + buku/alat tulis');
            } elseif (str_contains($text, 'baju') || str_contains($text, 'sepatu') || str_contains($text, 'pakaian')) {
                $this->boostCategory('pengeluaran_pakaian', 45, 'semantic: beli + pakaian');
            } elseif (str_contains($text, 'skincare') || str_contains($text, 'makeup')) {
                $this->boostCategory('pengeluaran_perawatan_diri', 45, 'semantic: beli + perawatan diri');
            } elseif (str_contains($text, 'oli') || str_contains($text, 'ban') || str_contains($text, 'aki') || str_contains($text, 'sparepart')) {
                $this->boostCategory('pengeluaran_otomotif', 45, 'semantic: beli + otomotif');
            } elseif (str_contains($text, 'stok') || str_contains($text, 'bahan baku') || str_contains($text, 'kulakan')) {
                $this->boostCategory('pengeluaran_modal', 45, 'semantic: beli + stok/modal');
            }
        }

        // Context 4: Location-based inference
        if ($intentType === 'expense' && (str_contains($text, 'di') || str_contains($text, 'ke'))) {
            if (preg_match('/\b(di|ke)\s+(restoran|kafe|warung|rm\.?)\b/i', $text)) {
                $this->boostCategory('pengeluaran_makanan', 30, 'semantic: location indicates eating');
            } elseif (preg_match('/\b(di|ke)\s+(apotek|klinik|rumah sakit)\b/i', $text)) {
                $this->boostCategory('pengeluaran_kesehatan', 30, 'semantic: location indicates health');
            } elseif (preg_match('/\b(di|ke)\s+(spbu)\b/i', $text)) {
                $this->boostCategory('pengeluaran_transport', 30, 'semantic: location indicates fuel');
            } elseif (preg_match('/\b(di|ke)\s+(bengkel)\b/i', $text)) {
                $this->boostCategory('pengeluaran_otomotif', 30, 'semantic: location indicates workshop');
            } elseif (preg_match('/\b(di|ke)\s+(salon|barber)\b/i', $text)) {
                $this->boostCategory('pengeluaran_perawatan_diri', 30, 'semantic: location indicates grooming');
            }
        }
    }

    /**
     * Resolve verb-noun context combinations
     */
    protected function resolveVerbNounContext(): void
    {
        $text = $this->pipelineData['cleaned'];
        
        // Pattern: [verb] [noun] - understand the combination
        $patterns = [
            '/\bbayar\s+(listrik|air|internet|token)\b/i' => ['pengeluaran_utilitas', 40],
            '/\bbayar\s+(hutang|utang)\b/i' => ['pengeluaran_bayar_hutang', 60],
            '/\bbayar\s+(cicilan|angsuran|kpr|leasing)\b/i' => ['pengeluaran_cicilan', 55],
            '/\bbayar\s+(asuransi|premi|bpjs)\b/i' => ['pengeluaran_asuransi', 50],
            '/\bbayar\s+(pajak|pph|ppn|pbb|samsat|stnk)\b/i' => ['pengeluaran_pajak', 55],
            '/\bbayar\s+(pinjaman|pinjol|paylater|kredivo|akulaku)\b/i' => ['pengeluaran_pinjaman', 55],
            '/\bbeli\s+(bensin|bbm|solar)\b/i' => ['pengeluaran_transport', 40],
            '/\bmakan\s+(siang|malam|pagi)\b/i' => ['pengeluaran_makanan', 40],
            '/\bbeli\s+(saham|reksadana|emas|crypto|bitcoin|eth|ethereum)\b/i' => ['pengeluaran_investasi', 45],
            '/\b(servis|service)\s+(motor|mobil)\b/i' => ['pengeluaran_otomotif', 45],
            '/\b(beli|kulakan)\s+(stok|stock|bahan baku)\b/i' => ['pengeluaran_modal', 45],
            '/\btransfer\s+(masuk|dari)\b/i' => ['pendapatan_transfer', 35],
            '/\btransfer\s+(keluar|ke)\b/i' => ['pengeluaran_transfer', 35],
        ];

        foreach ($patterns as $pattern => [$categoryType, $boost]) {
            if (preg_match($pattern, $text)) {
                $this->boostCategory($categoryType, $boost, "verb-noun pattern: {$pattern}");
            }
        }
    }

    /**
     * Boost category score with logging
     */
    protected function boostCategory(string $categoryType, int $boost, string $reason): void
    {
        $oldScore = $this->pipelineData['scores'][$categoryType] ?? 0;
        $this->pipelineData['scores'][$categoryType] = $oldScore + $boost;
        $this->pipelineData['context_boost'][$categoryType] = $reason;
        
        Log::debug('Category boosted', [
            'category' => $categoryType,
            'boost' => $boost,
            'reason' => $reason,
            'old_score' => $oldScore,
            'new_score' => $oldScore + $boost,
        ]);
    }

    /**
     * Step 6: Confidence Scoring - Beri skor keyakinan
     */
    protected function stepConfidenceScoring(): void
    {
        $scores = $this->pipelineData['scores'];
        
        if (empty($scores)) {
            $this->pipelineData['confidence'] = 0.3; // Low confidence
            return;
        }

        // Calculate confidence based on score distribution
        $maxScore = max($scores);
        $totalScore = array_sum($scores);
        if ($totalScore <= 0) {
            $this->pipelineData['confidence'] = 0.3;
            $this->pipelineData['max_score'] = $maxScore;
            $this->pipelineData['total_score'] = $totalScore;
            return;
        }
        
        // Higher max score relative to total = higher confidence
        $confidence = $maxScore / $totalScore;
        
        // Cap between 0.3 and 0.99
        $confidence = max(0.3, min(0.99, $confidence));
        
        $this->pipelineData['confidence'] = $confidence;
        $this->pipelineData['max_score'] = $maxScore;
        $this->pipelineData['total_score'] = $totalScore;
    }

    /**
     * Step 7: Final Decision - Keputusan akhir kategori
     */
    protected function stepFinalDecision(): array
    {
        $scores = $this->pipelineData['scores'];
        $intentType = $this->pipelineData['intent_type'] ?? 'expense';
        $confidence = $this->pipelineData['confidence'] ?? 0.3;

        // If debt flow was detected, use it directly
        if (!empty($this->pipelineData['detected_intent'])) {
            $categoryType = $this->pipelineData['detected_intent'];
            return $this->buildResult($categoryType, 0.95, 'debt_flow_detection');
        }

        // If no scores, use default
        if (empty($scores)) {
            $defaultType = $intentType === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
            return $this->buildResult($defaultType, 0.3, 'fallback_default');
        }

        // Get top category
        $topCategoryType = array_key_first($scores);
        $topScore = $scores[$topCategoryType];

        // If confidence too low, use default
        if ($confidence < 0.4) {
            $defaultType = $intentType === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
            return $this->buildResult($defaultType, $confidence, 'low_confidence_fallback');
        }

        return $this->buildResult($topCategoryType, $confidence, 'pipeline_decision');
    }

    /**
     * Build final result array
     */
    protected function buildResult(string $categoryType, float $confidence, string $source): array
    {
        $config = $this->categoryConfig[$categoryType] ?? null;
        
        return [
            'category_type' => $categoryType,
            'category_name' => $config['name'] ?? 'Unknown',
            'category_icon' => $config['icon'] ?? '📝',
            'type' => $config['type'] ?? ($this->pipelineData['intent_type'] ?? 'expense'),
            'confidence' => $confidence,
            'source' => $source,
            'entities' => $this->pipelineData['entities'] ?? [],
            'metadata' => [
                'pipeline_data' => $this->pipelineData,
                'all_scores' => $this->pipelineData['scores'] ?? [],
            ],
        ];
    }

    /**
     * Simple amount extraction (placeholder - bisa gunakan helper yang ada)
     */
    protected function extractAmount(string $text): ?float
    {
        // Pattern: 15rb, 50.000, 1jt, Rp 100000
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k)\b/i', $text, $matches)) {
            $num = str_replace(',', '.', $matches[1]);
            return floatval($num) * 1000;
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(jt|juta|m)\b/i', $text, $matches)) {
            $num = str_replace(',', '.', $matches[1]);
            return floatval($num) * 1000000;
        }
        if (preg_match('/(\d{1,3}(?:[.,]\d{3})+)/i', $text, $matches)) {
            return floatval(str_replace([',', '.'], '', $matches[1]));
        }
        if (preg_match('/(\d+)/', $text, $matches)) {
            return floatval($matches[1]);
        }
        return null;
    }

    /**
     * Simple date extraction (placeholder)
     */
    protected function extractDate(string $text): string
    {
        if (preg_match('/kemarin/', $text)) {
            return now()->subDay()->toDateString();
        }
        if (preg_match('/lusa/', $text)) {
            return now()->addDay()->toDateString();
        }
        return now()->toDateString();
    }
}
