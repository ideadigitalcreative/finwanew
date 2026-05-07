<?php

/**
 * CHECK AND CLEAN ORPHANED WHATSAPP NUMBERS
 * Find WhatsApp numbers that belong to deleted users
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$phoneNumber = $argv[1] ?? '6285159205506';

echo "=== CHECKING WHATSAPP NUMBER: {$phoneNumber} ===\n\n";

// 1. Check if number exists in user_whatsapp_numbers
$whatsappNumbers = DB::table('user_whatsapp_numbers')
    ->where('whatsapp_number', $phoneNumber)
    ->get();

if ($whatsappNumbers->count() === 0) {
    echo "✅ No WhatsApp number mapping found\n";
    exit;
}

echo "Found {$whatsappNumbers->count()} WhatsApp number mapping(s):\n\n";

foreach ($whatsappNumbers as $mapping) {
    echo "Mapping ID: {$mapping->id}\n";
    echo "  User ID: {$mapping->user_id}\n";
    echo "  Tenant ID: {$mapping->tenant_id}\n";
    echo "  Name: {$mapping->name}\n";
    echo "  Is Primary: " . ($mapping->is_primary ? 'Yes' : 'No') . "\n";
    echo "  Is Active: " . ($mapping->is_active ? 'Yes' : 'No') . "\n";
    echo "  Is LID: " . ($mapping->is_lid ? 'Yes' : 'No') . "\n";
    
    // Check if user exists
    $user = \App\Models\User::find($mapping->user_id);
    
    if ($user) {
        echo "  ✅ User exists: {$user->email}\n";
    } else {
        echo "  ❌ User DELETED (orphaned mapping)\n";
        echo "  → This is why bot still responds!\n";
        
        // Ask to delete
        echo "\n  Delete this orphaned mapping? (y/n): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        if (trim(strtolower($line)) === 'y') {
            DB::table('user_whatsapp_numbers')
                ->where('id', $mapping->id)
                ->delete();
            echo "  ✅ Mapping deleted!\n";
        } else {
            echo "  ⏭️  Skipped\n";
        }
    }
    
    echo "\n";
}

// 2. Find all orphaned mappings
echo "\n=== CHECKING ALL ORPHANED MAPPINGS ===\n\n";

$orphanedMappings = DB::table('user_whatsapp_numbers as uwn')
    ->leftJoin('users as u', 'uwn.user_id', '=', 'u.id')
    ->whereNull('u.id')
    ->select('uwn.*')
    ->get();

if ($orphanedMappings->count() === 0) {
    echo "✅ No orphaned mappings found\n";
} else {
    echo "Found {$orphanedMappings->count()} orphaned mapping(s):\n\n";
    
    foreach ($orphanedMappings as $mapping) {
        echo "  - {$mapping->whatsapp_number} (User ID: {$mapping->user_id}, Mapping ID: {$mapping->id})\n";
    }
    
    echo "\nDelete all orphaned mappings? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) === 'y') {
        $deleted = DB::table('user_whatsapp_numbers as uwn')
            ->leftJoin('users as u', 'uwn.user_id', '=', 'u.id')
            ->whereNull('u.id')
            ->delete();
        
        echo "✅ Deleted {$deleted} orphaned mapping(s)\n";
    } else {
        echo "⏭️  Skipped\n";
    }
}

echo "\n=== DONE ===\n";
