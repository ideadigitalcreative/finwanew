<?php

/**
 * CHECK USER TENANT STATUS
 * Debug tenant issue for user
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Tenant;
use App\Models\User;

$email = $argv[1] ?? 'dandan21@gmail.com';

echo "=== CHECKING USER TENANT STATUS ===\n\n";

// 1. Find user
$user = User::where('email', $email)->first();

if (! $user) {
    echo "❌ User not found: {$email}\n";
    exit;
}

echo "✅ User found:\n";
echo "  ID: {$user->id}\n";
echo "  Name: {$user->name}\n";
echo "  Email: {$user->email}\n";
echo "  Tenant ID: {$user->tenant_id}\n\n";

// 2. Check tenant
echo "Checking tenant...\n";
$tenant = Tenant::find($user->tenant_id);

if (! $tenant) {
    echo "❌ Tenant NOT found with ID: {$user->tenant_id}\n";
    echo "\nThis is the problem! User has tenant_id but tenant doesn't exist.\n";
    exit;
}

echo "✅ Tenant found:\n";
echo "  ID: {$tenant->id}\n";
echo "  Name: {$tenant->name}\n";
echo "  Slug: {$tenant->slug}\n";
echo '  Active: '.($tenant->is_active ? 'YES' : 'NO')."\n";
echo "  Trial Ends: {$tenant->trial_ends_at}\n";
echo "  Created: {$tenant->created_at}\n\n";

if (! $tenant->is_active) {
    echo "❌ PROBLEM: Tenant is NOT active!\n\n";
    echo "Fixing...\n";
    $tenant->is_active = true;
    $tenant->save();
    echo "✅ Tenant activated!\n\n";
} else {
    echo "✅ Tenant is active\n\n";
}

// 3. Check user-tenant relationship
echo "Checking user-tenant relationship...\n";

// Check if user belongs to tenant
$userBelongsToTenant = $user->tenant_id === $tenant->id;
echo 'User belongs to tenant: '.($userBelongsToTenant ? 'YES' : 'NO')."\n";

// Check tenant users
$tenantUsers = User::where('tenant_id', $tenant->id)->count();
echo "Total users in this tenant: {$tenantUsers}\n\n";

// 4. Check subscription
echo "Checking subscription...\n";
$subscription = \App\Models\Subscription::where('tenant_id', $tenant->id)
    ->where('status', 'active')
    ->first();

if ($subscription) {
    echo "✅ Active subscription found:\n";
    echo "  Plan: {$subscription->plan}\n";
    echo "  Status: {$subscription->status}\n";
    echo "  Starts: {$subscription->starts_at}\n";
    echo "  Ends: {$subscription->ends_at}\n";
} else {
    echo "❌ No active subscription found!\n";
    echo "\nThis might be the problem. Creating free subscription...\n";

    \App\Models\Subscription::create([
        'tenant_id' => $tenant->id,
        'plan' => 'free',
        'duration_months' => 1,
        'price' => 0,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addDays(30),
        'payment_provider' => 'free_trial',
        'metadata' => [
            'registered_via' => 'whatsapp',
            'fixed_at' => now()->toIso8601String(),
        ],
    ]);

    echo "✅ Free subscription created!\n";
}

echo "\n=== SUMMARY ===\n";
echo "User should now be able to login and access the system.\n";
echo "Try logging in again!\n";
