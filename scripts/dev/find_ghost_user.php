<?php

use App\Models\User;
use App\Models\UserWhatsAppNumber;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$targetNumber = '6285159205506'; // Normalized form of +62 851-5920-5506
$originalInput = '+62 851-5920-5506';

echo "🔍 Searching for user with number: $originalInput\n";
echo "ℹ️ Normalized search: $targetNumber\n\n";

// 1. Search in Users table (whatsapp_number column)
// We use raw logic since User model doesn't use SoftDeletes trait, based on User.php inspection
echo "1. Checking 'users' table (column: whatsapp_number)...\n";
$users = User::where('whatsapp_number', 'LIKE', '%85159205506%')
    ->orWhere('whatsapp_number', 'LIKE', '%851-5920-5506%')
    ->get(); // No withTrashed() because model doesn't have it

if ($users->count() > 0) {
    foreach ($users as $user) {
        echo "   Found: ID {$user->id} | Name: {$user->name} | Email: {$user->email} | WA: {$user->whatsapp_number}\n";
    }
} else {
    echo "   ❌ Not found in users table.\n";
}

echo "\n";

// 2. Search in UserWhatsAppNumbers table (if used)
if (class_exists(UserWhatsAppNumber::class)) {
    echo "2. Checking 'user_whatsapp_numbers' table...\n";
    $mappings = UserWhatsAppNumber::where('whatsapp_number', 'LIKE', '%85159205506%')
        ->orWhere('whatsapp_number', 'LIKE', '%851-5920-5506%')
        ->get();

    if ($mappings->count() > 0) {
        foreach ($mappings as $map) {
            echo "   Found: ID {$map->id} | User ID: {$map->user_id} | WA: {$map->whatsapp_number} | Active: {$map->is_active}\n";
            // Check if user exists for this mapping
            $linkedUser = User::find($map->user_id);
            if ($linkedUser) {
                echo "      -> Linked to User: {$linkedUser->name}\n";
            } else {
                echo "      -> Linked to User ID {$map->user_id} (User record NOT FOUND)\n";
            }
        }
    } else {
        echo "   ❌ Not found in user_whatsapp_numbers table.\n";
    }
} else {
    echo "2. 'UserWhatsAppNumber' model not found. Skipping.\n";
}

echo "\n";

// 3. Check specific variation 0851...
echo "3. Checking variations (0851...)\n";
$usersVar = User::where('whatsapp_number', 'LIKE', '085159205506')
    ->get();
if ($usersVar->count() > 0) {
    foreach ($usersVar as $user) {
        echo "   Found (08...): ID {$user->id} | {$user->name}\n";
    }
} else {
    echo "   ❌ Not found with 08 prefix.\n";
}

echo "\nDone.\n";
