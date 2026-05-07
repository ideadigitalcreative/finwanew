<?php

/**
 * COMPREHENSIVE CHECK FOR USER AND WHATSAPP MAPPINGS
 * Check all possible ways user might still be registered
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$phoneNumber = $argv[1] ?? '6285159205506';

echo "=== COMPREHENSIVE CHECK FOR: {$phoneNumber} ===\n\n";

// 1. Check in users table
echo "1. CHECKING USERS TABLE:\n";
$users = DB::table('users')
    ->where('whatsapp_number', $phoneNumber)
    ->get();

if ($users->count() > 0) {
    echo "  Found {$users->count()} user(s):\n";
    foreach ($users as $user) {
        echo "    - User ID: {$user->id}, Email: {$user->email}, Name: {$user->name}\n";
    }
} else {
    echo "  ✅ No users found with this WhatsApp number\n";
}

// 2. Check in user_whatsapp_numbers table
echo "\n2. CHECKING USER_WHATSAPP_NUMBERS TABLE:\n";
$mappings = DB::table('user_whatsapp_numbers')
    ->where('whatsapp_number', $phoneNumber)
    ->orWhere('whatsapp_number', 'LIKE', '%' . substr($phoneNumber, -8) . '%')
    ->get();

if ($mappings->count() > 0) {
    echo "  Found {$mappings->count()} mapping(s):\n";
    foreach ($mappings as $mapping) {
        echo "    - Mapping ID: {$mapping->id}\n";
        echo "      User ID: {$mapping->user_id}\n";
        echo "      WhatsApp Number: {$mapping->whatsapp_number}\n";
        echo "      Is Active: " . ($mapping->is_active ? 'Yes' : 'No') . "\n";
        echo "      Is LID: " . ($mapping->is_lid ? 'Yes' : 'No') . "\n";
        
        // Check if user exists
        $user = DB::table('users')->where('id', $mapping->user_id)->first();
        if ($user) {
            echo "      User: {$user->email} (exists)\n";
        } else {
            echo "      User: DELETED (orphaned)\n";
        }
        echo "\n";
    }
} else {
    echo "  ✅ No mappings found\n";
}

// 3. Check messages from this number
echo "\n3. CHECKING RECENT MESSAGES:\n";
$messages = DB::table('messages')
    ->where('sender_id', 'LIKE', '%' . substr($phoneNumber, -8) . '%')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get(['id', 'sender_id', 'tenant_id', 'content', 'created_at']);

if ($messages->count() > 0) {
    echo "  Found {$messages->count()} recent message(s):\n";
    foreach ($messages as $msg) {
        echo "    - Message ID: {$msg->id}\n";
        echo "      Sender: {$msg->sender_id}\n";
        echo "      Tenant ID: {$msg->tenant_id}\n";
        echo "      Content: " . substr($msg->content, 0, 50) . "...\n";
        echo "      Time: {$msg->created_at}\n";
        
        // Check tenant
        if ($msg->tenant_id > 1) {
            $tenant = DB::table('tenants')->where('id', $msg->tenant_id)->first();
            if ($tenant) {
                echo "      Tenant: {$tenant->name}\n";
            }
        }
        echo "\n";
    }
} else {
    echo "  ✅ No messages found\n";
}

// 4. Check all variations of the number
echo "\n4. CHECKING NUMBER VARIATIONS:\n";
$variations = [
    $phoneNumber,
    '0' . substr($phoneNumber, 2), // 62xxx -> 0xxx
    '62' . substr($phoneNumber, 1), // 0xxx -> 62xxx
    substr($phoneNumber, -10), // Last 10 digits
    substr($phoneNumber, -9), // Last 9 digits
];

foreach ($variations as $variant) {
    $count = DB::table('user_whatsapp_numbers')
        ->where('whatsapp_number', $variant)
        ->count();
    
    if ($count > 0) {
        echo "  ✅ Found with variant: {$variant} ({$count} mapping(s))\n";
    }
}

echo "\n=== RECOMMENDATION ===\n";
echo "If user can still send messages, check:\n";
echo "1. The exact sender_id from recent messages\n";
echo "2. Use that sender_id to find the mapping\n";
echo "3. Delete the mapping manually\n";

echo "\n=== DONE ===\n";
