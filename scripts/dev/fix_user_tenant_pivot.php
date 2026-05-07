<?php

/**
 * FIX USER TENANT RELATIONSHIP
 * Create pivot table entry for WhatsApp registered users
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;

$email = $argv[1] ?? null;

echo "=== FIX USER TENANT RELATIONSHIP ===\n\n";

if ($email) {
    $users = User::where('email', $email)->get();
    if ($users->count() === 0) {
        echo "❌ User not found: {$email}\n";
        exit;
    }
} else {
    // Fix all users without pivot entry (registered in last 7 days)
    $users = User::where('created_at', '>=', now()->subDays(7))
        ->whereNotNull('tenant_id')
        ->get()
        ->filter(function ($user) {
            return $user->tenants()->count() === 0;
        });

    if ($users->count() === 0) {
        echo "No users found without tenant relationship\n";
        exit;
    }
}

echo "Found {$users->count()} user(s) to fix:\n\n";

// Get owner role_id
$ownerRole = DB::table('roles')->where('name', 'owner')->first();
$adminRole = DB::table('roles')->where('name', 'admin')->first();

if (! $ownerRole && ! $adminRole) {
    echo "❌ No 'owner' or 'admin' role found in roles table\n";
    echo "\nAvailable roles:\n";
    $roles = DB::table('roles')->get();
    foreach ($roles as $role) {
        echo "  - {$role->name} (ID: {$role->id})\n";
    }
    exit;
}

$roleId = $ownerRole ? $ownerRole->id : $adminRole->id;
$roleName = $ownerRole ? 'owner' : 'admin';

echo "Using role: {$roleName} (ID: {$roleId})\n\n";

foreach ($users as $user) {
    echo "Fixing: {$user->email} (ID: {$user->id})\n";
    echo "  Tenant ID: {$user->tenant_id}\n";

    // Check if pivot entry already exists
    $exists = DB::table('user_tenants')
        ->where('user_id', $user->id)
        ->where('tenant_id', $user->tenant_id)
        ->exists();

    if ($exists) {
        echo "  ⏭️  Pivot entry already exists\n\n";

        continue;
    }

    // Create pivot entry
    DB::table('user_tenants')->insert([
        'user_id' => $user->id,
        'tenant_id' => $user->tenant_id,
        'role_id' => $roleId,
        'is_active' => true,
        'joined_at' => $user->created_at,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo "  ✅ Pivot entry created with role: {$roleName}\n\n";
}

echo "=== SUMMARY ===\n";
echo "Fixed {$users->count()} user(s)\n";
echo "\nUsers can now login and access the system!\n";
