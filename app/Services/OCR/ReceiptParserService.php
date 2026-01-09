<?php

namespace App\Services\OCR;

use Illuminate\Support\Facades\Log;

/**
 * ReceiptParserService - Handles extraction of data from OCR text
 * 
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 * 
 * Methods moved as-is without any modification to logic.
 */
class ReceiptParserService
{
    /**
     * Optimize OCR text for AI processing
     * Reduce long OCR text (e.g., 57 lines) to ~15-20 important lines only
     * This prevents Ollama from using too much RAM on VPS with limited memory
     * 
     * MOVED FROM: ProcessIncomingMessage::optimizeOcrTextForAI()
     * LINES: 6443-6523
     */
    public function optimizeOcrTextForAI(string $ocrText): string
    {
        $lines = explode("\n", $ocrText);
        $importantLines = [];
        $totalFound = false;
        
        Log::info('Optimizing OCR text for AI', [
            'original_line_count' => count($lines)
        ]);
        
        // 1. Always keep the first 3 lines (Header/Merchant info)
        for ($i = 0; $i < min(3, count($lines)); $i++) {
            $importantLines[] = trim($lines[$i]);
        }
        
        // 2. Scan for important key-value lines
        for ($i = 3; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            $upperLine = strtoupper($line);
            
            // Skip empty or very short garbage lines
            if (strlen($line) < 3) continue;
            
            // PATTERNS TO KEEP:
            
            // A. Date/Time info
            if (preg_match('/(TANGGAL|DATE|TIME|JAM|WAKTU|202[0-9]|[\d]{2}[\/\.\-][\d]{2})/', $upperLine)) {
                $importantLines[] = $line;
                continue;
            }
            
            // B. Item lines (price patterns)
            // e.g., "10.000", "Rp 50.000", "5 x 2.000"
            if (preg_match('/(\d{1,3}[\.,]\d{3})/', $line) || preg_match('/^\d+\s*[xX]\s*\d+/', $line)) {
                $importantLines[] = $line;
                continue;
            }
            
            // C. Totals and Payment info
            if (preg_match('/(TOTAL|JUMLAH|BAYAR|KEMBALI|CASH|TUNAI|DEBIT|CREDIT|SUBTOTAL|DISC|DISKON|TAX|PPN)/', $upperLine)) {
                $importantLines[] = $line;
                if (str_contains($upperLine, 'TOTAL')) {
                    $totalFound = true;
                }
                continue;
            }
            
            // Hard limit to prevent too long text
            if (count($importantLines) >= 25) {
                break;
            }
        }
        
        // If no important lines found, return first 15 lines as fallback
        if (empty($importantLines)) {
            Log::warning('No important lines found in OCR text, using first 15 lines');
            return implode("\n", array_slice($lines, 0, 15));
        }
        
        $optimizedText = implode("\n", $importantLines);
        
        Log::info('OCR text optimized', [
            'original_lines' => count($lines),
            'optimized_lines' => count($importantLines),
            'reduction_percent' => round((1 - count($importantLines) / count($lines)) * 100, 1)
        ]);
        
        return $optimizedText;
    }

