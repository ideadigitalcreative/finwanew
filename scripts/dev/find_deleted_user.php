<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$searchTerm = 'Makan dimsum 18k';
$tenantId = 10213;

echo "=== Searching for specific message: '$searchTerm' ===\n\n";

// 1. Find the message in the messages table
$messages = DB::table('messages')
    ->where('content', 'LIKE', '%'.$searchTerm.'%')
    ->orderBy('created_at', 'desc')
    ->get();

if ($messages->isEmpty()) {
    echo "   Message not found in database.\n";
} else {
    foreach ($messages as $msg) {
        echo "   - ID: {$msg->id}\n";
        echo "   - Tenant ID: {$msg->tenant_id}\n";
        echo "   - Sender ID: {$msg->sender_id}\n";
        echo "   - Created At: {$msg->created_at}\n";
        echo "   - Content: {$msg->content}\n";
        echo "   - Metadata: {$msg->metadata}\n\n";

        // Investigate this sender
        $senderId = $msg->sender_id;
        echo "   --- Investigating Sender ID: $senderId ---\n";

        // Search in users
        $u = DB::table('users')->where('whatsapp_number', $senderId)->first();
        if ($u) {
            echo "     - Found in Users: ID {$u->id}, Name: {$u->name}, Tenant ID: {$u->tenant_id}\n";
        } else {
            echo "     - Not found in Users table.\n";
        }

        // Search in user_whatsapp_numbers
        $wn = DB::table('user_whatsapp_numbers')->where('whatsapp_number', $senderId)->first();
        if ($wn) {
            echo "     - Found in user_whatsapp_numbers: ID {$wn->id}, User ID: {$wn->user_id}, Tenant ID: {$wn->tenant_id}\n";
        } else {
            echo "     - Not found in user_whatsapp_numbers.\n";
        }

        // Search in user_lid_mappings
        $lm = DB::table('user_lid_mappings')->where('lid', $senderId)->orWhere('phone_number', $senderId)->first();
        if ($lm) {
            echo "     - Found in user_lid_mappings: ID {$lm->id}, User ID: {$lm->user_id}, Tenant ID: {$lm->tenant_id}, LID: {$lm->lid}\n";
        } else {
            echo "     - Not found in user_lid_mappings.\n";
        }
    }
}
echo "\n";

// 2. Check the most recent messages for Tenant 10213
echo "=== Most recent messages for Tenant $tenantId ===\n";
$recentMsgs = DB::table('messages')
    ->where('tenant_id', $tenantId)
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

foreach ($recentMsgs as $rm) {
    echo "   - ID: {$rm->id}, Sender: {$rm->sender_id}, Content: ".substr($rm->content, 0, 50)."..., Time: {$rm->created_at}\n";
}
echo "\n";

echo "=== Search Complete ===\n";

echo "=== Search Complete ===\n";
