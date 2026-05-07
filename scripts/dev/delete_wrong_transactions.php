<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DELETE WRONG TRANSACTIONS ===\n\n";

// Tenant IDs yang salah
$wrongTenants = [10023, 10024]; // Sarka and Nayshila

// Find transactions from today in these tenants
$transactions = DB::table('transactions')
    ->whereIn('tenant_id', $wrongTenants)
    ->where('created_at', '>=', '2025-12-19 00:00:00')
    ->orderBy('tenant_id')
    ->orderBy('created_at', 'desc')
    ->get();

if ($transactions->count() === 0) {
    echo "No transactions found\n";
    exit;
}

echo "Found {$transactions->count()} transaction(s) to delete:\n\n";

$byTenant = [];
foreach ($transactions as $txn) {
    if (!isset($byTenant[$txn->tenant_id])) {
        $byTenant[$txn->tenant_id] = [];
    }
    $byTenant[$txn->tenant_id][] = $txn;
}

foreach ($byTenant as $tenantId => $txns) {
    $tenantName = $tenantId == 10023 ? 'Sarka' : 'Nayshila';
    echo "TENANT $tenantId ($tenantName): " . count($txns) . " transaction(s)\n";
    
    foreach ($txns as $txn) {
        echo "  - ID $txn->id: Rp " . number_format($txn->amount, 0, ',', '.') . " - $txn->description\n";
    }
    echo "\n";
}

echo "Delete all transactions above? (yes/no): ";
$handle = fopen('php://stdin', 'r');
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) === 'yes') {
    $deleted = 0;
    foreach ($transactions as $txn) {
        DB::table('transactions')->where('id', $txn->id)->delete();
        $deleted++;
    }
    echo "\n✅ Deleted $deleted transaction(s)\n";
} else {
    echo "\n❌ Cancelled\n";
}
