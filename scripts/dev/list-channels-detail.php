<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Channel;

echo "📋 Channels in Database (Detailed):\n";
echo "====================================\n\n";

$channels = Channel::all();

if ($channels->count() === 0) {
    echo "❌ No channels found in database!\n";
    exit(1);
}

foreach ($channels as $channel) {
    $config = $channel->config ?? [];
    $sessionId = $config['session_id'] ?? 'NULL';
    
    echo "ID: {$channel->id}\n";
    echo "Tenant ID: {$channel->tenant_id}\n";
    echo "Type: {$channel->type}\n";
    echo "Account: {$channel->channel_account}\n";
    echo "Session ID: {$sessionId}\n";
    echo "Active: " . ($channel->is_active ? 'Yes' : 'No') . "\n";
    echo "---\n";
}

echo "\nTotal: {$channels->count()} channel(s)\n";
