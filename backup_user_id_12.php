<?php

/**
 * BACKUP USER ID 12 - haerulhadi00@gmail.com
 * Complete backup for migration to VPS
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$userId = 12;
$backupFile = "backup_user_id_{$userId}_" . date('Y-m-d_His') . ".json";

echo "=== BACKUP USER ID: {$userId} ===\n\n";

$user = \App\Models\User::find($userId);

if (!$user) {
    echo "❌ User not found\n";
    exit;
}

echo "User: {$user->email}\n";
echo "Name: {$user->name}\n";
echo "Tenant ID: {$user->tenant_id}\n\n";

$backup = [
    'backup_date' => date('Y-m-d H:i:s'),
    'user_id' => $userId,
    'user' => json_decode(json_encode($user), true),
    'tenant' => null,
    'whatsapp_mappings' => [],
    'transactions' => [],
    'subscriptions' => [],
    'user_tenants' => [],
    'categories' => [],
    'balances' => [],
];

// 1. Tenant
$tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
if ($tenant) {
    $backup['tenant'] = json_decode(json_encode($tenant), true);
    echo "✅ Tenant: {$tenant->name}\n";
}

// 2. WhatsApp Mappings
$mappings = DB::table('user_whatsapp_numbers')
    ->where('user_id', $userId)
    ->get();

foreach ($mappings as $mapping) {
    $backup['whatsapp_mappings'][] = json_decode(json_encode($mapping), true);
}
echo "✅ WhatsApp Mappings: {$mappings->count()}\n";

// 3. Transactions
$transactions = DB::table('transactions')
    ->where('tenant_id', $user->tenant_id)
    ->get();

foreach ($transactions as $txn) {
    $backup['transactions'][] = json_decode(json_encode($txn), true);
}
echo "✅ Transactions: {$transactions->count()}\n";

// 4. Subscriptions
$subscriptions = DB::table('subscriptions')
    ->where('tenant_id', $user->tenant_id)
    ->get();

foreach ($subscriptions as $sub) {
    $backup['subscriptions'][] = json_decode(json_encode($sub), true);
}
echo "✅ Subscriptions: {$subscriptions->count()}\n";

// 5. User-Tenant Pivots
$pivots = DB::table('user_tenants')
    ->where('user_id', $userId)
    ->get();

foreach ($pivots as $pivot) {
    $backup['user_tenants'][] = json_decode(json_encode($pivot), true);
}
echo "✅ User-Tenant Pivots: {$pivots->count()}\n";

// 6. Categories
$categories = DB::table('categories')
    ->where('tenant_id', $user->tenant_id)
    ->get();

foreach ($categories as $cat) {
    $backup['categories'][] = json_decode(json_encode($cat), true);
}
echo "✅ Categories: {$categories->count()}\n";

// 7. Balances
$balances = DB::table('balances')
    ->where('tenant_id', $user->tenant_id)
    ->get();

foreach ($balances as $balance) {
    $backup['balances'][] = json_decode(json_encode($balance), true);
}
echo "✅ Balances: {$balances->count()}\n";

// Save backup
file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT));

echo "\n✅ Backup saved to: {$backupFile}\n";
echo "File size: " . number_format(filesize($backupFile)) . " bytes\n";

// Summary
echo "\n=== BACKUP SUMMARY ===\n";
echo "User: {$user->email}\n";
echo "Tenant: " . ($backup['tenant']['name'] ?? 'N/A') . "\n";
echo "WhatsApp Numbers: " . count($backup['whatsapp_mappings']) . "\n";
echo "Transactions: " . count($backup['transactions']) . "\n";
echo "Subscriptions: " . count($backup['subscriptions']) . "\n";
echo "Categories: " . count($backup['categories']) . "\n";
echo "Balances: " . count($backup['balances']) . "\n";

echo "\n=== DONE ===\n";
echo "Upload this file to VPS and run:\n";
echo "php restore_user_backup.php {$backupFile}\n";
