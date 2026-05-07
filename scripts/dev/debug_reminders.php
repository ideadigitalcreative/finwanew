<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Subscription;
use Carbon\Carbon;

echo "=== Subscription Reminder Debug ===\n";
echo 'Current Time: '.now()."\n\n";

// Check for H-3 (3 days before)
$targetDate3 = Carbon::now()->addDays(3)->startOfDay();
$nextDay3 = Carbon::now()->addDays(4)->startOfDay();
echo "H-3 Range: {$targetDate3} to {$nextDay3}\n";

$subsH3 = Subscription::where('status', 'active')
    ->whereNotNull('ends_at')
    ->whereBetween('ends_at', [$targetDate3, $nextDay3])
    ->count();
echo "Found {$subsH3} subscriptions expiring in 3 days\n\n";

// Check for H-1 (1 day before)
$targetDate1 = Carbon::now()->addDays(1)->startOfDay();
$nextDay1 = Carbon::now()->addDays(2)->startOfDay();
echo "H-1 Range: {$targetDate1} to {$nextDay1}\n";

$subsH1 = Subscription::where('status', 'active')
    ->whereNotNull('ends_at')
    ->whereBetween('ends_at', [$targetDate1, $nextDay1])
    ->count();
echo "Found {$subsH1} subscriptions expiring in 1 day\n\n";

// Show upcoming expirations
echo "=== Upcoming Active Subscriptions (next 7 days) ===\n";
$upcoming = Subscription::where('status', 'active')
    ->whereNotNull('ends_at')
    ->where('ends_at', '>', now())
    ->where('ends_at', '<', now()->addDays(7))
    ->with('tenant.users')
    ->orderBy('ends_at')
    ->get();

if ($upcoming->count() > 0) {
    foreach ($upcoming as $sub) {
        $user = $sub->tenant?->users()->whereNotNull('whatsapp_number')->first();
        $phone = $user ? $user->whatsapp_number : 'No phone';
        echo "- Sub #{$sub->id}: Tenant #{$sub->tenant_id}, Plan: {$sub->plan}, Ends: {$sub->ends_at}, Phone: {$phone}\n";
    }
} else {
    echo "No subscriptions expiring in the next 7 days\n";
}

echo "\n=== All Active Future Subscriptions (sample) ===\n";
$allActive = Subscription::where('status', 'active')
    ->whereNotNull('ends_at')
    ->where('ends_at', '>', now())
    ->orderBy('ends_at')
    ->limit(5)
    ->get();

foreach ($allActive as $sub) {
    $daysLeft = now()->diffInDays($sub->ends_at);
    echo "- Sub #{$sub->id}: Ends: {$sub->ends_at} ({$daysLeft} days left)\n";
}
