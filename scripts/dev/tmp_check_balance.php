<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Balance;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;

$name = 'haerul';
$user = User::where('name', 'like', "%{$name}%")->first();

if (! $user) {
    echo "User '{$name}' not found.\n";
    exit;
}

echo "Found User: {$user->name} (ID: {$user->id}, Tenant ID: {$user->tenant_id})\n";

$tenant = Tenant::find($user->tenant_id);
if (! $tenant) {
    echo "Tenant not found.\n";
    exit;
}

$balances = Balance::where('tenant_id', $tenant->id)->where('is_active', true)->get();
echo "\nCurrent Balances in DB:\n";
foreach ($balances as $b) {
    echo "- {$b->account_name}: Rp ".number_format($b->balance, 0, ',', '.')."\n";
}

// Calculate expected balance from transactions
$transactions = Transaction::where('tenant_id', $tenant->id)->where('status', 'confirmed')->get();
$totalIncome = $transactions->where('type', 'income')->sum('amount');
$totalExpense = $transactions->where('type', 'expense')->sum('amount');
$net = $totalIncome - $totalExpense;

echo "\nCalculated from Transactions:\n";
echo '- Total Income: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
echo '- Total Expense: Rp '.number_format($totalExpense, 0, ',', '.')."\n";
echo '- Net Cashflow: Rp '.number_format($net, 0, ',', '.')."\n";
echo "\nDifference: Rp ".number_format($balances->sum('balance') - $net, 0, ',', '.')."\n";