    /**
     * Extract total amount from OCR text
     * Priority: TOTAL BELANJA > NON TUNAI > TUNAI > TOTAL > JUMLAH
     * 
     * MOVED FROM: ProcessIncomingMessage::extractTotalFromOcrText()
     * LINES: 6530-6629
     */
    public function extractTotalFromOcrText(string $text, bool $strictMode = false): ?int
    {
        $textUpper = strtoupper($text);
        
        // Priority patterns (in order of importance)
        // IMPORTANT: TUNAI is NOT a total - it's cash paid. We should prioritize TOTAL BELANJA.
        $priorityPatterns = [
            // HIGHEST PRIORITY: Specific and explicit total patterns
            '/TOTAL\s+BELANJA[\s\:\.\-\=]*(?:Rp|IDR)?\s*([\d\.,\h]+)/i',
            '/GRAND\s+TOTAL[\s\:\.\-\=]*(?:Rp|IDR)?\s*([\d\.,\h]+)/i',
            '/HARGA\s+JUAL[\s\:\.\-\=]*(?:Rp|IDR)?\s*([\d\.,\h]+)/i', // Actual sale price
            '/NET\s+TOTAL[\s\:\.\-\=]*(?:Rp|IDR)?\s*([\d\.,\h]+)/i',
            '/TAGIHAN[\s\:\.\-\=]*(?:Rp|IDR)?\s*([\d\.,\h]+)/i',
            
            // HIGH PRIORITY: Standard "TOTAL" patterns at start of line (NOT TUNAI/payment)
            '/^\s*TOTAL[\s\:\.\-\=]*(?:Rp|IDR)?\s*([\d\.,\h]+)/im',
            '/^\s*JUMLAH[\s\:\.\-\=]*(?:Rp|IDR)?\s*([\d\.,\h]+)/im',
            '/^\s*BAYAR[\s\:\.\-\=]*(?:Rp|IDR)?\s*([\d\.,\h]+)/im',
            
            // MEDIUM PRIORITY: Total word anywhere with STRICT number format
            '/TOTAL[\s\:\.\-\=]*(?:Rp|IDR)?\s*([0-9\h]{1,5}(?:[.,][0-9\h]{3})+)/i',
            
            // NOTE: TUNAI (cash paid) is NOT included here - it's often higher than actual total
            // e.g., Total = 49,900, Tunai = 50,000, Kembali = 100
        ];
        
        // Try each priority pattern in order
        foreach ($priorityPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                // Smart parse amount (handle 10.000,00 vs 10.000)
                $rawAmt = $matches[1];
                $amount = $this->parseAmountSmart($rawAmt);
                
                // Basic validation: amount should be reasonable (> 100) and < 500jt (sanity)
                if ($amount > 100 && $amount < 500000000) {
                    Log::info('Total extracted from OCR text (Pattern Match)', [
                        'pattern' => $pattern,
                        'matched' => $matches[0],
                        'amount' => $amount
                    ]);
                    return $amount;
                }
            }
        }
        
        // STOP HERE if Strict Mode is enabled
        if ($strictMode) {
            return null;
        }
        
        // Fallback 1: Look for "Rp XX.XXX" format anywhere
        if (preg_match_all('/Rp\\s*[:\\.]?\\s*([\\d\\.,]+)/i', $text, $matches)) {
            $amounts = [];
            foreach ($matches[1] as $match) {
                $clean = preg_replace('/[^\\d]/', '', $match);
                $val = (int)$clean;
                if ($val > 1000 && $val < 100000000) { // Add upper limit for safety
                    $amounts[] = $val;
                }
            }
            if (!empty($amounts)) {
                // Return the largest Rp amount (likely total)
                $maxAmount = max($amounts);
                Log::info('Total extracted from Rp pattern fallback', [
                    'amount' => $maxAmount
                ]);
                return $maxAmount;
            }
        }
        
        // Fallback 2: Find largest formatted number (XX.XXX format)
        if (preg_match_all('/(\\d{1,3}(?:[.,]\\d{3})+)/', $text, $matches)) {
            $amounts = [];
            foreach ($matches[1] as $match) {
                $clean = preg_replace('/[^\\d]/', '', $match);
                $val = (int)$clean;
                if ($val >= 1000 && $val < 100000000) {
                    $amounts[] = $val;
                }
            }
            if (!empty($amounts)) {
                $maxAmount = max($amounts);
                Log::info('Total extracted from formatted number fallback', [
                    'amount' => $maxAmount
                ]);
                return $maxAmount;
            }
        }
        
