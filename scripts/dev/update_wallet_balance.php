<?php

/**
 * Script to fix wallet names for a specific user
 * Usage: php update_wallet_balance.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Balance;
use App\Models\UserWhatsAppNumber;

// Target user
$whatsappNumber = '6282317974774';
$tenantId = 10075; // From previous check

echo "=== Fix Wallet Names ===\n\n";

// Wallet name fixes: old name => new name
$nameFixes = [
    'Bjb 5.' => 'BJB',
    'BRI 1.' => 'BRI', 
    'BSI 31.' => 'BSI',
];

echo "Current wallets for tenant {$tenantId}:\n";
$wallets = Balance::where('tenant_id', $tenantId)->get();
foreach ($wallets as $wallet) {
    echo "  - {$wallet->account_name}: Rp " . number_format($wallet->balance, 0, ',', '.') . "\n";
}
echo "\n";

// Fix wallet names
echo "Fixing wallet names:\n";
foreach ($nameFixes as $oldName => $newName) {
    $wallet = Balance::where('tenant_id', $tenantId)
        ->where('account_name', $oldName)
        ->first();
    
    if ($wallet) {
        $wallet->account_name = $newName;
        $wallet->save();
        echo "✅ '{$oldName}' → '{$newName}'\n";
    } else {
        echo "⚠️ Wallet '{$oldName}' not found\n";
    }
}

echo "\nUpdated wallets:\n";
$wallets = Balance::where('tenant_id', $tenantId)->get();
foreach ($wallets as $wallet) {
    echo "  - {$wallet->account_name}: Rp " . number_format($wallet->balance, 0, ',', '.') . "\n";
}

echo "\n=== Complete ===\n";
