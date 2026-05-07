<?php

use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsAppUserMappingService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$inputNumber = '6285604676142'; // The number reporting issues
$service = new WhatsAppUserMappingService;

echo "🔍 Debugging Mapping Service for: $inputNumber\n";
$cleanedInput = $service->cleanPhoneNumber($inputNumber);
echo "ℹ️ Cleaned Input: $cleanedInput\n\n";

// 1. Test getTenantIdFromWhatsAppNumber
echo "1. Testing getTenantIdFromWhatsAppNumber...\n";
$tenantId = $service->getTenantIdFromWhatsAppNumber($inputNumber);
if ($tenantId) {
    echo "   🔴 RESULT: Returned Tenant ID: $tenantId\n";
} else {
    echo "   ✅ RESULT: Returned NULL (Correct if user doesn't exist)\n";
}

echo "\n";

// 2. Test findUserByWhatsAppNumber
echo "2. Testing findUserByWhatsAppNumber...\n";
$user = $service->findUserByWhatsAppNumber($inputNumber);
if ($user) {
    echo "   🔴 RESULT: Found User!\n";
    echo "      ID: {$user->id}\n";
    echo "      Name: {$user->name}\n";
    echo "      WA: {$user->whatsapp_number}\n";
    echo '      Cleaned DB WA: '.$service->cleanPhoneNumber($user->whatsapp_number)."\n";

    // We should fix/delete this user
    echo "\n   ⚠️ AUTOMATIC FIX: Deleting ghost user found by Service...\n";
    \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
        \Illuminate\Support\Facades\DB::table('user_tenants')->where('user_id', $user->id)->delete();
        $user->delete();
    });
    echo "   ✅ Deleted.\n";

} else {
    echo "   ✅ RESULT: User NOT found.\n";
}

echo "\n";

// 3. Test Manual Scan with Service Cleaning Logic
echo "3. Manual Scan with Cleaning Logic...\n";
$users = User::all(); // Yes, slow, but we need to be sure
$foundAny = false;
foreach ($users as $u) {
    if (! $u->whatsapp_number) {
        continue;
    }
    $c = $service->cleanPhoneNumber($u->whatsapp_number);
    if ($c === $cleanedInput) {
        echo "   🔴 FOUND MATCH via Manual Scan!\n";
        echo "      User ID: {$u->id} | WA: {$u->whatsapp_number} | Cleaned: $c\n";
        // We should fix/delete this user
        echo "      ⚠️ AUTOMATIC FIX: Deleting ghost user...\n";
        \Illuminate\Support\Facades\DB::transaction(function () use ($u) {
            \Illuminate\Support\Facades\DB::table('user_tenants')->where('user_id', $u->id)->delete();
            $u->delete();
        });
        echo "      ✅ Deleted.\n";
        $foundAny = true;
    }
}

if (! $foundAny) {
    echo "   ✅ No matches found via manual scan.\n";
}

// 4. Test UserWhatsAppNumbers scan
if (class_exists(UserWhatsAppNumber::class)) {
    echo "\n4. Manual Scan UserWhatsAppNumbers...\n";
    $mappings = UserWhatsAppNumber::all();
    foreach ($mappings as $m) {
        $c = $service->cleanPhoneNumber($m->whatsapp_number);
        if ($c === $cleanedInput) {
            echo "   🔴 FOUND MAPPING MATCH!\n";
            echo "      ID: {$m->id} | WA: {$m->whatsapp_number} | Cleaned: $c\n";
            $m->delete();
            echo "      ✅ Deleted.\n";
        }
    }
}

echo "\nDone.\n";
