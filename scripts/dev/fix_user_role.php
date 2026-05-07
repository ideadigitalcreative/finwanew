<?php

/**
 * FIX USER ROLE FOR WHATSAPP REGISTERED USERS
 * Set role to 'owner' for users without role
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

$email = $argv[1] ?? null;

echo "=== FIX USER ROLE ===\n\n";

if ($email) {
    // Fix specific user
    $users = User::where('email', $email)->get();
    if ($users->count() === 0) {
        echo "❌ User not found: {$email}\n";
        exit;
    }
} else {
    // Fix all users without role (registered in last 7 days)
    $users = User::where(function ($query) {
        $query->whereNull('role')
            ->orWhere('role', '');
    })
        ->where('created_at', '>=', now()->subDays(7))
        ->get();

    if ($users->count() === 0) {
        echo "No users found without role\n";
        exit;
    }
}

echo "Found {$users->count()} user(s) to fix:\n\n";

foreach ($users as $user) {
    echo "Fixing: {$user->email} (ID: {$user->id})\n";
    echo '  Current role: '.($user->role ?: '(empty)')."\n";

    // Set role to 'owner' (or 'admin' if you prefer)
    $user->role = 'owner';
    $user->save();

    echo "  ✅ Role set to: owner\n\n";
}

echo "=== SUMMARY ===\n";
echo "Fixed {$users->count()} user(s)\n";
echo "\nUsers can now login and access the system!\n";
