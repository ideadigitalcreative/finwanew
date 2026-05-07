<?php

namespace App\Services\Category;

class CategoryMappingService
{
    protected ?CategoryCorrectionService $correctionService;

    public function __construct(?CategoryCorrectionService $correctionService = null)
    {
        $this->correctionService = $correctionService;
    }

    /**
     * Normalize text before category matching.
     * Strips punctuation, collapses whitespace, trims.
     */
    protected static function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\w\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Map FinWa-AI kategori to system category_type format
     *
     * @param  string|null  $kategori  The kategori from FinWa-AI (e.g., 'makan', 'transport', 'gaji')
     * @param  bool  $isIncome  Whether this is an income transaction
     * @return string The mapped category_type (e.g., 'pengeluaran_makanan', 'pendapatan_gaji')
     *
     * MOVED FROM: ProcessIncomingMessage::mapFinwaKategoriToCategoryType()
     * LINES: 4966-5101
     * MODIFICATION: None (structural move only)
     */
    public function mapFinwaKategoriToCategoryType(?string $kategori, bool $isIncome): string
    {
        if (empty($kategori)) {
            return $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
        }

        $kategoriLower = strtolower(trim($kategori));

        $aiMap = config('finwa_category_rules.ai_category_map', []);

        if (isset($aiMap[$kategoriLower])) {
            $candidates = (array) $aiMap[$kategoriLower];
            foreach ($candidates as $candidate) {
                if ($isIncome && str_starts_with($candidate, 'pendapatan_')) {
                    return $candidate;
                }
                if (! $isIncome && str_starts_with($candidate, 'pengeluaran_')) {
                    return $candidate;
                }
            }
            if (! empty($candidates)) {
                return $candidates[0];
            }
        }

        $keywordMap = $isIncome
            ? config('finwa_category_rules.income_keywords', [])
            : config('finwa_category_rules.expense_keywords', []);

        $weightedMatch = self::resolveCategoryByWeightedMatch($kategoriLower, $keywordMap);
        if ($weightedMatch !== null) {
            return $weightedMatch;
        }

        $reverseMap = [];
        $kategoriNormalized = self::normalizeText($kategoriLower);
        foreach ($keywordMap as $keyword => $categoryType) {
            $kwNormalized = self::normalizeText($keyword);
            if (str_contains($kwNormalized, $kategoriNormalized) && $kategoriNormalized !== $kwNormalized) {
                $reverseMap[$keyword] = $categoryType;
            }
        }
        if (! empty($reverseMap)) {
            $reverseMatch = self::resolveCategoryByWeightedMatch($kategoriLower, $reverseMap);
            if ($reverseMatch !== null) {
                return $reverseMatch;
            }

            return reset($reverseMap);
        }

        return $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
    }

    /**
     * Determine category from description text
     *
     * MOVED FROM: ProcessIncomingMessage::determineCategoryFromDescription()
     * REFACTORED: Now delegates to resolveCategory() which reads from config.
     *
     * @param  string  $description  Transaction description
     * @param  string|null  $merchant  Optional merchant name for better accuracy
     * @param  int|null  $amount  Optional amount for nominal disambiguation
     * @param  int|null  $tenantId  Optional tenant ID for feedback loop
     */
    public function determineCategoryFromDescription(string $description, ?string $merchant = null, ?int $amount = null, ?int $tenantId = null): string
    {
        return $this->resolveCategory($description, false, null, $merchant, $amount, $tenantId);
    }

    /**
     * Determine category from description text (extended version)
     *
     * MOVED FROM: ProcessIncomingMessage::determineCategoryFromText()
     * REFACTORED: Now delegates to resolveCategory() which reads from config.
     *
     * @param  string  $description  Transaction description
     * @param  bool  $isIncome  Whether income
     * @param  string|null  $merchant  Optional merchant name for better accuracy
     * @param  int|null  $amount  Optional amount for nominal disambiguation
     * @param  int|null  $tenantId  Optional tenant ID for feedback loop
     */
    public function determineCategoryFromText(string $description, bool $isIncome = false, ?string $merchant = null, ?int $amount = null, ?int $tenantId = null): string
    {
        return $this->resolveCategory($description, $isIncome, null, $merchant, $amount, $tenantId);
    }

