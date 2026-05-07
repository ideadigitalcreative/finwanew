<?php

namespace App\Services\Transaction;

use App\Models\Balance;
use App\Models\Category;
use App\Services\Category\CategoryMappingService;
use Carbon\Carbon;

class TransactionParserService
{
    protected TransactionExtractorService $extractor;

    protected CategoryMappingService $categoryMapper;

    public function __construct(
        TransactionExtractorService $extractor,
        CategoryMappingService $categoryMapper
    ) {
        $this->extractor = $extractor;
        $this->categoryMapper = $categoryMapper;
    }

    public function parse(string $text, int $tenantId): array
    {
        $text = trim($text);

        if (empty($text)) {
            return ['success' => false, 'error' => 'Teks tidak boleh kosong'];
        }

        $amount = $this->extractor->extractAmountFromText($text);

        if (! $amount || $amount <= 0) {
            return ['success' => false, 'error' => 'Nominal tidak terdeteksi'];
        }

        $description = $this->extractor->extractDescriptionFromLine($text);
        $isIncome = $this->detectType($text);
        $type = $isIncome ? 'income' : 'expense';

        $categoryResult = $this->categoryMapper->resolveCategoryWithConfidence(
            $description,
            $isIncome,
            null,
            null,
            $amount,
            $tenantId
        );

        $categoryType = $categoryResult['category'];
        $confidence = $categoryResult['confidence'];

        $category = Category::where('tenant_id', $tenantId)
            ->where('type', $categoryType)
            ->first();

        $transactionDate = $this->extractor->extractDateFromText($text)
            ?? Carbon::now('Asia/Jakarta')->toDateString();

        $balances = Balance::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'account_name', 'account_type', 'balance', 'currency']);

        $defaultBalance = $balances->first();

        $alternatives = [];
        if ($confidence < 0.7) {
            $keywordMap = $isIncome
                ? config('finwa_category_rules.income_keywords', [])
                : config('finwa_category_rules.expense_keywords', []);

            $allCategories = Category::where('tenant_id', $tenantId)
                ->where('type', 'like', $isIncome ? 'pendapatan_%' : 'pengeluaran_%')
                ->get(['id', 'type', 'name', 'icon', 'color']);

            $alternatives = $allCategories
                ->filter(fn ($c) => $c->type !== $categoryType)
                ->values()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'type' => $c->type,
                    'name' => $c->name,
                    'icon' => $c->icon,
                ])
                ->toArray();
        }

        return [
            'success' => true,
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'category_id' => $category?->id,
            'category_type' => $categoryType,
            'category_name' => $category?->name ?? $this->formatCategoryName($categoryType),
            'category_icon' => $category?->icon ?? ($isIncome ? '💵' : '💸'),
            'date' => $transactionDate,
            'confidence' => round($confidence, 2),
            'default_balance_id' => $defaultBalance?->id,
            'balances' => $balances->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->account_name,
                'type' => $b->account_type,
                'balance' => (float) $b->balance,
                'currency' => $b->currency,
            ])->toArray(),
            'alternatives' => $alternatives,
        ];
    }

    protected function detectType(string $text): bool
    {
        $textLower = mb_strtolower($text);

        $expenseOverridePatterns = [
            'beli', 'belanja', 'jajan', 'order', 'pesan', 'pesen', 'checkout',
            'bayar', 'bayar gaji', 'bayar thr', 'ambil gaji',
            'abis duit', 'habis duit', 'keluar duit', 'abis uang', 'habis uang', 'keluar uang',
            'ngeluarin', 'keluarin', 'abis buat', 'habis buat',
        ];

        foreach ($expenseOverridePatterns as $pattern) {
            if (str_contains($textLower, $pattern)) {
                return false;
            }
        }

        $incomeKeywords = [
            'gaji', 'bonus', 'pemasukan', 'pendapatan', 'honor', 'upah',
            'dikasih', 'dikasi', 'hadiah', 'angpao', 'kiriman', 'kado',
            'terima gaji', 'terima transfer', 'terima pembayaran',
            'uang masuk', 'duit masuk', 'masuk pembayaran', 'income',
            'dari papi', 'dari papa', 'dari mama', 'dari mami',
            'dari ortu', 'dari ayah', 'dari ibu', 'dari bapak',
            'dari suami', 'dari istri', 'dari pacar',
        ];

        foreach ($incomeKeywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $textLower)) {
                return true;
            }
        }

        if (preg_match('/\b(thr)\b/i', $textLower)) {
            return true;
        }

        if (preg_match('/di\s*(kasih|kasi|kirimin?|transfer)/i', $textLower)) {
            return true;
        }

        return false;
    }

    protected function formatCategoryName(string $type): string
    {
        $name = str_replace(['pendapatan_', 'pengeluaran_'], '', $type);

        return ucwords(str_replace('_', ' ', $name));
    }
}
