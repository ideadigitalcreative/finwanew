<?php

use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsAppUserMappingService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$inputNumber = '6285159205506';

echo "🔍 Debugging Mapping for: $inputNumber\n";

// Instantiate service to test cleaning
$service = new WhatsAppUserMappingService;
$cleanedInput = $service->cleanPhoneNumber($inputNumber);
echo "ℹ️ Cleaned Input: $cleanedInput\n\n";

// 1. Search UserWhatsAppNumber
if (class_exists(UserWhatsAppNumber::class)) {
    echo "1. Searching 'user_whatsapp_numbers' table...\n";
    // Get all and filter in PHP to be sure about cleaning logic matches
    $allMappings = UserWhatsAppNumber::all();
    $found = false;
    foreach ($allMappings as $map) {
        $cleanedMap = $service->cleanPhoneNumber($map->whatsapp_number);
        if ($cleanedMap == $cleanedInput) {
            echo "   🔴 FOUND GHOST RECORD!\n";
            echo "      ID: {$map->id}\n";
            echo "      User ID: {$map->user_id}\n";
            echo "      Tenant ID: {$map->tenant_id}\n";
            echo "      WA Number (Raw): {$map->whatsapp_number}\n";
            echo "      Is Active: {$map->is_active}\n";

            // Check User existence
            $u = User::find($map->user_id);
            if (! $u) {
                echo "      ⚠️ Linked User ID {$map->user_id} DOES NOT EXIST (Orphan).\n";
            } else {
                echo "      ✅ Linked User exists: {$u->name} ({$u->email})\n";
            }

            // Delete it?
            echo "      🗑️ DELETING this mapping record...\n";
            $map->delete();
            echo "      ✅ Deleted.\n";
            $found = true;
        }
    }

    if (! $found) {
        echo "   ✅ No matching records found in user_whatsapp_numbers.\n";
    }
} else {
    echo "   ⚠️ Model UserWhatsAppNumber not found.\n";
}

echo "\n";

// 2. Search Users table again just in case
echo "2. Searching 'users' table again...\n";
$users = User::all();
$foundUser = false;
foreach ($users as $u) {
    if (! $u->whatsapp_number) {
        continue;
    }

    $cleanedUserWa = $service->cleanPhoneNumber($u->whatsapp_number);
    if ($cleanedUserWa == $cleanedInput) {
        echo "   🔴 FOUND USER with matching number!\n";
        echo "      ID: {$u->id}\n";
        echo "      Name: {$u->name}\n";
        echo "      WA: {$u->whatsapp_number}\n";
        $foundUser = true;

        echo "      🗑️ DELETING this user...\n";
        $u->delete();
        echo "      ✅ Deleted.\n";
    }
}

if (! $foundUser) {
    echo "   ✅ No matching users found.\n";
}

echo "\nDone.\n";
