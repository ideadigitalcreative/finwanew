<?php

/**
 * BACKUP USER DATA - 6285242766676
 * Backup all data before deletion for potential restore
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$phoneNumber = '6285242766676';
$backupFile = "backup_user_{$phoneNumber}_".date('Y-m-d_His').'.json';

echo "=== BACKUP USER DATA: {$phoneNumber} ===\n\n";

$backup = [
    'phone_number' => $phoneNumber,
    'backup_date' => date('Y-m-d H:i:s'),
    'users' => [],
    'whatsapp_mappings' => [],
    'transactions' => [],
    'tenants' => [],
];

// 1. Find users with this number
$users = DB::table('users')
    ->where('whatsapp_number', $phoneNumber)
    ->get();

echo "Found {$users->count()} user(s)\n";

foreach ($users as $user) {
    $backup['users'][] = json_decode(json_encode($user), true);

    echo "  - User ID: {$user->id}, Email: {$user->email}\n";

    // Get tenant
    $tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
    if ($tenant) {
        $backup['tenants'][] = json_decode(json_encode($tenant), true);
    }

    // Get WhatsApp mappings
    $mappings = DB::table('user_whatsapp_numbers')
        ->where('user_id', $user->id)
        ->get();

    foreach ($mappings as $mapping) {
        $backup['whatsapp_mappings'][] = json_decode(json_encode($mapping), true);
    }

    // Get transactions
    $transactions = DB::table('transactions')
        ->where('tenant_id', $user->tenant_id)
        ->get();

    echo "    Transactions: {$transactions->count()}\n";

    foreach ($transactions as $txn) {
        $backup['transactions'][] = json_decode(json_encode($txn), true);
    }

    // Get subscriptions
    $subscriptions = DB::table('subscriptions')
        ->where('tenant_id', $user->tenant_id)
        ->get();

    $backup['subscriptions'] = [];
    foreach ($subscriptions as $sub) {
        $backup['subscriptions'][] = json_decode(json_encode($sub), true);
    }

    // Get user_tenants pivot
    $pivots = DB::table('user_tenants')
        ->where('user_id', $user->id)
        ->get();

    $backup['user_tenants'] = [];
    foreach ($pivots as $pivot) {
        $backup['user_tenants'][] = json_decode(json_encode($pivot), true);
    }
}

// 2. Check WhatsApp mappings (even if user deleted)
$allMappings = DB::table('user_whatsapp_numbers')
    ->where('whatsapp_number', $phoneNumber)
    ->get();

echo "\nWhatsApp mappings: {$allMappings->count()}\n";

foreach ($allMappings as $mapping) {
    if (! in_array($mapping->id, array_column($backup['whatsapp_mappings'], 'id'))) {
        $backup['whatsapp_mappings'][] = json_decode(json_encode($mapping), true);
        echo "  - Mapping ID: {$mapping->id}, User ID: {$mapping->user_id}\n";
    }
}

// Save backup
file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT));

echo "\n✅ Backup saved to: {$backupFile}\n";
echo 'File size: '.number_format(filesize($backupFile))." bytes\n";

// Summary
echo "\n=== BACKUP SUMMARY ===\n";
echo 'Users: '.count($backup['users'])."\n";
echo 'Tenants: '.count($backup['tenants'])."\n";
echo 'WhatsApp Mappings: '.count($backup['whatsapp_mappings'])."\n";
echo 'Transactions: '.count($backup['transactions'])."\n";
echo 'Subscriptions: '.count($backup['subscriptions'] ?? [])."\n";
echo 'User-Tenant Pivots: '.count($backup['user_tenants'] ?? [])."\n";

echo "\n=== DONE ===\n";
echo "To restore, use: php restore_user_backup.php {$backupFile}\n";
