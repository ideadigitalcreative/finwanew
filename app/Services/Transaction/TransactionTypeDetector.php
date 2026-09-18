<?php

namespace App\Services\Transaction;

/**
 * Satu sumber kebenaran untuk deteksi tipe transaksi (income vs expense).
 *
 * Sebelumnya logika ini terduplikasi di TransactionExtractorService dan
 * CategoryInferenceService dengan urutan cek yang berbeda, menyebabkan bug:
 * - "Uang lembur pondok cabe 1,5 juta" terdeteksi expense oleh CategoryInferenceService
 *   padahal TransactionExtractorService sudah benar mendeteksi income.
 *
 * Service ini menerapkan aturan prioritas berbasis posisi (posisi income keyword
 * di awal pesan menang atas expense pattern), sebagaimana diformalkan di
 * .kiro/specs/income-detection-priority-fix/bugfix.md.
 */
class TransactionTypeDetector
{
    /**
     * Deteksi tipe transaksi dari teks.
     *
     * Urutan aturan (prioritas menurun):
     *   1. Income keyword di AWAL pesan (position-based) → income
     *   2. Expense override pattern / kata "bayar"        → expense
     *   3. Income keyword via word boundary               → income
     *   4. Default                                        → expense
     */
    public function detect(string $text): string
    {
        $textLower = mb_strtolower(trim($text));
        $incomeKeywords = config('finwa_category_rules.income_detection_keywords', []);

        // Aturan 1 — prioritas posisi (selaras TransactionExtractorService)
        // Keyword income yang muncul di awal pesan memiliki prioritas tertinggi.
        foreach ($incomeKeywords as $keyword) {
            if (! str_starts_with($textLower, $keyword)) {
                continue;
            }

            $after = strlen($keyword);
            if ($after >= strlen($textLower)
                || $textLower[$after] === ' '
                || ctype_digit($textLower[$after])
            ) {
                return 'income';
            }
        }

        // Aturan 2 — expense override
        $isExpenseOverride = false;
        foreach (config('finwa_category_rules.expense_detection_patterns', []) as $pattern) {
            if (str_contains($textLower, $pattern)) {
                $isExpenseOverride = true;
                break;
            }
        }
        if (! $isExpenseOverride && preg_match('/\bbayar\b/u', $textLower)) {
            $isExpenseOverride = true;
        }
        if ($isExpenseOverride) {
            return 'expense';
        }

        // Aturan 3 — income via word boundary
        foreach ($incomeKeywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $textLower)) {
                return 'income';
            }
        }

        // Aturan 4 — default: expense
        return 'expense';
    }
}
