<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== CHECKING USER 6285714725102 ===\n\n";

$mapping = \App\Models\UserWhatsAppNumber::where('whatsapp_number', '6285714725102')->first();

if ($mapping) {
    $user = \App\Models\User::find($mapping->user_id);
    $tenant = \App\Models\Tenant::find($mapping->tenant_id);

    echo "User: {$user->name} (ID: {$user->id})\n";
    echo "Tenant: {$tenant->name} (ID: {$tenant->id})\n";
    echo "Email: {$user->email}\n\n";

    $subscription = \App\Models\Subscription::where('tenant_id', $tenant->id)
        ->where('status', 'active')
        ->first();

    if ($subscription) {
        echo "✅ ACTIVE SUBSCRIPTION:\n";
        echo "  Plan: {$subscription->plan}\n";
        echo "  Status: {$subscription->status}\n";
        echo "  Starts: {$subscription->starts_at}\n";
        echo "  Ends: {$subscription->ends_at}\n";

        $daysRemaining = $subscription->ends_at->diffInDays(now());
        $isExpired = $subscription->ends_at < now();

        if ($isExpired) {
            echo "  ❌ EXPIRED {$daysRemaining} days ago!\n";
        } else {
            echo "  ✅ Days remaining: {$daysRemaining}\n";
        }
    } else {
        echo "❌ NO ACTIVE SUBSCRIPTION!\n\n";
        echo "All subscriptions:\n";
        $allSubs = \App\Models\Subscription::where('tenant_id', $tenant->id)
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($allSubs as $sub) {
            $status = $sub->status;
            $expired = $sub->ends_at < now() ? '(EXPIRED)' : '';
            echo "  - {$sub->plan} ({$status}) ends: {$sub->ends_at} {$expired}\n";
        }
    }
} else {
    echo "❌ User NOT FOUND\n";
}
