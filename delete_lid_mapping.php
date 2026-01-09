<?php

/**
 * DELETE SPECIFIC LID MAPPING
 * Remove LID 218442590343379 from user Nayshila
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DELETE LID MAPPING ===\n\n";

$mappingId = 145;
$lid = '218442590343379';

// Get mapping details first
$mapping = DB::table('user_whatsapp_numbers')
    ->where('id', $mappingId)
    ->first();

if (!$mapping) {
    echo "❌ Mapping not found\n";
    exit;
}

echo "Found mapping:\n";
echo "  Mapping ID: {$mapping->id}\n";
echo "  User ID: {$mapping->user_id}\n";
echo "  WhatsApp Number: {$mapping->whatsapp_number}\n";
echo "  Tenant ID: {$mapping->tenant_id}\n\n";

// Get user details
$user = DB::table('users')->where('id', $mapping->user_id)->first();
if ($user) {
    echo "  User: {$user->email}\n\n";
}

echo "⚠️  This will delete the LID mapping!\n";
echo "Delete? (yes/no): ";

$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) !== 'yes') {
    echo "❌ Cancelled\n";
    exit;
}

// Delete mapping
DB::table('user_whatsapp_numbers')
    ->where('id', $mappingId)
    ->delete();

echo "\n✅ Mapping deleted!\n";
echo "LID {$lid} is no longer linked to any user.\n";
echo "You will not be able to send messages until you register again.\n";

echo "\n=== DONE ===\n";
