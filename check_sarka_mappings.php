<?php

/**
 * CHECK USER SARKA DETAILS
 * See all WhatsApp numbers linked to sarka
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CHECKING USER SARKA ===\n\n";

$user = DB::table('users')
    ->where('email', 'sarka3622@gmail.com')
    ->first();

if (!$user) {
    echo "User not found\n";
    exit;
}

echo "User Details:\n";
echo "  ID: {$user->id}\n";
echo "  Name: {$user->name}\n";
echo "  Email: {$user->email}\n";
echo "  WhatsApp Number: {$user->whatsapp_number}\n";
echo "  Tenant ID: {$user->tenant_id}\n";
echo "  Created: {$user->created_at}\n\n";

// Check all WhatsApp mappings for this user
echo "ALL WHATSAPP NUMBERS LINKED TO THIS USER:\n";
$mappings = DB::table('user_whatsapp_numbers')
    ->where('user_id', $user->id)
    ->get();

if ($mappings->count() === 0) {
    echo "  No mappings found\n";
} else {
    foreach ($mappings as $mapping) {
        echo "\n  Mapping ID: {$mapping->id}\n";
        echo "    WhatsApp Number: {$mapping->whatsapp_number}\n";
        echo "    Is Primary: " . ($mapping->is_primary ? 'Yes' : 'No') . "\n";
        echo "    Is Active: " . ($mapping->is_active ? 'Yes' : 'No') . "\n";
        echo "    Is LID: " . ($mapping->is_lid ? 'Yes' : 'No') . "\n";
        echo "    Created: {$mapping->created_at}\n";
        
        // Check if this is the problematic LID
        if ($mapping->whatsapp_number === '218442590343379') {
            echo "    ⚠️  THIS IS YOUR LID! (Should not be here)\n";
        }
    }
}

echo "\n=== RECOMMENDATION ===\n";
echo "If LID 218442590343379 belongs to you (6285159205506):\n";
echo "1. Delete this mapping from user sarka\n";
echo "2. The LID was incorrectly linked during registration\n";
echo "3. This happened because of auto-linking logic\n";

echo "\n=== DELETE WRONG LID MAPPING ===\n";
echo "Delete LID 218442590343379 from user sarka? (yes/no): ";

$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) === 'yes') {
    $deleted = DB::table('user_whatsapp_numbers')
        ->where('user_id', $user->id)
        ->where('whatsapp_number', '218442590343379')
        ->delete();
    
    if ($deleted > 0) {
        echo "\n✅ Deleted LID mapping from user sarka\n";
        echo "Your LID will no longer send messages to sarka's account\n";
    } else {
        echo "\n❌ Mapping not found\n";
    }
} else {
    echo "\n❌ Cancelled\n";
}

echo "\n=== DONE ===\n";
