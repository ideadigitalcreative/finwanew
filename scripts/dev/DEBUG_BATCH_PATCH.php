<?php

/**
 * TEMPORARY DEBUG PATCH
 * Add logging to handleBatchTransactions to see why only 1 transaction is created
 *
 * Add this code at line 4845 in ProcessIncomingMessage.php:
 */

// BEFORE:
// $amount = $this->extractAmountFromText($cleanLine);

// REPLACE WITH:
$amount = $this->extractAmountFromText($cleanLine);
Log::info('DEBUG: Extracting amount from line', [
    'line_number' => $i,
    'original_line' => $line,
    'clean_line' => $cleanLine,
    'extracted_amount' => $amount,
]);

// This will log each line being processed and the amount extracted
// After testing, check the log with:
// tail -100 storage/logs/laravel.log | grep "DEBUG: Extracting amount"
