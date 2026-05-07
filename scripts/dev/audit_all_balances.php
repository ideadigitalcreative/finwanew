<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Transaction;
use App\Models\Balance;

$fixMode = in_array('--fix', $argv);

echo "=== AUDIT & FIX SALDO USER ===\n";
echo $fixMode ? "⚠️  MODE PERBAIKAN AKTIF! Saldo akan direset mengikuti history transaksi.\n" : "ℹ️  Mode Audit (Read Only). Gunakan --fix untuk memperbaiki.\n";
echo "\n";

$users = User::all();
$countMismatch = 0;

foreach ($users as $user) {
    echo "User: {$user->name} (ID: {$user->id})\n";
    
    // 1. Calculate Lifetime
    $ltIncome = Transaction::where('tenant_id', $user->tenant_id)->where('type', 'income')->sum('amount');
    $ltExpense = Transaction::where('tenant_id', $user->tenant_id)->where('type', 'expense')->sum('amount');
    $calcNett = $ltIncome - $ltExpense;
    
    // 2. Actual Balance (All Wallets)
    $balances = Balance::where('tenant_id', $user->tenant_id)->get();
    $actualTotal = $balances->sum('balance');
    $mainWallet = $balances->sortBy('id')->first(); // Asumsi wallet terlama adalah utama
    
    // 3. Compare
    $diff = $actualTotal - $calcNett;
    
    echo "   History: +$ltIncome | -$ltExpense = $calcNett\n";
    echo "   DB Saldo: $actualTotal\n";
    
    if (abs($diff) > 100) { // Toleransi 100 perak
        echo "   ❌ MISMATCH! Selisih: " . number_format($diff, 0, ',', '.') . "\n";
        $countMismatch++;
        
        if ($fixMode) {
            if (!$mainWallet) {
                echo "   ⚠️  Skipping: User tidak punya wallet.\n";
                continue;
            }
            
            echo "   🛠️  Fixing... ";
            
            // Adjust saldo agar totalnya = calcNett
            // Kita adjust di Main Wallet saja
            // Target Main Wallet Balance = (calcNett - (Total Lainnya))
            $otherWalletsTotal = $actualTotal - $mainWallet->balance;
            $targetMainBalance = $calcNett - $otherWalletsTotal;
            
            $adjustmentAmount = $targetMainBalance - $mainWallet->balance;
            
            if (abs($adjustmentAmount) > 0) {
                // Buat Transaksi Adjustment
                $cat = \App\Models\Category::where('tenant_id', $user->tenant_id)->first(); // Fallback category
                
                Transaction::create([
                    'tenant_id' => $user->tenant_id,
                    'balance_id' => $mainWallet->id,
                    'category_id' => $cat ? $cat->id : null,
                    'amount' => abs($adjustmentAmount),
                    'type' => $adjustmentAmount > 0 ? 'income' : 'expense',
                    'transaction_date' => now(),
                    'description' => 'Auto Fix Balance Audit',
                    'status' => 'confirmed'
                ]);
                
                $mainWallet->balance = $targetMainBalance;
                $mainWallet->save();
                echo "DONE. Main Wallet adjusted to $targetMainBalance.\n";
            } else {
                echo "Skipped (Small diff).\n";
            }
        }
    } else {
        echo "   ✅ Match.\n";
    }
    echo "----------------------------------------\n";
}

echo "\nTotal User Mismatch: $countMismatch\n";
if (!$fixMode && $countMismatch > 0) {
    echo "Jalankan dengan 'php audit_all_balances.php --fix' untuk memperbaiki otomatis.\n";
}
