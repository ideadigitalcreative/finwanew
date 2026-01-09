<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class TransactionCategoryHelper
{
    /**
     * Determine if the transaction is an expense based on text and intent.
     * Incorporates override logic for specific phrases like "ambil gaji".
     */
    public static function isExpense(string $text, string $intent = null): bool
    {
        $textLower = strtolower($text);
        
        Log::info('TransactionCategoryHelper::isExpense Check', [
            'text' => $text,
            'intent' => $intent
        ]);
        
        // PRIORITY 0: Check expense override patterns FIRST
        // Using Regex for more robust matching (handles multiple spaces)
        $expenseOverridePatterns = [
            '/ambil\s+gaji/', '/ambil\s+upah/', '/ambil\s+honor/', 
            '/sudah\s+ambil/', '/ngambil\s+gaji/', '/bayar\s+gaji/',
            '/kasih\s+gaji/', '/potong\s+gaji/'
        ];
        
        foreach ($expenseOverridePatterns as $pattern) {
            if (preg_match($pattern, $textLower)) {
                Log::info('TransactionCategoryHelper: Expense override matched', ['pattern' => $pattern]);
                return true;
            }
        }
        
        // PRIORITY 1: Check intent
        if ($intent === 'catat_pemasukan') {
            Log::info('TransactionCategoryHelper: Intent is catat_pemasukan');
            return false;
        }
        
        if ($intent === 'catat_pengeluaran') {
            return true;
        }
        
        // PRIORITY 2: Check context keywords
        $incomeKeywords = [
             'gaji', 'bonus', 'terima', 'dapat', 'dapet', 
             'pemasukan', 'pendapatan', 'honor', 'upah', 
             'masuk', 'uang masuk', 'duit masuk', 'transfer masuk'
        ];
        
        foreach ($incomeKeywords as $keyword) {
            // Check exact word match or phrase match
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $textLower) || 
                str_contains($textLower, $keyword)) {
                Log::info('TransactionCategoryHelper: Income keyword matched', ['keyword' => $keyword]);
                return false;
            }
        }
        
        // Default to expense if not identified as income
        return true;
    }

    /**
     * Map FinWa intent/category to system category type.
     */
    public static function determineCategoryType(string $text, ?string $finwaCategory, bool $isIncome): string
    {
        $textLower = strtolower($text);
        $finwaCategory = strtolower($finwaCategory ?? '');
        
        Log::info('TransactionCategoryHelper::determineCategoryType Check', [
            'text' => $text,
            'finwaCategory' => $finwaCategory,
            'isIncome' => $isIncome
        ]);
        
        // 1. Check for specific overrides based on text content
        if (!$isIncome) {
            // Salary expense override
            if (str_contains($textLower, 'gaji') || 
                str_contains($textLower, 'upah') || 
                str_contains($textLower, 'honor')) {
                Log::info('TransactionCategoryHelper: Salary Expense Override matched -> pengeluaran_gaji');
                return 'pengeluaran_gaji';
            }
        }

        // 2. Map standard FinWa categories
        if ($finwaCategory) {
            $mapped = self::mapFinwaCategory($finwaCategory, $isIncome);
            if ($mapped) {
                Log::info('TransactionCategoryHelper: Mapped from FinWa category', ['mapped' => $mapped]);
                return $mapped;
            }
        }
        
        // 3. Fallback based on keywords in text
        $fallback = self::inferCategoryFromText($textLower, $isIncome);
        if ($fallback) {
            return $fallback;
        }

        $default = $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
        Log::info('TransactionCategoryHelper: Using default', ['default' => $default]);
        return $default;
    }

    protected static function mapFinwaCategory(string $category, bool $isIncome): ?string
    {
        if ($isIncome) {
            $map = [
                'gaji' => 'pendapatan_gaji',
                'bonus' => 'pendapatan_bonus',
                'investasi' => 'pendapatan_investasi',
            ];
        } else {
            $map = [
                'makan' => 'pengeluaran_makanan',
                'transport' => 'pengeluaran_transport',
                'belanja' => 'pengeluaran_belanja',
                'keluarga' => 'pengeluaran_keluarga',
                'gaji' => 'pengeluaran_gaji', // Map if AI returns 'gaji' for expense
            ];
        }

        return $map[$category] ?? null;
    }

    protected static function inferCategoryFromText(string $text, bool $isIncome): ?string
    {
        // Simple fallback inference if AI category is missing
        if (!$isIncome) {
            if (str_contains($text, 'makan') || str_contains($text, 'minum')) return 'pengeluaran_makanan';
            if (str_contains($text, 'bensin') || str_contains($text, 'ojek')) return 'pengeluaran_transport';
        }
        return null; // Let the caller use default
    }
}
