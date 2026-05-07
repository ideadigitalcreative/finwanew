<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Balance;
use App\Models\Transaction;

$tenantId = 10315; // Haerul's tenant ID

$balances = Balance::where('tenant_id', $tenantId)->where('is_active', true)->get();

echo "Repairing balances for Tenant ID: {$tenantId}\n";

foreach ($balances as $balance) {
    echo "Processing wallet: {$balance->account_name}\n";

    // Calculate total confirmed income for this wallet
    $income = Transaction::where('tenant_id', $tenantId)
        ->where('balance_id', $balance->id)
        ->where('type', 'income')
        ->where('status', 'confirmed')
        ->sum('amount');

    // Calculate total confirmed expense for this wallet
    $expense = Transaction::where('tenant_id', $tenantId)
        ->where('balance_id', $balance->id)
        ->where('type', 'expense')
        ->where('status', 'confirmed')
        ->sum('amount');

    $newBalance = $income - $expense;
    $oldBalance = $balance->balance;

    echo '- Old Balance: Rp '.number_format($oldBalance, 0, ',', '.')."\n";
    echo '- New Balance: Rp '.number_format($newBalance, 0, ',', '.')."\n";

    if ($oldBalance != $newBalance) {
        $balance->balance = $newBalance;
        $balance->save();
        echo "✅ Balance updated!\n";
    } else {
        echo "ℹ️ Balance is already correct.\n";
    }
}
