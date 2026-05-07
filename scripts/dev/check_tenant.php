<?php

/**
 * CHECK TENANT 10024
 * See what's in this tenant
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$tenantId = $argv[1] ?? 10024;

echo "=== CHECKING TENANT {$tenantId} ===\n\n";

// Check tenant
$tenant = DB::table('tenants')->where('id', $tenantId)->first();

if (! $tenant) {
    echo "❌ Tenant not found\n";
    exit;
}

echo "Tenant Details:\n";
echo "  ID: {$tenant->id}\n";
echo "  Name: {$tenant->name}\n";
echo "  Slug: {$tenant->slug}\n";
echo '  Active: '.($tenant->is_active ? 'Yes' : 'No')."\n";
echo "  Created: {$tenant->created_at}\n\n";

// Check users in this tenant
echo "USERS IN THIS TENANT:\n";
$users = DB::table('users')
    ->where('tenant_id', $tenantId)
    ->get(['id', 'name', 'email', 'whatsapp_number', 'created_at']);

if ($users->count() === 0) {
    echo "  ✅ No users found\n";
} else {
    foreach ($users as $user) {
        echo "  - User ID: {$user->id}\n";
        echo "    Name: {$user->name}\n";
        echo "    Email: {$user->email}\n";
        echo "    WhatsApp: {$user->whatsapp_number}\n";
        echo "    Created: {$user->created_at}\n\n";
    }
}

// Check WhatsApp mappings for this tenant
echo "WHATSAPP MAPPINGS IN THIS TENANT:\n";
$mappings = DB::table('user_whatsapp_numbers')
    ->where('tenant_id', $tenantId)
    ->get();

if ($mappings->count() === 0) {
    echo "  ✅ No mappings found\n";
} else {
    foreach ($mappings as $mapping) {
        echo "  - Mapping ID: {$mapping->id}\n";
        echo "    User ID: {$mapping->user_id}\n";
        echo "    WhatsApp: {$mapping->whatsapp_number}\n";
        echo '    Is LID: '.($mapping->is_lid ? 'Yes' : 'No')."\n";

        // Check if user exists
        $user = DB::table('users')->where('id', $mapping->user_id)->first();
        if (! $user) {
            echo "    ⚠️  USER DELETED (orphaned mapping)\n";
        }
        echo "\n";
    }
}

// Check recent messages
echo "RECENT MESSAGES TO THIS TENANT:\n";
$messages = DB::table('messages')
    ->where('tenant_id', $tenantId)
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get(['id', 'sender_id', 'content', 'created_at']);

foreach ($messages as $msg) {
    echo "  - [{$msg->created_at}] {$msg->sender_id}: ".substr($msg->content, 0, 30)."\n";
}

echo "\n=== DONE ===\n";
