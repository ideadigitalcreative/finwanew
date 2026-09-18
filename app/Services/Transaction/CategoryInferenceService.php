<?php

namespace App\Services\Transaction;

use Illuminate\Support\Facades\Log;

class CategoryInferenceService
{
    protected string $messageText;
    protected string $messageLower;
    protected array $pipelineData = [];
    protected array $categoryMap = [];

    protected bool $loaded = false;

    protected function ensureLoaded(): void
    {
        if ($this->loaded) return;
        $this->loaded = true;
        $this->loadCategoryMap();
    }

    protected function loadCategoryMap(): void
    {
        $expenseMap = config('finwa_category_rules.expense_keywords', []);
        $incomeMap = config('finwa_category_rules.income_keywords', []);
        $extras = config('finwa_category_rules.local_expense_extras', []);

        $this->categoryMap = [];

        foreach ($expenseMap as $keyword => $category) {
            $this->categoryMap[$category]['keywords'][] = $keyword;
            $this->categoryMap[$category]['type'] = 'expense';
        }

        foreach ($incomeMap as $keyword => $category) {
            $this->categoryMap[$category]['keywords'][] = $keyword;
            $this->categoryMap[$category]['type'] = 'income';
        }

        foreach ($extras as $keyword => $category) {
            $this->categoryMap[$category]['keywords'][] = $keyword;
            if (!isset($this->categoryMap[$category]['type'])) {
                $this->categoryMap[$category]['type'] = str_contains($category, 'pengeluaran') ? 'expense' : 'income';
            }
        }

        $this->categoryMap['pengeluaran_lainnya'] = [
            'keywords' => [],
            'type' => 'expense',
            'name' => 'Pengeluaran Lainnya',
            'icon' => '📝',
        ];
        $this->categoryMap['pendapatan_lainnya'] = [
            'keywords' => [],
            'type' => 'income',
            'name' => 'Pendapatan Lainnya',
            'icon' => '💵',
        ];
        // Ensure bahan_makanan has correct metadata even if not in config
        if (!isset($this->categoryMap['pengeluaran_bahan_makanan'])) {
            $this->categoryMap['pengeluaran_bahan_makanan'] = [
                'keywords' => [],
                'type' => 'expense',
                'name' => 'Bahan Makanan & Bumbu Dapur',
                'icon' => '🍜',
            ];
        } else {
            $this->categoryMap['pengeluaran_bahan_makanan']['name'] = 'Bahan Makanan & Bumbu Dapur';
            $this->categoryMap['pengeluaran_bahan_makanan']['icon'] = '🍜';
        }

        Log::info('CategoryInference: Map loaded', [
            'categories' => array_keys($this->categoryMap),
            'total_keywords' => array_sum(array_map(fn($c) => count($c['keywords'] ?? []), $this->categoryMap)),
            'otomotif_keywords' => $this->categoryMap['pengeluaran_otomotif']['keywords'] ?? 'NOT FOUND',
        ]);
    }

    public function infer(string $messageText, ?bool $isIncomeHint = null): array
    {
        $this->ensureLoaded();
        $this->messageText = $messageText;
        $this->messageLower = mb_strtolower($messageText);
        $this->pipelineData = [
            'scores' => [],
            'matched_keywords' => [],
        ];

        $intentType = $this->detectIntentType($isIncomeHint);

        $this->matchKeywords($intentType);

        $this->applyContextBoosts($intentType);

        $result = $this->decide($intentType);

        $result = $this->validateWithGemini($result);

        Log::info('CategoryInference: Result v2', [
            'message' => $messageText,
            'category' => $result['category_type'],
            'confidence' => $result['confidence'],
            'source' => $result['source'],
            'matched_keywords' => $result['metadata']['matched_keywords'] ?? [],
        ]);

        return $result;
    }

    protected function detectIntentType(?bool $isIncomeHint = null): string
    {
        if ($isIncomeHint !== null) {
            return $isIncomeHint ? 'income' : 'expense';
        }

        // Delegasikan ke TransactionTypeDetector — satu sumber kebenaran
        // (sebelumnya logika ini terduplikasi dengan urutan cek berbeda,
        //  menyebabkan "Uang lembur" salah jadi expense)
        return app(TransactionTypeDetector::class)->detect($this->messageLower);
    }

