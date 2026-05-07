<?php

use App\Services\Category\CategoryMappingService;
use App\Services\Transaction\TransactionService;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Testing THR and Family Income Keywords ===\n\n";

$testMessages = [
    'dapat thr dari suami 4jt',
    'dapat thr 1jt',
    'dikasih suami 500rb',
    'terima uang dari istri 2jt',
    'duit masuk dari pacar 100rb',
    'rejeki anak sholeh 50k',
    'transfer dari mama 1jt',
    'dapet bonus 500rb',
];

// Helper to simulate the detection logic I added
function simulateIncomeDetection($text)
{
    $textLower = strtolower($text);

    // This regex is from TransactionService.php line 251 approx
    $isIncome = preg_match('/\b(gaji|bonus|terima|dapat|dapet|pemasukan|pendapatan|honor|upah|masuk|dikasih|dikasi|hadiah|angpao|kiriman|kado|sumbangan|thr|tf|duit|uang|rejeki|rezeki|transfer|income)\b/i', $textLower)
        || str_contains($textLower, 'uang masuk')
        || str_contains($textLower, 'duit masuk')
        || str_contains($textLower, 'dari papi')
        || str_contains($textLower, 'dari papa')
        || str_contains($textLower, 'dari mama')
        || str_contains($textLower, 'dari mami')
        || str_contains($textLower, 'dari ortu')
        || str_contains($textLower, 'dari ayah')
        || str_contains($textLower, 'dari ibu')
        || str_contains($textLower, 'dari bapak')
        || str_contains($textLower, 'dari suami')
        || str_contains($textLower, 'dari istri')
        || str_contains($textLower, 'dari pacar')
        || preg_match('/di\s*(kasih|kasi|kirimin?|transfer)/i', $textLower);

    return $isIncome;
}

$mappingService = new CategoryMappingService;

foreach ($testMessages as $msg) {
    $isIncome = simulateIncomeDetection($msg);
    $status = $isIncome ? '✅ INCOME' : '❌ EXPENSE';

    // Get category mapping (simulating how it would be called)
    // We don't have the AI entities here, so we simulate the fallback
    $categoryType = $mappingService->determineCategoryFromText($msg, $isIncome);

    echo "Message: \"$msg\"\n";
    echo "Result:  $status\n";
    echo "Category: $categoryType\n";
    echo "-------------------------------------------\n";
}

echo "=== Test Complete ===\n";
