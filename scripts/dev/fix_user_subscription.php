<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== FIXING USER 6285714725102 SUBSCRIPTION ===\n\n";

$mapping = \App\Models\UserWhatsAppNumber::where('whatsapp_number', '6285714725102')->first();
$user = \App\Models\User::find($mapping->user_id);
$tenant = \App\Models\Tenant::find($mapping->tenant_id);

echo "User: {$user->name}\n";
echo 'Current time: '.now()->format('Y-m-d H:i:s')."\n\n";

$subscription = \App\Models\Subscription::where('tenant_id', $tenant->id)
    ->where('status', 'active')
    ->first();

if ($subscription) {
    echo "Current subscription:\n";
    echo "  Starts: {$subscription->starts_at}\n";
    echo "  Ends: {$subscription->ends_at}\n";
    echo "  Status: {$subscription->status}\n\n";

    // Check if expired
    if ($subscription->ends_at < now()) {
        echo "❌ Subscription EXPIRED!\n";
        echo "Updating status to 'expired'...\n";

        $subscription->update(['status' => 'expired']);

        echo "✅ Status updated to 'expired'\n\n";

        // Create new 30-day free trial
        echo "Creating new 30-day free trial...\n";
        $newSub = \App\Models\Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => 'free',
            'duration_months' => 1,
            'price' => 0,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'payment_provider' => 'internal',
            'metadata' => [
                'renewed_from' => 'manual_fix',
                'renewed_at' => now()->toIso8601String(),
            ],
        ]);

        echo "✅ New subscription created!\n";
        echo "  Starts: {$newSub->starts_at}\n";
        echo "  Ends: {$newSub->ends_at}\n";
        echo "  Days: 30\n";
    } else {
        echo "✅ Subscription is still valid\n";
        echo 'Days remaining: '.now()->diffInDays($subscription->ends_at)."\n";
    }
}
