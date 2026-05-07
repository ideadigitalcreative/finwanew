<?php

/**
 * Debug script to check why number 6285159205506 is not linked
 * to the Pro user with LID 94588232532003
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsAppUserMappingService;

$mappingService = new WhatsAppUserMappingService;

echo "=== DEBUG: Checking number mapping for Daeng Digital ===\n\n";

// 1. Check user_whatsapp_numbers for 94588232532003
echo "--- 1. UserWhatsAppNumber entries for 94588232532003 ---\n";
$entries1 = UserWhatsAppNumber::where('whatsapp_number', '94588232532003')->get();
foreach ($entries1 as $e) {
    echo "  ID: {$e->id}, user_id: {$e->user_id}, tenant_id: {$e->tenant_id}, number: {$e->whatsapp_number}, primary: ".($e->is_primary ? 'YES' : 'NO').', active: '.($e->is_active ? 'YES' : 'NO').', is_lid: '.($e->is_lid ? 'YES' : 'NO')."\n";
}
if ($entries1->isEmpty()) {
    echo "  No entries found!\n";
}

// 2. Check user_whatsapp_numbers for 6285159205506
echo "\n--- 2. UserWhatsAppNumber entries for 6285159205506 ---\n";
$entries2 = UserWhatsAppNumber::where('whatsapp_number', '6285159205506')->get();
foreach ($entries2 as $e) {
    echo "  ID: {$e->id}, user_id: {$e->user_id}, tenant_id: {$e->tenant_id}, number: {$e->whatsapp_number}, primary: ".($e->is_primary ? 'YES' : 'NO').', active: '.($e->is_active ? 'YES' : 'NO').', is_lid: '.($e->is_lid ? 'YES' : 'NO')."\n";
}
if ($entries2->isEmpty()) {
    echo "  No entries found!\n";
}

// Also search with LIKE
echo "\n--- 2b. UserWhatsAppNumber entries LIKE %5159205506% ---\n";
$entries2b = UserWhatsAppNumber::where('whatsapp_number', 'like', '%5159205506%')->get();
foreach ($entries2b as $e) {
    echo "  ID: {$e->id}, user_id: {$e->user_id}, tenant_id: {$e->tenant_id}, number: {$e->whatsapp_number}, primary: ".($e->is_primary ? 'YES' : 'NO').', active: '.($e->is_active ? 'YES' : 'NO').', is_lid: '.($e->is_lid ? 'YES' : 'NO')."\n";
}
if ($entries2b->isEmpty()) {
    echo "  No entries found!\n";
}

// 3. Check LID mapping
echo "\n--- 3. UserLidMapping for 94588232532003 ---\n";
$lidMapping = UserLidMapping::where('lid', '94588232532003')->first();
if ($lidMapping) {
    echo "  ID: {$lidMapping->id}, user_id: {$lidMapping->user_id}, tenant_id: {$lidMapping->tenant_id}, lid: {$lidMapping->lid}, phone_number: {$lidMapping->phone_number}\n";
} else {
    echo "  No LID mapping found!\n";
}

// 4. Check users table for whatsapp_number = 6285159205506
echo "\n--- 4. Users with whatsapp_number = 6285159205506 ---\n";
$users = User::where('whatsapp_number', '6285159205506')->get();
foreach ($users as $u) {
    echo "  ID: {$u->id}, name: {$u->name}, wa: {$u->whatsapp_number}, tenant_id: {$u->tenant_id}\n";
}
if ($users->isEmpty()) {
    echo "  No users found!\n";
}

// 5. Test the mapping service
echo "\n--- 5. Testing WhatsAppUserMappingService::findUserByWhatsAppNumber('6285159205506@c.us') ---\n";
$foundUser = $mappingService->findUserByWhatsAppNumber('6285159205506@c.us');
if ($foundUser) {
    echo "  FOUND! User ID: {$foundUser->id}, Name: {$foundUser->name}, tenant_id: {$foundUser->tenant_id}\n";
} else {
    echo "  NOT FOUND! This is the BUG - user should be found.\n";
}

echo "\n--- 6. Testing getTenantIdFromWhatsAppNumber('6285159205506@c.us') ---\n";
$tenantId = $mappingService->getTenantIdFromWhatsAppNumber('6285159205506@c.us');
echo '  Result: '.($tenantId ?? 'NULL')."\n";

// 7. Check cleanPhoneNumber
echo "\n--- 7. cleanPhoneNumber results ---\n";
echo "  cleanPhoneNumber('94588232532003') = ".$mappingService->cleanPhoneNumber('94588232532003')."\n";
echo "  cleanPhoneNumber('6285159205506@c.us') = ".$mappingService->cleanPhoneNumber('6285159205506@c.us')."\n";
echo "  cleanPhoneNumber('6285159205506') = ".$mappingService->cleanPhoneNumber('6285159205506')."\n";

// 8. Check if user with LID 94588232532003 has a user entry
echo "\n--- 8. Find user connected to LID 94588232532003 ---\n";
if ($entries1->isNotEmpty()) {
    $userId = $entries1->first()->user_id;
    $user = User::find($userId);
    if ($user) {
        echo "  User ID: {$user->id}, Name: {$user->name}, WA: {$user->whatsapp_number}, tenant_id: {$user->tenant_id}\n";

        // List ALL whatsapp numbers for this user
        echo "\n  All UserWhatsAppNumbers for this user:\n";
        $allNumbers = UserWhatsAppNumber::where('user_id', $userId)->get();
        foreach ($allNumbers as $n) {
            echo "    - ID: {$n->id}, number: {$n->whatsapp_number}, primary: ".($n->is_primary ? 'YES' : 'NO').', active: '.($n->is_active ? 'YES' : 'NO').', is_lid: '.($n->is_lid ? 'YES' : 'NO')."\n";
        }
    }
}
// 9. Check subscription status
echo "\n--- 9. Check subscription for user's tenant ---\n";
if ($entries1->isNotEmpty()) {
    $tenantId = $entries1->first()->tenant_id;
    $tenant = \App\Models\Tenant::find($tenantId);
    if ($tenant) {
        echo "  Tenant ID: {$tenant->id}, Name: {$tenant->name}\n";
        $subs = \App\Models\Subscription::where('tenant_id', $tenantId)->orderBy('id', 'desc')->limit(3)->get();
        foreach ($subs as $s) {
            echo "  Sub ID: {$s->id}, plan: ".($s->plan ?? 'N/A').', status: '.($s->status ?? 'N/A').', start: '.($s->starts_at ?? $s->start_date ?? 'N/A').', end: '.($s->ends_at ?? $s->end_date ?? 'N/A')."\n";
        }
    }
}

echo "\n=== DONE ===\n";
