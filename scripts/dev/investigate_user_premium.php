<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$numbers = [
    '6285242766676',
    '85242766676',
    '085242766676',
    '6285255021716',
    '85255021716',
    '085255021716',
];

echo "🔍 Searching for User ID 12...\n";
$u12 = User::find(12);
if ($u12) {
    echo "   [ID 12] Found: {$u12->name}, Email: {$u12->email}, WA: {$u12->whatsapp_number}\n";
    $tenant = $u12->tenant;
    if ($tenant) {
        echo "      Tenant ID: {$tenant->id}, Name: {$tenant->name}\n";
        $sub = DB::table('subscriptions')->where('tenant_id', $tenant->id)->where('status', 'active')->first();
        if ($sub) {
            echo "      Active Subscription: {$sub->plan} until {$sub->ends_at}\n";
        } else {
            echo "      ❌ No active subscription for User 12's Tenant\n";
        }
    }
} else {
    echo "   [ID 12] Not found\n";
}
echo "\n";

echo "🔍 Investigating numbers and searching for premium accounts...\n\n";

foreach ($numbers as $num) {
    echo "--- Checking Number: $num ---\n";

    // 1. Check users table by whatsapp_number
    $users = User::where('whatsapp_number', 'LIKE', "%$num%")->get();

    // 2. Check users by email
    $usersByEmail = User::where('email', 'LIKE', "%$num%")->get();

    // Merge results
    $allUsers = $users->merge($usersByEmail)->unique('id');

    if ($allUsers->count() > 0) {
        foreach ($allUsers as $user) {
            echo "   [USERS TABLE] Found ID: {$user->id}, Name: {$user->name}, Email: {$user->email}, WA: {$user->whatsapp_number}\n";
            $tenant = $user->tenant;
            if ($tenant) {
                echo "      Tenant ID: {$tenant->id}, Name: {$tenant->name}\n";
                echo '      Subscription: '.($tenant->subscription_type ?: 'FREE').', Ends: '.($tenant->subscription_ends_at ?: 'NULL')."\n";
                echo '      Trial Ends: '.($tenant->trial_ends_at ?: 'NULL')."\n";
                echo '      Is Active: '.($tenant->is_active ? 'YES' : 'NO')."\n";
            }
        }
    } else {
        echo "   [USERS TABLE] Not found\n";
    }

    // 3. Check user_whatsapp_numbers table
    $mappings = DB::table('user_whatsapp_numbers')->where('whatsapp_number', 'LIKE', "%$num%")->get();
    if ($mappings->count() > 0) {
        foreach ($mappings as $mapping) {
            echo "   [MAPPINGS] Found ID: {$mapping->id}, WA: {$mapping->whatsapp_number}, User ID: {$mapping->user_id}\n";
            $u = User::find($mapping->user_id);
            if ($u) {
                echo "      Linked User: {$u->name} ({$u->whatsapp_number})\n";
            } else {
                echo "      ⚠️ Linked User NOT FOUND\n";
            }
        }
    } else {
        echo "   [MAPPINGS] Not found\n";
    }
    echo "\n";
}

echo "--- Searching for all Active/Premium Subscriptions ---\n";
$activeSubscriptions = DB::table('subscriptions')
    ->where('status', 'active')
    ->where(function ($query) {
        $query->whereNull('ends_at')
            ->orWhere('ends_at', '>', now());
    })
    ->get();

if ($activeSubscriptions->count() > 0) {
    foreach ($activeSubscriptions as $sub) {
        $tenant = DB::table('tenants')->where('id', $sub->tenant_id)->first();
        if ($tenant) {
            echo "   Subscription Plan: {$sub->plan}, Status: {$sub->status}, Ends: {$sub->ends_at}\n";
            echo "      Tenant ID: {$tenant->id}, Name: {$tenant->name}\n";
            $users = User::where('tenant_id', $tenant->id)->get();
            foreach ($users as $u) {
                echo "      - User ID: {$u->id}, Name: {$u->name}, WA: {$u->whatsapp_number}, Email: {$u->email}\n";
            }
        }
    }
} else {
    echo "   ❌ No active subscriptions found in the database.\n";
}

echo "Done.\n";
