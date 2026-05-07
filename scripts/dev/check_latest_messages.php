<?php

/**
 * CHECK LATEST MESSAGES FOR A GIVEN SEARCH STRING
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$search = $argv[1] ?? '55021716';

echo "=== LATEST MESSAGES FOR: {$search} ===\n\n";

$messages = DB::table('messages')
    ->where('sender_id', 'LIKE', '%'.$search.'%')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get(['id', 'sender_id', 'tenant_id', 'content', 'created_at']);

if ($messages->count() > 0) {
    echo "Found {$messages->count()} messages:\n";
    foreach ($messages as $msg) {
        echo "[ID: {$msg->id}] [Time: {$msg->created_at}] [Tenant: {$msg->tenant_id}]\n";
        echo "Sender: {$msg->sender_id}\n";
        echo "Content: {$msg->content}\n";
        echo "---------------------------------\n";
    }
} else {
    echo "No messages found for search string: {$search}\n\n";

    echo "=== GLOBAL LATEST 10 MESSAGES ===\n";
    $allMessages = DB::table('messages')
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get(['id', 'sender_id', 'tenant_id', 'content', 'created_at']);

    foreach ($allMessages as $msg) {
        echo "[ID: {$msg->id}] [Time: {$msg->created_at}] [Tenant: {$msg->tenant_id}]\n";
        echo "Sender: {$msg->sender_id}\n";
        echo "Content: {$msg->content}\n";
        echo "---------------------------------\n";
    }
}

echo "\nDone.\n";