        return null;
    }

    /**
     * Extract store name from OCR text (simple heuristic)
     * 
     * MOVED FROM: ProcessIncomingMessage::extractStoreNameFromOcrText()
     * LINES: 6634-6669
     */
    public function extractStoreNameFromOcrText(string $text): ?string
    {
        $lines = explode("\n", $text);
        
        // Check first 5 lines for common store names
        for ($i = 0; $i < min(5, count($lines)); $i++) {
            $line = strtoupper(trim($lines[$i]));
            
            if (str_contains($line, 'INDOMARET')) return 'Indomaret';
            if (str_contains($line, 'ALFAMART')) return 'Alfamart';
            if (str_contains($line, 'ALFAMIDI')) return 'Alfamidi';
            if (str_contains($line, 'HYPERMART')) return 'Hypermart';
            if (str_contains($line, 'SUPERINDO')) return 'Superindo';
            if (str_contains($line, 'STARBUCKS')) return 'Starbucks';
            if (str_contains($line, 'MCDONALD')) return 'McDonalds';
            if (str_contains($line, 'KFC')) return 'KFC';
            if (str_contains($line, 'BURGER KING')) return 'Burger King';
            if (str_contains($line, 'HOKBEN')) return 'HokBen';
            if (str_contains($line, 'GOCAR')) return 'GoCar';
            if (str_contains($line, 'GRAB')) return 'Grab';
            if (str_contains($line, 'GOJEK')) return 'Gojek';
            if (str_contains($line, 'TOKOPEDIA')) return 'Tokopedia';
            if (str_contains($line, 'SHOPEE')) return 'Shopee';
        }
        
        // If no known store found, return the first non-empty line that looks like a title
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && strlen($line) > 3 && !preg_match('/^\d/', $line)) {
                // Return title case
                return ucwords(strtolower($line));
            }
        }
        
        return null;
    }

    /**
     * Parse date from receipt text
     * 
     * MOVED FROM: ProcessIncomingMessage::parseReceiptDate()
     * LINES: 6674-6694
     */
    public function parseReceiptDate(?string $dateRaw): string
    {
        if (!$dateRaw) return date('Y-m-d');
        
        try {
            // Try common receipt formats
            // 18.11.25 -> 2025-11-18
            if (preg_match('/(\d{2})[\.\/\-](\d{2})[\.\/\-](\d{2})/', $dateRaw, $matches)) {
                return "20{$matches[3]}-{$matches[2]}-{$matches[1]}";
            }
            
            // 18.11.2025 -> 2025-11-18
            if (preg_match('/(\d{2})[\.\/\-](\d{2})[\.\/\-](\d{4})/', $dateRaw, $matches)) {
                return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
            }
            
            return date('Y-m-d');
        } catch (\Exception $e) {
            return date('Y-m-d');
        }
    }

    /**
     * Parse amount string smartly handling IDR/US formats
     * 10.000 -> 10000
     * 10.000,00 -> 10000
     * 10,000.00 -> 10000
     */
    private function parseAmountSmart(string $rawAmt): int
    {
        // Remove currency symbols and spaces
        $clean = preg_replace('/[^\d\.,]/', '', $rawAmt);
        
        // Check for both separators
        $hasDot = strpos($clean, '.') !== false;
        $hasComma = strpos($clean, ',') !== false;
        
        if ($hasDot && $hasComma) {
            // Determine which is decimal separator (the right-most one)
            $lastDot = strrpos($clean, '.');
            $lastComma = strrpos($clean, ',');
            
            if ($lastComma > $lastDot) {
                // Format: 1.000,00 (IDR standard)
                $clean = str_replace('.', '', $clean); // Remove thousands
                $clean = str_replace(',', '.', $clean); // Convert decimal
            } else {
                // Format: 1,000.00 (US standard)
                $clean = str_replace(',', '', $clean);
            }
        } elseif ($hasComma) {
            // Only comma. Ambiguous: 100,00 (decimal) or 100,000 (thousands - or OCR error reading dot as comma)?
            // Heuristic: If followed by exactly 3 digits at the end, treat as THOUSANDS.
            // Example: 137,900 -> 137900
            // Example: 50,00 -> 50.00 (decimal)
            if (preg_match('/,\d{3}$/', $clean)) {
                 $clean = str_replace(',', '', $clean);
            } else {
                 $clean = str_replace(',', '.', $clean);
            }
        } elseif ($hasDot) {
             // Only dot. 10.000 (Thousands in IDR)
             // 137.900 -> 137900
             $clean = str_replace('.', '', $clean);
        }
        
        return (int) floatval($clean);
    }
}
