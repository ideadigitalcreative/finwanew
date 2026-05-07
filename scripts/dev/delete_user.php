<?php

/**
 * DELETE USER BY EMAIL OR ID
 * Properly delete user and all related data
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;

$identifier = $argv[1] ?? null;

if (! $identifier) {
    echo "Usage: php delete_user.php <email_or_id>\n";
    echo "Example: php delete_user.php haerulhadi00@gmail.com\n";
    echo "Example: php delete_user.php 12\n";
    exit;
}

echo "=== DELETE USER ===\n\n";

// Find user
if (is_numeric($identifier)) {
    $user = User::find($identifier);
} else {
    $user = User::where('email', $identifier)->first();
}

if (! $user) {
    echo "❌ User not found: {$identifier}\n";
    exit;
}

echo "Found user:\n";
echo "  ID: {$user->id}\n";
echo "  Name: {$user->name}\n";
echo "  Email: {$user->email}\n";
echo "  WhatsApp: {$user->whatsapp_number}\n";
echo "  Tenant ID: {$user->tenant_id}\n\n";

// Check related data
$whatsappMappings = DB::table('user_whatsapp_numbers')
    ->where('user_id', $user->id)
    ->count();

$pivotEntries = DB::table('user_tenants')
    ->where('user_id', $user->id)
    ->count();

$messages = DB::table('messages')
    ->where('sender_id', $user->whatsapp_number)
    ->count();

echo "Related data:\n";
echo "  WhatsApp mappings: {$whatsappMappings}\n";
echo "  Tenant memberships: {$pivotEntries}\n";
echo "  Messages sent: {$messages}\n\n";

echo "⚠️  WARNING: This will permanently delete the user and related data!\n";
echo 'Continue? (yes/no): ';

$handle = fopen('php://stdin', 'r');
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) !== 'yes') {
    echo "❌ Cancelled\n";
    exit;
}

echo "\nDeleting user...\n";

// Delete related data manually (in case boot method not working)
DB::table('user_whatsapp_numbers')
    ->where('user_id', $user->id)
    ->delete();
echo "  ✅ Deleted {$whatsappMappings} WhatsApp mapping(s)\n";

DB::table('user_tenants')
    ->where('user_id', $user->id)
    ->delete();
echo "  ✅ Deleted {$pivotEntries} tenant membership(s)\n";

// Delete user
$user->delete();
echo "  ✅ User deleted\n\n";

echo "=== SUCCESS ===\n";
echo "User {$user->email} has been permanently deleted.\n";
echo "They will no longer be able to send messages to the bot.\n";
