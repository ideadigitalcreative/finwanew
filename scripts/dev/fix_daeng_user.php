<?php

/**
 * Fix user 363 (hadi / Daeng Digital):
 * 1. Set users.whatsapp_number to actual phone number (6285159205506)
 * 2. Mark 94588232532003 as is_lid = true in user_whatsapp_numbers
 * 3. Create LID mapping for 94588232532003
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;

$userId = 363;
$realPhone = '6285159205506';
$lidNumber = '94588232532003';
$tenantId = 10267;

echo "=== Fix User 363 (Daeng Digital / hadi) ===\n\n";

// 1. Fix users.whatsapp_number
$user = User::find($userId);
if ($user) {
    echo "BEFORE: users.whatsapp_number = {$user->whatsapp_number}\n";
    $user->whatsapp_number = $realPhone;
    $user->save();
    echo "AFTER:  users.whatsapp_number = {$user->whatsapp_number}\n\n";
} else {
    echo "ERROR: User not found!\n";
    exit(1);
}

// 2. Mark 94588232532003 as is_lid = true
$lidEntry = UserWhatsAppNumber::where('whatsapp_number', $lidNumber)
    ->where('user_id', $userId)
    ->first();

if ($lidEntry) {
    echo "BEFORE: UserWhatsAppNumber #{$lidEntry->id} is_lid = ".($lidEntry->is_lid ? 'YES' : 'NO')."\n";
    $lidEntry->is_lid = true;
    $lidEntry->is_primary = false; // LID should not be primary
    $lidEntry->save();
    echo "AFTER:  UserWhatsAppNumber #{$lidEntry->id} is_lid = YES, is_primary = NO\n\n";
}

// 2b. Set 6285159205506 as primary
$phoneEntry = UserWhatsAppNumber::where('whatsapp_number', $realPhone)
    ->where('user_id', $userId)
    ->first();

if ($phoneEntry) {
    $phoneEntry->is_primary = true;
    $phoneEntry->save();
    echo "Set {$realPhone} as primary number\n\n";
}

// 3. Create LID mapping
echo "Creating LID mapping for {$lidNumber}...\n";
UserLidMapping::linkLidToUser($lidNumber, $userId, $tenantId, $realPhone);
echo "LID mapping created/updated\n\n";

// Verify
echo "=== Verification ===\n";
$user->refresh();
echo "users.whatsapp_number: {$user->whatsapp_number}\n";

$allNumbers = UserWhatsAppNumber::where('user_id', $userId)->get();
foreach ($allNumbers as $n) {
    echo "UserWhatsAppNumber #{$n->id}: {$n->whatsapp_number} | primary: ".($n->is_primary ? 'YES' : 'NO').' | active: '.($n->is_active ? 'YES' : 'NO').' | is_lid: '.($n->is_lid ? 'YES' : 'NO')."\n";
}

$lidMap = UserLidMapping::where('lid', $lidNumber)->first();
echo "\nLID Mapping: lid={$lidMap->lid}, user_id={$lidMap->user_id}, phone={$lidMap->phone_number}\n";

echo "\n=== DONE ===\n";
