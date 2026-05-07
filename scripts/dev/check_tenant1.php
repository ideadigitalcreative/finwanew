<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== RECENT MESSAGES TO TENANT 1 ===\n\n";

$messages = DB::table('messages')
    ->where('tenant_id', 1)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get(['id', 'sender_id', 'tenant_id', 'content', 'created_at']);

foreach ($messages as $msg) {
    echo "[ID: {$msg->id}] [Time: {$msg->created_at}] [Tenant: {$msg->tenant_id}]\n";
    echo "Sender: {$msg->sender_id}\n";
    echo "Content: {$msg->content}\n";
    echo "---------------------------------\n";
}

echo "=== RECENT MESSAGES FROM ANY SENDER THAT ARE LIDs ===\n\n";

$lids = DB::table('messages')
    ->where('sender_id', 'REGEXP', '^[0-9]{13,15}$')
    ->where('sender_id', 'NOT REGEXP', '^628')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get(['id', 'sender_id', 'tenant_id', 'content', 'created_at']);

foreach ($lids as $msg) {
    echo "[ID: {$msg->id}] [Time: {$msg->created_at}] [Tenant: {$msg->tenant_id}]\n";
    echo "Sender: {$msg->sender_id}\n";
    echo "Content: {$msg->content}\n";
    echo "---------------------------------\n";
}
