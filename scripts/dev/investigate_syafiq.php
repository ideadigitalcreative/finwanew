<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$email = 'syafiqnaufalfikri@gmail.com';
$name = 'Syafiq Naufal';

echo "Investigating User: $name ($email)".PHP_EOL.PHP_EOL;

$user = App\Models\User::where('email', $email)
    ->orWhere('name', 'LIKE', "%$name%")
    ->first();

if (! $user) {
    echo 'User NOT FOUND in database.'.PHP_EOL;
    exit;
}

echo '--- USER DATA ---'.PHP_EOL;
echo 'ID: '.$user->id.PHP_EOL;
echo 'Name: '.$user->name.PHP_EOL;
echo 'Email: '.$user->email.PHP_EOL;
echo 'WhatsApp Number (User): '.($user->whatsapp_number ?? 'NULL').PHP_EOL;
echo 'Tenant ID: '.($user->tenant_id ?? 'NULL').PHP_EOL;
echo 'Current Tenant ID: '.($user->current_tenant_id ?? 'NULL').PHP_EOL;
echo 'Is Super Admin: '.($user->is_super_admin ? 'YES' : 'NO').PHP_EOL;
echo 'Created At: '.$user->created_at.PHP_EOL;
echo 'Updated At: '.$user->updated_at.PHP_EOL;

if ($user->tenant) {
    echo PHP_EOL.'--- TENANT DATA ---'.PHP_EOL;
    $tenant = $user->tenant;
    echo 'ID: '.$tenant->id.PHP_EOL;
    echo 'Name: '.$tenant->name.PHP_EOL;
    echo 'Is Active: '.($tenant->is_active ? 'YES' : 'NO').PHP_EOL;
    echo 'Trial Ends: '.($tenant->trial_ends_at ? $tenant->trial_ends_at->format('Y-m-d H:i:s') : 'NULL').PHP_EOL;

    $subscription = $tenant->subscriptions()->orderBy('id', 'desc')->first();
    if ($subscription) {
        echo 'Latest Subscription: '.$subscription->plan.' ('.$subscription->status.')'.PHP_EOL;
        echo 'Ends: '.($subscription->ends_at ? $subscription->ends_at->format('Y-m-d H:i:s') : 'NULL').PHP_EOL;
        echo 'Is Active (Model Check): '.($subscription->isActive() ? 'YES' : 'NO').PHP_EOL;
    } else {
        echo 'No Subscription found.'.PHP_EOL;
    }
}

$whatsappNumbers = App\Models\UserWhatsAppNumber::where('user_id', $user->id)->get();
if ($whatsappNumbers->isNotEmpty()) {
    echo PHP_EOL.'--- WHATSAPP MAPPINGS ---'.PHP_EOL;
    foreach ($whatsappNumbers as $wn) {
        echo 'Number: '.$wn->whatsapp_number.' | Primary: '.($wn->is_primary ? 'Y' : 'N').' | Active: '.($wn->is_active ? 'Y' : 'N').' | LID: '.($wn->is_lid ? 'Y' : 'N').PHP_EOL;
    }
} else {
    echo PHP_EOL.'No UserWhatsAppNumbers found.'.PHP_EOL;
}

// Check for recent messages
if (class_exists('App\Models\Message')) {
    $messages = App\Models\Message::where('tenant_id', $user->tenant_id)
        ->orderBy('id', 'desc')
        ->limit(10)
        ->get();

    if ($messages->isNotEmpty()) {
        echo PHP_EOL.'--- RECENT MESSAGES (TENANT) ---'.PHP_EOL;
        foreach ($messages as $msg) {
            echo '['.$msg->created_at.'] '.($msg->sender_id ?? 'Unknown').': '.substr($msg->content ?? '', 0, 50).' ('.$msg->status.')'.PHP_EOL;
        }
    } else {
        echo PHP_EOL.'No messages found for this tenant.'.PHP_EOL;
    }
}
