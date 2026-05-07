<?php

/**
 * DEBUG REGISTRATION FLOW
 * Check what's happening during registration
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Channel;
use App\Models\User;
use App\Services\WhatsAppService;

echo "=== DEBUG REGISTRATION FLOW ===\n\n";

// 1. Check latest registered user
echo "1. LATEST REGISTERED USER:\n";
$latestUser = User::orderBy('created_at', 'desc')->first();
if ($latestUser) {
    echo "  ID: {$latestUser->id}\n";
    echo "  Name: {$latestUser->name}\n";
    echo "  Email: {$latestUser->email}\n";
    echo "  Created: {$latestUser->created_at}\n";
    echo "  Tenant ID: {$latestUser->tenant_id}\n\n";
} else {
    echo "  No users found\n\n";
}

// 2. Check WhatsApp channel
echo "2. WHATSAPP CHANNEL:\n";
$channel = Channel::where('is_active', true)->first();
if ($channel) {
    echo "  ID: {$channel->id}\n";
    echo "  Name: {$channel->name}\n";
    echo '  Active: '.($channel->is_active ? 'Yes' : 'No')."\n";
    echo '  Config: '.json_encode($channel->config, JSON_PRETTY_PRINT)."\n\n";
} else {
    echo "  ❌ No active channel found\n\n";
}

// 3. Check recent logs
echo "3. RECENT REGISTRATION LOGS:\n";
$logFile = storage_path('logs/laravel.log');
if (file_exists($logFile)) {
    $lines = file($logFile);
    $recentLines = array_slice($lines, -100); // Last 100 lines

    $foundRegistration = false;
    foreach ($recentLines as $line) {
        if (stripos($line, 'registration') !== false) {
            echo $line;
            $foundRegistration = true;
        }
    }

    if (! $foundRegistration) {
        echo "  No registration logs in last 100 lines\n";
    }
} else {
    echo "  ❌ Log file not found\n";
}

echo "\n4. RECENT ERRORS:\n";
if (file_exists($logFile)) {
    $lines = file($logFile);
    $recentLines = array_slice($lines, -100);

    $foundError = false;
    foreach ($recentLines as $line) {
        if (stripos($line, 'error') !== false || stripos($line, 'failed') !== false) {
            echo $line;
            $foundError = true;
        }
    }

    if (! $foundError) {
        echo "  No errors in last 100 lines\n";
    }
}

echo "\n5. TEST WHATSAPP SERVICE:\n";
if ($channel) {
    try {
        $sessId = $channel->config['session_id'] ?? "wa_{$channel->tenant_id}_{$channel->channel_account}";
        echo "  Session ID: {$sessId}\n";

        $whatsappService = app(WhatsAppService::class);
        echo "  ✅ WhatsApp service initialized\n";

        // Try to get session status
        echo "  Attempting to check session...\n";

    } catch (\Exception $e) {
        echo '  ❌ Error: '.$e->getMessage()."\n";
    }
}

echo "\n=== END DEBUG ===\n";
