<?php

use App\Models\User;
use App\Models\UserWhatsAppNumber;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

// Nomor dari user
$targetNumbers = [
    '6285604676142',
    '085604676142',
    '+62 856-0467-6142',
];

echo "🔍 Searching for +62 856-0467-6142...\n\n";

$found = false;

// 1. Search in Users table
echo "1. Checking 'users' table...\n";
foreach ($targetNumbers as $num) {
    // try exact match or like
    $users = User::where('whatsapp_number', 'LIKE', '%'.preg_replace('/[^0-9]/', '', $num).'%')->get();
    foreach ($users as $user) {
        $found = true;
        echo "   🔴 FOUND IN USERS!\n";
        echo "      ID: {$user->id}\n";
        echo "      Name: {$user->name}\n";
        echo "      Email: {$user->email}\n";
        echo "      WA: {$user->whatsapp_number}\n";

        // Cek status trialnya
        $tenantId = $user->tenant_id;
        if ($tenantId) {
            $tenant = \App\Models\Tenant::find($tenantId);
            echo "      Tenant: {$tenant->name} (ID: $tenantId)\n";
            echo '      Trial Ends: '.($tenant->trial_ends_at ? $tenant->trial_ends_at->format('Y-m-d H:i:s') : 'NULL')."\n";
            echo '      Is Active: '.($tenant->is_active ? 'YES' : 'NO')."\n";

            // Cek logic trial
            $isInTrial = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture();
            echo '      Is In Trial (Future): '.($isInTrial ? 'YES' : 'NO')."\n";

            // Perbaiki trial jika expired
            if (! $isInTrial) {
                echo "      ⚠️ Trial expired. FIXING NOW -> Adding 3 DAYS.\n";
                $tenant->trial_ends_at = now()->addDays(3);
                $tenant->save();
                echo "      ✅ Trial extended.\n";
            }
        }
    }
}

// 2. Search in UserWhatsAppNumber
if (class_exists(UserWhatsAppNumber::class)) {
    echo "\n2. Checking 'user_whatsapp_numbers' table...\n";
    foreach ($targetNumbers as $num) {
        $mappings = UserWhatsAppNumber::where('whatsapp_number', 'LIKE', '%'.preg_replace('/[^0-9]/', '', $num).'%')->get();
        foreach ($mappings as $map) {
            $found = true;
            echo "   🔴 FOUND IN MAPPINGS!\n";
            echo "      ID: {$map->id}\n";
            echo "      WA: {$map->whatsapp_number}\n";
            echo "      User ID: {$map->user_id}\n";

            // Check user linked
            $u = User::find($map->user_id);
            if ($u) {
                echo "      Linked User: {$u->name}\n";
            } else {
                echo "      ⚠️ Linked User NOT FOUND (Orphaned Mapping)\n";
                echo "      🗑️ Deleting orphan mapping...\n";
                $map->delete();
                echo "      ✅ Deleted.\n";
            }
        }
    }
}

if (! $found) {
    echo "\n✅ ACCOUNT NOT FOUND.\n";
    echo "This number is not registered in the database.\n";
    echo "This means the issue is likely in the 'Wait List' / 'Unregistered' logic or Cache.\n";

    // Clear cache just in case
    echo "🧹 Clearing unregistered warning cache for this number...\n";
    \Illuminate\Support\Facades\Cache::forget('unregistered_warning:6285604676142@c.us');
    \Illuminate\Support\Facades\Cache::forget('unregistered_warning:6285604676142');
    // Also clear expired cache
    \Illuminate\Support\Facades\Cache::forget('expired_subscription_reply:6285604676142@c.us');

    echo "✅ Cache cleared.\n";
}

echo "\nDone.\n";
