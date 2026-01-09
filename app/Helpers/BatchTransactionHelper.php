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
        // Indicators of batch transaction:
        // 1. Multiple lines with dashes/bullets
        // 2. Multiple amounts found
        // 3. Contains list markers (-, *, •, numbers)
        // 4. Multiple lines each with an amount (NEW)
        
        $hasListMarkers = preg_match('/^\s*[-*•]\s+/m', $text) || preg_match('/^\s*\d+[.)]/m', $text);
        
        $amounts = self::extractAllAmounts($text);
        $hasMultipleAmounts = count($amounts) >= 2;
        
        // NEW: Check if multiple lines (without list markers)
        $lines = preg_split('/[\r\n]+/', trim($text));
        $hasMultipleLines = count($lines) >= 2;
        
        // Batch if:
        // - Has list markers AND multiple amounts, OR
        // - Has multiple lines AND multiple amounts (even without markers)
        return ($hasListMarkers && $hasMultipleAmounts) || ($hasMultipleLines && $hasMultipleAmounts);
    }
    
    /**
     * Extract ALL amounts from text (for batch transactions)
     * Returns array of amounts
     */
    public static function extractAllAmounts(string $text): array
    {
        $amounts = [];
        $textLower = strtolower($text);
        
        // Pattern 1: Numbers with suffix (10k, 5rb, 2jt)
        if (preg_match_all('/\b(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)\b/i', $textLower, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $num = floatval(str_replace(',', '.', $match[1]));
                $suffix = strtolower($match[2]);
                
                $multipliers = [
                    'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                    'jt' => 1000000, 'juta' => 1000000
                ];
                
                $amounts[] = (int)($num * ($multipliers[$suffix] ?? 1));
            }
        }
        
        // Pattern 2: Plain numbers with dots (10.000, 25.000)
        if (empty($amounts) && preg_match_all('/(\d{1,3}(?:\.\d{3})+)/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $amounts[] = (int)str_replace('.', '', $match);
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
            if (!empty($amounts)) {
                $total = array_sum($amounts);
                Log::info('Batch transaction detected', [
                    'text' => substr($text, 0, 100),
                    'amounts' => $amounts,
                    'total' => $total,
                    'count' => count($amounts)
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
        
        // Pattern 0: Multiplication patterns (33000 dikali 3, 50rb x 2)
        // Must check FIRST before other patterns
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?\s*(?:dikali|kali|x|\*)\s*(\d+)/i', $textLower, $matches)) {
            $base = floatval(str_replace(',', '.', $matches[1]));
            $suffix = strtolower($matches[2] ?? '');
            $multiplier = (int)$matches[3];
            
            $suffixMultipliers = [
                'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                'jt' => 1000000, 'juta' => 1000000
            ];
            
            // Apply suffix if present
            if (!empty($suffix)) {
                $base = $base * ($suffixMultipliers[$suffix] ?? 1);
            } elseif ($base < 1000) {
                // Small number without suffix - assume ribu (e.g., "33 dikali 3" = 33000 x 3)
                $base = $base * 1000;
            }
            // else: large number like 33000, use as-is
            
            $total = (int)($base * $multiplier);
            
            Log::info('Multiplication detected in batch helper', [
                'base' => $base,
                'multiplier' => $multiplier,
                'total' => $total,
                'text' => substr($text, 0, 100)
            ]);
            
            return $total;
        }
        
        // Pattern 1: Number with suffix (60rb, 5jt, 25k)
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)\b/i', $textLower, $matches)) {
            $num = floatval(str_replace(',', '.', $matches[1]));
            $suffix = strtolower($matches[2]);
            
            $multipliers = [
                'rb' => 1000, 'ribu' => 1000, 'k' => 1000,
                'jt' => 1000000, 'juta' => 1000000
            ];
            
            return (int)($num * ($multipliers[$suffix] ?? 1));
        }
        
        // Pattern 2: Plain number with dots (50.000)
        if (preg_match('/(\d{1,3}(?:\.\d{3})+)/', $text, $matches)) {
            return (int)str_replace('.', '', $matches[1]);
        }
        
        // Pattern 3: Plain number at end (50000, at least 3 digits)
        if (preg_match('/(\d{3,})$/', trim($text), $matches)) {
            return (int)$matches[1];
        }
        
        // Pattern 4: Number anywhere (fallback)
        if (preg_match('/(\d{4,})/', $text, $matches)) {
            return (int)$matches[1];
        }
        
        return null;
    }
}
