<?php

/**
 * AUTO VERIFY WHATSAPP REGISTERED USERS
 * Verify email for users registered via WhatsApp
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Subscription;
use App\Models\User;

echo "=== AUTO VERIFY WHATSAPP REGISTERED USERS ===\n\n";

// Find users registered via WhatsApp (check subscription metadata)
$whatsappUsers = User::whereNull('email_verified_at')
    ->where('created_at', '>=', now()->subDays(1)) // Last 24 hours
    ->get();

if ($whatsappUsers->count() === 0) {
    echo "No unverified users found in last 24 hours\n";
    exit;
}

echo "Found {$whatsappUsers->count()} unverified users:\n\n";

$verified = 0;

foreach ($whatsappUsers as $user) {
    // Check if user has subscription with whatsapp metadata
    $subscription = Subscription::where('tenant_id', $user->tenant_id)
        ->whereRaw("JSON_EXTRACT(metadata, '$.registered_via') = 'whatsapp'")
        ->first();

    if ($subscription) {
        echo "✅ Verifying: {$user->email} (ID: {$user->id})\n";
        $user->email_verified_at = now();
        $user->save();
        $verified++;
    } else {
        echo "⏭️  Skipping: {$user->email} (not registered via WhatsApp)\n";
    }
}

echo "\n=== SUMMARY ===\n";
echo "Total verified: {$verified} users\n";

if ($verified > 0) {
    echo "\n✅ Users can now login!\n";
}
