<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Balance;
use App\Models\Transaction;
use App\Models\User;

// Target User
$whatsapp = '6285159205506'; // Haerul Hadi (ID 12)
echo "=== Debug Balance Mismatch for $whatsapp ===\n";

$user = User::where('whatsapp_number', $whatsapp)->first();
if (! $user) {
    echo "User $whatsapp not found! Listing all users:\n";
    foreach (User::all() as $u) {
        echo "- ID: {$u->id} | WA: {$u->whatsapp_number} | Name: {$u->name}\n";
    }
    exit("\nPlease update the script with correct Whatsapp Number or ID.\n");
}

$tenantId = $user->tenant_id;
echo "Tenant ID: $tenantId\n\n";

// 1. Calculate Lifetime Stats
$lifetimeIncome = Transaction::where('tenant_id', $tenantId)->where('type', 'income')->sum('amount');
$lifetimeExpense = Transaction::where('tenant_id', $tenantId)->where('type', 'expense')->sum('amount');
$lifetimeNett = $lifetimeIncome - $lifetimeExpense;

echo "📊 Lifetime Calculator:\n";
echo '   Total Income  : Rp '.number_format($lifetimeIncome, 0, ',', '.')."\n";
echo '   Total Expense : Rp '.number_format($lifetimeExpense, 0, ',', '.')."\n";
echo '   Calculated Nett: Rp '.number_format($lifetimeNett, 0, ',', '.')."\n\n";

// 2. Check Actual Balance
$balances = Balance::where('tenant_id', $tenantId)->get();
$totalActualBalance = $balances->sum('balance');

echo "🏦 Actual DB Balance:\n";
foreach ($balances as $b) {
    $activeStatus = $b->is_active ? '✅ Active' : '❌ Inactive';
    echo "   - {$b->name} (ID: {$b->id}) [{$activeStatus}]: Rp ".number_format($b->balance, 0, ',', '.')."\n";
}
echo '   TOTAL: Rp '.number_format($totalActualBalance, 0, ',', '.')."\n\n";

// 3. Diagnosis
$diff = $totalActualBalance - $lifetimeNett;
echo '⚖️ Difference (Actual - Calculated): Rp '.number_format($diff, 0, ',', '.')."\n";

if ($diff == 0) {
    echo "✅ PERFECT MATCH! Balance is historically correct.\n";
    echo "   (If user complains, maybe they forgot enter Initial Balance?)\n";
} else {
    echo "❌ MISMATCH FOUND! DB Balance is out of sync with Transactions.\n";
    echo "   Recommendation: Reset balance to match calculated nett.\n";
}