    protected function matchKeywords(string $intentType): void
    {
        $text = $this->messageLower;

        foreach ($this->categoryMap as $categoryType => $config) {
            if (($config['type'] ?? 'expense') !== $intentType) continue;

            // Guard: pastikan prefix kategori konsisten dengan intentType.
            // Mencegah "Uang lembur" (income) memenangkan kategori pengeluaran_bahan_makanan
            // lewat context boost atau jalur lain, meski tipe sudah benar income.
            if ($intentType === 'income' && str_starts_with($categoryType, 'pengeluaran_')) continue;
            if ($intentType === 'expense' && str_starts_with($categoryType, 'pendapatan_')) continue;

            $score = 0;
            $matched = [];
            $bestKeywordLen = 0;

            foreach ($config['keywords'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    if (!$this->isValidIndonesianMatch($text, $keyword)) {
                        continue;
                    }
                    $len = mb_strlen($keyword);
                    $keywordScore = 10 + $len;
                    if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $text)) {
                        $keywordScore += 5;
                    }
                    if ($len > $bestKeywordLen) {
                        $bestKeywordLen = $len;
                    }
                    $score += $keywordScore;
                    $matched[] = $keyword;
                }
            }

            if ($score > 0) {
                $this->pipelineData['scores'][$categoryType] = $score;
                $this->pipelineData['matched_keywords'][$categoryType] = $matched;
            }
        }
    }

    protected function isValidIndonesianMatch(string $text, string $keyword): bool
    {
        $keywordLower = mb_strtolower($keyword);
        $len = mb_strlen($keywordLower);
        
        $offset = 0;
        while (($pos = mb_strpos($text, $keywordLower, $offset)) !== false) {
            $before = mb_substr($text, 0, $pos);
            $after = mb_substr($text, $pos + $len);
            
            $wordBefore = '';
            if (preg_match('/([a-zA-Z0-9]+)$/u', $before, $matches)) {
                $wordBefore = $matches[1];
            }
            
            $wordAfter = '';
            if (preg_match('/^([a-zA-Z0-9]+)/u', $after, $matches)) {
                $wordAfter = $matches[1];
            }
            
            if ($wordBefore === '' && $wordAfter === '') {
                return true;
            }
            
            $allowedPrefixes = ['me', 'mem', 'men', 'meng', 'meny', 'di', 'ke', 'ter', 'se', 'pe', 'pem', 'pen', 'peng', 'peny', 'ber', 'be', 'bel'];
            $allowedSuffixes = ['an', 'kan', 'i', 'nya', 'lah', 'kah', 'pun'];
            
            $prefixValid = ($wordBefore === '' || in_array($wordBefore, $allowedPrefixes));
            $suffixValid = ($wordAfter === '' || in_array($wordAfter, $allowedSuffixes));
            
            if ($prefixValid && $suffixValid) {
                if ($len <= 3) {
                    if ($wordBefore === '' && $wordAfter === '') {
                        return true;
                    }
                } else {
                    return true;
                }
            }
            
            $offset = $pos + 1;
        }
        
        return false;
    }

    protected function applyContextBoosts(string $intentType): void
    {
        $text = $this->messageLower;

        $patterns = [
            '/\bbayar\s+(hutang|utang)\b/i' => ['pengeluaran_bayar_hutang', 60],
            '/\bbayar\s+(cicilan|angsuran)\b/i' => ['pengeluaran_cicilan', 55],
            '/\bbayar\s+(asuransi|premi|bpjs)\b/i' => ['pengeluaran_asuransi', 50],
            '/\bbayar\s+(pajak|pph|ppn|pbb)\b/i' => ['pengeluaran_pajak', 55],
            '/\bbayar\s+(pinjaman|pinjol|paylater)\b/i' => ['pengeluaran_pinjaman', 55],
            '/\bbayar\s+(listrik|air|internet|token)\b/i' => ['pengeluaran_utilitas', 40],
            '/\bbayar\s+(sewa|kost|kos)\b/i' => ['pengeluaran_hunian', 40],
            '/\b(servis|service)\s+(motor|mobil)\b/i' => ['pengeluaran_otomotif', 45],
            '/\bganti\s+(oli|ban|aki)\b/i' => ['pengeluaran_otomotif', 45],
            '/\b(beli|kulakan)\s+(stok|stock|bahan baku)\b/i' => ['pengeluaran_modal', 45],
            '/\b(beli|bayar)\s+.*(oli|ban|aki|sparepart|spare part)\b/i' => ['pengeluaran_otomotif', 40],
            '/\b(beli|bayar)\s+.*(makan|kopi|nasi|mie|bakso|sate|gado|pecel|kue|roti|jajan|snack|cemilan)\b/i' => ['pengeluaran_makanan', 35],
            '/\b(beli|bayar)\s+.*(beras|tepung|gula|garam|bumbu|minyak goreng|kecap|santan|bahan masak)\b/i' => ['pengeluaran_bahan_makanan', 40],
            '/\b(indomie|mie sedaap|mie sejati|sarimi|supermi|pop mie)\b/i' => ['pengeluaran_bahan_makanan', 50],
            '/\b(indofood|royco|masako|miwon|penyedap)\b/i' => ['pengeluaran_bahan_makanan', 50],
            '/\b(beli|bayar)\s+.*(popok|susu formula|susu bayi|susu baby|susu anak|stroller|baju bayi|mainan|dot|teether|mpasi|bubur bayi|pampers|diapers)\b/i' => ['pengeluaran_baby', 45],
            '/\b(fitti|fitti pants|merries|mamypoko|mamy poko|huggies|sweety|genki|goon)\b/i' => ['pengeluaran_baby', 55],
            '/\b(zwitsal|pigeon|mustela|cussons baby|cb baby|my baby|little baby|little me)\b/i' => ['pengeluaran_baby', 55],
            '/\b(chil kid|chil mil|promina|milna|bebelac|enfagrow|enfamil|nutrilon|pediasure|sgm|morinaga|lactogen|cerelac|gerber)\b/i' => ['pengeluaran_baby', 55],
            '/sabun\s+(cuci\s+)?(botol\s+)?bayi/i' => ['pengeluaran_baby', 50],
            '/\b(beli|bayar)\s+.*(pakan|kucing|anjing|whiskas|royal canin|pet shop|dokter hewan|grooming)\b/i' => ['pengeluaran_hewan', 45],
            '/\b(dettol|lifebuoy|lux|dove|biore|nuvo|citra)\b/i' => ['pengeluaran_perawatan_diri', 50],
            '/\b(sunsilk|pantene|rejoice|tresemme|makarizo|emeron)\b/i' => ['pengeluaran_perawatan_diri', 50],
            '/\b(ovale|garnier|wardah|emina|somethinc|skintific|ms glow|scarlett|cetaphil|nivea|vaseline)\b/i' => ['pengeluaran_perawatan_diri', 50],
            '/\b(softex|charm|laurier|kotex|pantyliners|pembalut)\b/i' => ['pengeluaran_perawatan_diri', 50],
            '/\b(salonpas|koyo|balsem)\b/i' => ['pengeluaran_perawatan_diri', 50],
            '/\b(rokok|vape|liquid vape|iqos|marlboro|gudang garam|djarum|sampoerna)\b/i' => ['pengeluaran_gaya_hidup', 60],
            '/\b(gym|fitness|pijat|spa|massage|refleksi)\b/i' => ['pengeluaran_gaya_hidup', 50],
            '/\b(extra joss|kratingdaeng|red bull|energy drink|minuman energi)\b/i' => ['pengeluaran_gaya_hidup', 50],
            '/\b(nutrive|benecol|anlene|ensure|diabetasol|entrasol)\b/i' => ['pengeluaran_kesehatan', 55],
            '/\b(beli|bayar)\s+.*(hp|laptop|charger|earphone|headset|powerbank|kamera|tablet|smartwatch|airpods)\b/i' => ['pengeluaran_gadget', 45],
            '/\btransfer\s+(masuk|dari)\b/i' => ['pendapatan_transfer', 35],
            '/\btransfer\s+(keluar|ke)\b/i' => ['pengeluaran_transfer', 35],
            '/\bterima\s+(gaji|honor)\b/i' => ['pendapatan_gaji', 55],
            '/\bterima\s+(piutang|pelunasan)\b/i' => ['pendapatan_terima_piutang', 50],
        ];

        foreach ($patterns as $pattern => [$category, $boost]) {
            if (preg_match($pattern, $text)) {
                $old = $this->pipelineData['scores'][$category] ?? 0;
                $this->pipelineData['scores'][$category] = $old + $boost;
            }
        }

        if ($intentType === 'expense') {
            if (str_contains($text, 'kasih') && (str_contains($text, 'undangan') || str_contains($text, 'hajatan'))) {
                $old = $this->pipelineData['scores']['pengeluaran_acara'] ?? 0;
                $this->pipelineData['scores']['pengeluaran_acara'] = $old + 50;
            }
            if (str_contains($text, 'kasih') && (str_contains($text, 'amplop') || str_contains($text, 'sumbangan'))) {
                $old = $this->pipelineData['scores']['pengeluaran_sosial'] ?? 0;
                $this->pipelineData['scores']['pengeluaran_sosial'] = $old + 45;
            }
            if (str_contains($text, 'kasih') && (str_contains($text, 'gaji') || str_contains($text, 'upah'))) {
                $old = $this->pipelineData['scores']['pengeluaran_gaji'] ?? 0;
                $this->pipelineData['scores']['pengeluaran_gaji'] = $old + 55;
            }
        }
    }

    protected function decide(string $intentType): array
    {
        $scores = $this->pipelineData['scores'];

        if (empty($scores)) {
            $default = $intentType === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
            return $this->buildResult($default, 0.3, 'fallback_default');
        }

        arsort($scores);
        $topCategory = array_key_first($scores);
        $topScore = $scores[$topCategory];
        $totalScore = array_sum($scores);
        $confidence = max(0.3, min(0.99, $topScore / $totalScore));

        if ($confidence < 0.4) {
            $default = $intentType === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
            return $this->buildResult($default, $confidence, 'low_confidence_fallback');
        }

        return $this->buildResult($topCategory, $confidence, 'keyword_match');
    }

    protected function buildResult(string $categoryType, float $confidence, string $source): array
    {
        $config = $this->categoryMap[$categoryType] ?? [];
        $nameMap = config('finwa_category_rules.category_names', []);

        return [
            'category_type' => $categoryType,
            'category_name' => $nameMap[$categoryType] ?? $config['name'] ?? $categoryType,
            'category_icon' => $config['icon'] ?? '📝',
            'type' => $config['type'] ?? 'expense',
            'confidence' => $confidence,
            'source' => $source,
            'entities' => $this->pipelineData['entities'] ?? [],
            'metadata' => [
                'all_scores' => $this->pipelineData['scores'] ?? [],
                'matched_keywords' => $this->pipelineData['matched_keywords'] ?? [],
            ],
        ];
    }

    protected function validateWithGemini(array $result): array
    {
        if (!config('finwa_category_rules.gemini_validation_enabled', false)) {
            return $result;
        }

        if ($result['source'] === 'debt_flow_detection') {
            return $result;
        }

        if ($result['confidence'] >= 0.85 && $result['source'] === 'keyword_match') {
            return $result;
        }

        try {
            $geminiService = app(\App\Services\GeminiAIService::class);

            if (!$geminiService->isAvailable()) {
                return $result;
            }

            $geminiResult = $geminiService->validateCategory(
                $this->messageText,
                $result['type'],
                $result['category_type'],
                $this->categoryMap
            );

            if (!$geminiResult) {
                return $result;
            }

            if ($geminiResult['corrected']) {
                $newType = $geminiResult['category_type'];
                $newConfig = $this->categoryMap[$newType] ?? null;

                if (!$newConfig) {
                    foreach ($this->categoryMap as $type => $config) {
                        if (mb_strtolower($config['name'] ?? '') === mb_strtolower($newType) ||
                            str_contains(mb_strtolower($newType), mb_strtolower($type))) {
                            $newType = $type;
                            $newConfig = $config;
                            break;
                        }
                    }
                }

                if ($newConfig) {
                    $nameMap = config('finwa_category_rules.category_names', []);
                    return [
                        'category_type' => $newType,
                        'category_name' => $nameMap[$newType] ?? $newConfig['name'] ?? $newType,
                        'category_icon' => $newConfig['icon'] ?? '📝',
                        'type' => $newConfig['type'] ?? $result['type'],
                        'confidence' => $geminiResult['confidence'],
                        'source' => 'gemini_ai_validation',
                        'entities' => $result['entities'],
                        'metadata' => array_merge($result['metadata'] ?? [], [
                            'gemini_correction' => [
                                'original_category' => $result['category_type'],
                                'reason' => $geminiResult['reason'],
                            ],
                        ]),
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::error('CategoryInference: Gemini validation failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }
}
