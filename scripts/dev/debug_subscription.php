<?php

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

// Search for the tenant
$searchName = "endri's Organization";
echo "🔍 Searching for tenant: $searchName\n\n";

$tenant = Tenant::where('name', 'LIKE', '%endri%')->first();

if (! $tenant) {
    echo "❌ Tenant not found.\n";
    exit;
}

echo "✅ Found Tenant: {$tenant->name} (ID: {$tenant->id})\n";
echo '   Trial Ends At: '.($tenant->trial_ends_at ? $tenant->trial_ends_at->format('Y-m-d H:i:s') : 'NULL')."\n";
echo '   Is Active: '.($tenant->is_active ? 'YES' : 'NO')."\n\n";

// Check subscriptions
echo "📋 Subscriptions:\n";
$subs = Subscription::where('tenant_id', $tenant->id)->get();

foreach ($subs as $sub) {
    echo "   - Plan: {$sub->plan} | Status: {$sub->status} | Ends: ".($sub->ends_at ? $sub->ends_at->format('Y-m-d') : 'NULL')."\n";
}

echo "\n";

// Simulate the checkSubscriptionStatus logic
echo "🕵️ Running logic checkSubscriptionStatus($tenant->id)...\n";
echo '   Current Time: '.now()->format('Y-m-d H:i:s')."\n";

$hasActiveSubscription = Subscription::where('tenant_id', $tenant->id)
    ->where('status', 'active')
    ->where(function ($query) {
        $query->whereNull('ends_at')
            ->orWhere('ends_at', '>', now());
    })
    ->exists(); // Using exists() like the service

$isInTrial = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture();

echo '   hasActiveSubscription: '.($hasActiveSubscription ? 'TRUE' : 'FALSE')."\n";
echo '   isInTrial: '.($isInTrial ? 'TRUE' : 'FALSE')."\n";

if ($hasActiveSubscription || $isInTrial) {
    echo "   ✅ RESULT: ALLOWED (One of the conditions met)\n";
} else {
    echo "   ❌ RESULT: BLOCKED (No active sub or trial)\n";
}