    /**
     * Resolve category using weighted keyword matching.
     *
     * Automatically prioritizes longer phrase matches over shorter ones,
     * regardless of the order in which keywords are provided.
     *
     * This eliminates the "first-match-wins" bug where "bayar" (1 word)
     * could incorrectly override "bayar makan siang" (3 words) simply
     * because it appeared earlier in the mapping array.
     *
     * @param  string  $text  The input text (description/message)
     * @param  array  $keywordMap  Associative array of keyword => category
     * @return string|null The matched category, or null if no match
     */
    public static function resolveCategoryByWeightedMatch(string $text, array $keywordMap): ?string
    {
        $textNormalized = self::normalizeText($text);
        if (empty($textNormalized)) {
            return null;
        }

        $bestMatch = null;
        $bestLength = 0;

        foreach ($keywordMap as $keyword => $category) {
            $keywordNormalized = self::normalizeText($keyword);
            if (str_contains($textNormalized, $keywordNormalized)) {
                $len = mb_strlen($keywordNormalized);
                if ($len > $bestLength) {
                    $bestLength = $len;
                    $bestMatch = $category;
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Resolve category from merchant name.
     *
     * Looks up merchant/brand name in config('merchant_categories.merchants')
     * and returns the mapped category_type.
     *
     * @param  string  $merchant  The merchant name (e.g., 'KFC', 'Indomaret', 'Grab')
     * @return string|null The category_type, or null if no merchant match
     */
    public static function resolveByMerchant(string $merchant): ?string
    {
        if (empty(trim($merchant))) {
            return null;
        }

        $merchantLower = strtolower(trim($merchant));
        $merchantMap = config('merchant_categories.merchants', []);

        return self::resolveCategoryByWeightedMatch($merchantLower, $merchantMap);
    }

    /**
     * Resolve category from nominal amount as disambiguation signal.
     *
     * When the primary keyword match yields a generic/ambiguous category
     * (e.g., 'pengeluaran_belanja', 'pengeluaran_lainnya'), the nominal
     * amount can help refine the category.
     *
     * @param  string  $text  The original text for context
     * @param  int  $amount  The transaction amount
     * @param  string  $currentCategory  The current (possibly generic) category
     * @return string The refined category (or same if no refinement)
     */
    public static function resolveByNominal(string $text, int $amount, string $currentCategory): string
    {
        if ($amount <= 0) {
            return $currentCategory;
        }

        $textLower = strtolower(trim($text));

        $genericCategories = [
            'pengeluaran_belanja',
            'pengeluaran_lainnya',
            'pendapatan_lainnya',
        ];

        if (! in_array($currentCategory, $genericCategories, true)) {
            return $currentCategory;
        }

        $hasBayarBeli = str_contains($textLower, 'bayar') || str_contains($textLower, 'beli')
            || str_contains($textLower, 'belanja');

        if (! $hasBayarBeli) {
            return $currentCategory;
        }

        if ($amount >= 500000) {
            $billPatterns = ['tagihan', 'cicilan', 'angsuran', 'kredit', 'kpr', 'sewa', 'kos', 'listrik', 'air', 'internet', 'wifi', 'bpjs', 'asuransi', 'pajak'];
            foreach ($billPatterns as $pattern) {
                if (str_contains($textLower, $pattern)) {
                    return 'pengeluaran_tagihan';
                }
            }
            if (str_contains($textLower, 'bayar') && ! str_contains($textLower, 'makan') && ! str_contains($textLower, 'beli')) {
                return 'pengeluaran_tagihan';
            }
        }

        if ($amount <= 15000 && $currentCategory === 'pengeluaran_belanja') {
            return 'pengeluaran_makanan';
        }

        return $currentCategory;
    }

    public static function getCategoryConfidence(string $method, int $matchLength = 0, string $text = ''): float
    {
        switch ($method) {
            case 'learned':
                return 0.95;
            case 'ai':
                return 0.90;
            case 'merchant':
                return 0.85;
            case 'keyword_exact':
                return 0.80;
            case 'keyword_partial':
                $lenRatio = $matchLength > 0 ? min($matchLength / max(mb_strlen($text), 1), 1.0) : 0.3;

                return round(0.50 + ($lenRatio * 0.25), 2);
            case 'nominal_refined':
                return 0.55;
            case 'default':
            default:
                return 0.30;
        }
    }

    /**
     * Unified category resolver — single source of truth.
     *
     * Resolution priority:
     *   0. User feedback loop (learned corrections — highest priority)
     *   1. AI category (if provided with high confidence)
     *   2. Merchant-based lookup (if merchant is known)
     *   3. Weighted keyword matching from config
     *   4. Nominal-based disambiguation (refine generic categories)
     *   5. Default category
     *
     * @param  string  $text  Input text (description/message)
     * @param  bool  $isIncome  Whether transaction is income
     * @param  string|null  $aiCategory  Category from AI (optional, used as high-confidence override)
     * @param  string|null  $merchant  Merchant name (optional)
     * @param  int|null  $amount  Transaction amount (optional, for nominal disambiguation)
     * @param  int|null  $tenantId  Tenant ID (optional, for feedback loop lookup)
     * @return string Resolved category_type
     */
    public function resolveCategory(string $text, bool $isIncome, ?string $aiCategory = null, ?string $merchant = null, ?int $amount = null, ?int $tenantId = null): string
    {
        $text = self::normalizeText($text);

        // Step 0: Check learned corrections from user feedback
        if ($tenantId !== null && $this->correctionService !== null) {
            $learned = $this->correctionService->getLearnedCategory($tenantId, $text, $merchant);
            if ($learned !== null) {
                return $learned;
            }
        }

        // Step 1: AI category (highest confidence)
        if (! empty($aiCategory)) {
            $aiMap = config('finwa_category_rules.ai_category_map', []);
            $aiLower = strtolower(trim($aiCategory));
            if (isset($aiMap[$aiLower])) {
                $candidates = $aiMap[$aiLower];
                foreach ($candidates as $candidate) {
                    if ($isIncome && str_starts_with($candidate, 'pendapatan_')) {
                        return $candidate;
                    }
                    if (! $isIncome && str_starts_with($candidate, 'pengeluaran_')) {
                        return $candidate;
                    }
                }
                if (! empty($candidates)) {
                    return $candidates[0];
                }
            }
        }

        // Step 2: Merchant-based resolution
        if (! empty($merchant) && ! $isIncome) {
            $merchantCategory = self::resolveByMerchant($merchant);
            if ($merchantCategory !== null) {
                return $merchantCategory;
            }
        }

        // Also check merchant keywords in the text itself
        if (! $isIncome) {
            $merchantFromText = self::resolveByMerchant($text);
            if ($merchantFromText !== null) {
                return $merchantFromText;
            }
        }

        // Step 3: Weighted keyword matching from config
        $keywordMap = $isIncome
            ? config('finwa_category_rules.income_keywords', [])
            : config('finwa_category_rules.expense_keywords', []);

        $match = self::resolveCategoryByWeightedMatch($text, $keywordMap);
        if ($match !== null) {
            // Step 4: Nominal-based refinement for generic categories
            if ($amount !== null && ! $isIncome) {
                $match = self::resolveByNominal($text, $amount, $match);
            }

            return $match;
        }

        // Step 4b: Nominal-based fallback for unmatched text
        if ($amount !== null && ! $isIncome) {
            $default = 'pengeluaran_lainnya';

            return self::resolveByNominal($text, $amount, $default);
        }

        // Step 5: Default
        return $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
    }

    public function resolveCategoryWithConfidence(string $text, bool $isIncome, ?string $aiCategory = null, ?string $merchant = null, ?int $amount = null, ?int $tenantId = null): array
    {
        if ($tenantId !== null && $this->correctionService !== null) {
            $learned = $this->correctionService->getLearnedCategory($tenantId, $text, $merchant);
            if ($learned !== null) {
                return ['category' => $learned, 'confidence' => self::getCategoryConfidence('learned')];
            }
        }

        if (! empty($aiCategory)) {
            $aiMap = config('finwa_category_rules.ai_category_map', []);
            $aiLower = strtolower(trim($aiCategory));
            if (isset($aiMap[$aiLower])) {
                $candidates = $aiMap[$aiLower];
                foreach ($candidates as $candidate) {
                    if ($isIncome && str_starts_with($candidate, 'pendapatan_')) {
                        return ['category' => $candidate, 'confidence' => self::getCategoryConfidence('ai')];
                    }
                    if (! $isIncome && str_starts_with($candidate, 'pengeluaran_')) {
                        return ['category' => $candidate, 'confidence' => self::getCategoryConfidence('ai')];
                    }
                }
                if (! empty($candidates)) {
                    return ['category' => $candidates[0], 'confidence' => self::getCategoryConfidence('ai')];
                }
            }
        }

        if (! empty($merchant) && ! $isIncome) {
            $merchantCategory = self::resolveByMerchant($merchant);
            if ($merchantCategory !== null) {
                return ['category' => $merchantCategory, 'confidence' => self::getCategoryConfidence('merchant')];
            }
        }

        if (! $isIncome) {
            $merchantFromText = self::resolveByMerchant($text);
            if ($merchantFromText !== null) {
                return ['category' => $merchantFromText, 'confidence' => self::getCategoryConfidence('merchant')];
            }
        }

        $keywordMap = $isIncome
            ? config('finwa_category_rules.income_keywords', [])
            : config('finwa_category_rules.expense_keywords', []);

        $match = self::resolveCategoryByWeightedMatch($text, $keywordMap);
        if ($match !== null) {
            $matchLen = mb_strlen(explode(' → ', $match)[0] ?? '');
            $isExact = isset($keywordMap[mb_strtolower(trim($text))]);
            $method = $isExact ? 'keyword_exact' : 'keyword_partial';
            $confidence = self::getCategoryConfidence($method, $matchLen, $text);
            if ($amount !== null && ! $isIncome) {
                $refined = self::resolveByNominal($text, $amount, $match);
                if ($refined !== $match) {
                    return ['category' => $refined, 'confidence' => self::getCategoryConfidence('nominal_refined')];
                }
            }

            return ['category' => $match, 'confidence' => $confidence];
        }

        if ($amount !== null && ! $isIncome) {
            $default = 'pengeluaran_lainnya';
            $refined = self::resolveByNominal($text, $amount, $default);
            if ($refined !== $default) {
                return ['category' => $refined, 'confidence' => self::getCategoryConfidence('nominal_refined')];
            }
        }

        $default = $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';

        return ['category' => $default, 'confidence' => self::getCategoryConfidence('default')];
    }
}
