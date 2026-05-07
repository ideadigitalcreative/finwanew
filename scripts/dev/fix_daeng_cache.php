<?php

/**
 * 1. Clear all registration cache for user 363 / 6285159205506
 * 2. Check recent webhook logs to see what 'from' value is being sent
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

echo "=== 1. Clearing Registration Cache ===\n";

$numbers = ['6285159205506', '94588232532003'];
foreach ($numbers as $num) {
    $keys = [
        "wa_reg_flow:{$num}",
        "wa_reg_data:{$num}",
        'wa_reg_reply:hash:'.md5($num.'|halo'),
        'wa_reg_reply:hash:'.md5($num.'|makan siang 100 rb'),
        'wa_reg_reply:hash:'.md5($num.'|makan malam 200 rb'),
        "unregistered_warning:{$num}@c.us",
        "unregistered_warning:{$num}@lid",
        "expired_subscription_reply:{$num}@c.us",
        "expired_subscription_reply:{$num}@lid",
    ];

    foreach ($keys as $key) {
        $had = Cache::has($key);
        Cache::forget($key);
        echo ($had ? '  CLEARED: ' : '  (none):  ').$key."\n";
    }
}

// Also clear any possible registration cache with wildcard-like approach
// Check all possible sender formats
$formats = [
    '6285159205506', '6285159205506@c.us', '6285159205506@lid',
    '94588232532003', '94588232532003@c.us', '94588232532003@lid',
];
foreach ($formats as $fmt) {
    $cleanNum = preg_replace('/[^0-9]/', '', $fmt);
    Cache::forget("wa_reg_flow:{$cleanNum}");
    Cache::forget("wa_reg_data:{$cleanNum}");
}

echo "\n=== 2. Recent Messages from User 363 (last 10) ===\n";
$messages = DB::table('messages')
    ->where(function ($q) {
        $q->where('sender_id', 'like', '%5159205506%')
            ->orWhere('sender_id', 'like', '%94588232532003%');
    })
    ->orderBy('id', 'desc')
    ->limit(10)
    ->get();

foreach ($messages as $msg) {
    $meta = json_decode($msg->metadata ?? '{}', true);
    $rawData = json_decode($msg->raw_data ?? '{}', true);
    echo "\n  MSG #{$msg->id}:\n";
    echo "    sender_id:   {$msg->sender_id}\n";
    echo "    tenant_id:   {$msg->tenant_id}\n";
    echo '    content:     '.substr($msg->content ?? '(empty)', 0, 80)."\n";
    echo "    created_at:  {$msg->created_at}\n";
    echo '    original_from: '.($rawData['originalFrom'] ?? $meta['original_sender_id'] ?? 'N/A')."\n";
    echo '    raw_from:    '.($rawData['from'] ?? 'N/A')."\n";
}

echo "\n=== 3. Check Latest Log Entries (laravel.log) ===\n";
$logFile = storage_path('logs/laravel.log');
if (file_exists($logFile)) {
    // Read last 5000 characters and search for recent entries about this user
    $contents = file_get_contents($logFile, false, null, max(0, filesize($logFile) - 30000));
    $lines = explode("\n", $contents);

    $relevantLines = [];
    foreach ($lines as $line) {
        if (str_contains($line, '5159205506') || str_contains($line, '94588232532003') || str_contains($line, 'user_id.*363')) {
            $relevantLines[] = trim(substr($line, 0, 300));
        }
    }

    echo '  Found '.count($relevantLines)." relevant log lines:\n";
    foreach (array_slice($relevantLines, -15) as $line) {
        echo '  '.$line."\n";
    }
} else {
    echo "  Log file not found\n";
}

echo "\n=== DONE - Registration cache cleared ===\n";
echo "Please ask the user to try chatting again.\n";
