<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * Helper untuk batch transaction - detect dan sum multiple amounts
 */
class BatchTransactionHelper
{
    /**
     * Check if text contains multiple amounts (batch transaction)
     */
    public static function isBatchTransaction(string $text): bool
    {
        // Skip if empty
        if (empty(trim($text))) {
            return false;
        }

        $textLower = strtolower($text);

        // INDICATORS THAT THIS IS A SINGLE RECEIPT (NOT A BATCH)
        // If it contains receipt keywords, it's likely a single transaction with multiple items
        $receiptKeywords = ['total', 'subtotal', 'tunai', 'kembali', 'change', 'grand total', 'pajak', 'tax', 'diskon', 'discount'];
        foreach ($receiptKeywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $textLower)) {
                return false;
            }
        }

        // Indicators of batch transaction:
        // 1. Multiple lines with dashes/bullets
        // 2. Multiple amounts found
        // 3. Contains list markers (-, *, •, numbers)

        $hasListMarkers = preg_match('/^\s*[-*•]\s+/m', $text) || preg_match('/^\s*\d+[.)]/m', $text);

        $amounts = self::extractAllAmounts($text);
        $hasMultipleAmounts = count($amounts) >= 2;

        // Check for line-by-line amount pattern
        // Valid batch usually has: [Description] [Amount] per line
        $lines = preg_split('/[\r\n]+/', trim($text));
        $linesWithAmount = 0;
        foreach ($lines as $line) {
            if (self::extractSingleAmount($line) > 0) {
                $linesWithAmount++;
            }
        }

        // Only consider it a batch if:
        // 1. Has explicit list markers AND multiple amounts
        // 2. Has multiple lines AND each line looks like a transaction (Description + Amount)
        // AND doesn't look like a single receipt (checked above)

        if ($hasListMarkers && $hasMultipleAmounts) {
            return true;
        }

        // For non-marked lists, we need at least 2 distinct lines that each contain a valid amount
        // AND the number of amounts found should roughly match the number of lines with amounts
        if (count($lines) >= 2 && $linesWithAmount >= 2 && $hasMultipleAmounts) {
            // Additional guard: if it's a very long text (like raw OCR), be more skeptical
            if (strlen($text) > 500) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Extract ALL amounts from text (for batch transactions)
     * Returns array of amounts
     */
    public static function extractAllAmounts(string $text): array
    {
        $amounts = [];
        $textLower = strtolower($text);

        // Pattern 0 (HIGHEST PRIORITY): Rp-prefixed amounts with dot separators
        // e.g., "Rp 13.730.000", "Rp 2.636.000", "Rp. 50.000"
        // Must be checked FIRST to avoid misinterpreting product dimensions ("100 X 50") as amounts
        if (preg_match_all('/rp\.?\s*(\d{1,3}(?:\.\d{3})+)/i', $text, $rpDotMatches)) {
            foreach ($rpDotMatches[1] as $match) {
                $amounts[] = (int) str_replace('.', '', $match);
            }
        }

        // Pattern 0b: Rp-prefixed amounts with suffix (e.g., "Rp 50rb", "Rp 1,5jt")
        if (empty($amounts) && preg_match_all('/rp\.?\s*(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)/i', $textLower, $rpSufMatches, PREG_SET_ORDER)) {
            foreach ($rpSufMatches as $match) {
                $num = floatval(str_replace(',', '.', $match[1]));
                $suffix = strtolower($match[2]);
                $multipliers = [
                    'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                    'jt' => 1000000, 'juta' => 1000000,
                ];
                $val = (int) ($num * ($multipliers[$suffix] ?? 1));
                if ($val > 0) {
                    $amounts[] = $val;
                }
            }
        }

        // If Rp-prefixed amounts found, return them (skip non-Rp patterns to avoid double-counting)
        if (! empty($amounts)) {
            return $amounts;
        }

        // Pattern 1: Numbers with suffix (10k, 5rb, 2jt)
        if (preg_match_all('/\b(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)\b/i', $textLower, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $num = floatval(str_replace(',', '.', $match[1]));
                $suffix = strtolower($match[2]);

                $multipliers = [
                    'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                    'jt' => 1000000, 'juta' => 1000000,
                ];

                $val = (int) ($num * ($multipliers[$suffix] ?? 1));
                if ($val > 0) {
                    $amounts[] = $val;
                }
            }
        }

        // Pattern 2: Plain numbers with dots (10.000, 25.000)
        if (empty($amounts) && preg_match_all('/(\d{1,3}(?:\.\d{3})+)/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $amounts[] = (int) str_replace('.', '', $match);
            }
        }

        // Pattern 2b: Numbers with space-separated thousands (75 000, 1 500 000)
        if (empty($amounts) && preg_match_all('/(\d{1,3}(?:\s+\d{3})+)/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $amounts[] = (int) str_replace(' ', '', $match);
            }
        }

        return $amounts;
    }

    /**
     * Extract amount with batch support
     * If batch detected, sum all amounts
     * Otherwise, return first amount
     */
    public static function extractAmount(string $text): ?int
    {
        // Check if this is a batch transaction
        if (self::isBatchTransaction($text)) {
            $amounts = self::extractAllAmounts($text);
            if (! empty($amounts)) {
                $total = array_sum($amounts);
                Log::info('Batch transaction detected', [
                    'text' => substr($text, 0, 100),
                    'amounts' => $amounts,
                    'total' => $total,
                    'count' => count($amounts),
                ]);

                return $total;
            }
        }

        // Single transaction - extract first amount
        return self::extractSingleAmount($text);
    }

    /**
     * Extract single amount (original logic)
     */
    private static function extractSingleAmount(string $text): ?int
    {
        $textLower = strtolower($text);

        // Pattern -1 (HIGHEST PRIORITY): Rp-prefixed amounts
        // e.g., "Rp 13.730.000", "Rp. 2.636.000", "Rp 50rb", "Rp 1,5jt"
        // Must check BEFORE multiplication to avoid misinterpreting product dimensions
        // like "CNP 100 X 50" or "Siku 4 X 4 X 4" as math expressions
        if (preg_match('/rp\.?\s*(\d{1,3}(?:\.\d{3})+)/i', $text, $rpMatch)) {
            return (int) str_replace('.', '', $rpMatch[1]);
        }
        if (preg_match('/rp\.?\s*(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)/i', $textLower, $rpMatch)) {
            $num = floatval(str_replace(',', '.', $rpMatch[1]));
            $suffix = strtolower($rpMatch[2]);
            $multipliers = [
                'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                'jt' => 1000000, 'juta' => 1000000,
            ];

            return (int) ($num * ($multipliers[$suffix] ?? 1));
        }
        if (preg_match('/rp\.?\s*(\d{4,})/i', $text, $rpMatch)) {
            return (int) $rpMatch[1];
        }
        if (preg_match('/rp\.?\s*(\d{1,3}(?:\s+\d{3})+)/i', $text, $rpMatch)) {
            return (int) str_replace(' ', '', $rpMatch[1]);
        }

        // Pattern 0: Multiplication patterns (33000 dikali 3, 50rb x 2)
        // Only match when NOT followed by an Rp amount (already handled above)
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?\s*(?:dikali|kali|x|\*)\s*(\d+)/i', $textLower, $matches)) {
            $base = floatval(str_replace(',', '.', $matches[1]));
            $suffix = strtolower($matches[2] ?? '');
            $multiplier = (int) $matches[3];

            $suffixMultipliers = [
                'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                'jt' => 1000000, 'juta' => 1000000,
            ];

            // Apply suffix if present
            if (! empty($suffix)) {
                $base = $base * ($suffixMultipliers[$suffix] ?? 1);
            } elseif ($base < 1000) {
                // Small number without suffix - assume ribu (e.g., "33 dikali 3" = 33000 x 3)
                $base = $base * 1000;
            }
            // else: large number like 33000, use as-is

            $total = (int) ($base * $multiplier);

            Log::info('Multiplication detected in batch helper', [
                'base' => $base,
                'multiplier' => $multiplier,
                'total' => $total,
                'text' => substr($text, 0, 100),
            ]);

            return $total;
        }

        // Pattern 1: Number with suffix (60rb, 5jt, 25k)
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)\b/i', $textLower, $matches)) {
            $num = floatval(str_replace(',', '.', $matches[1]));
            $suffix = strtolower($matches[2]);

            $multipliers = [
                'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                'jt' => 1000000, 'juta' => 1000000,
            ];

            return (int) ($num * ($multipliers[$suffix] ?? 1));
        }

        // Pattern 2: Plain number with dots (50.000)
        if (preg_match('/(\d{1,3}(?:\.\d{3})+)/', $text, $matches)) {
            return (int) str_replace('.', '', $matches[1]);
        }

        // Pattern 2b: Number with space-separated thousands (75 000, 1 500 000)
        if (preg_match('/(\d{1,3}(?:\s+\d{3})+)/', $text, $matches)) {
            return (int) str_replace(' ', '', $matches[1]);
        }

        // Pattern 3: Plain number at end (50000, at least 3 digits)
        if (preg_match('/(\d{3,})$/', trim($text), $matches)) {
            return (int) $matches[1];
        }

        // Pattern 4: Number anywhere (fallback)
        if (preg_match('/(\d{4,})/', $text, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
