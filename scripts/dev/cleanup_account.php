<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$phoneNumber = '6285159205506';

echo "🧹 Starting cleanup for WhatsApp Number: $phoneNumber\n";

// 1. Find User(s)
$users = User::where('whatsapp_number', $phoneNumber)->get();

if ($users->isEmpty()) {
    echo "⚠️ No users found with this number in 'users' table.\n";
}

foreach ($users as $user) {
    echo "dumping user data to be deleted...\n";
    echo "User ID: {$user->id}, Name: {$user->name}, Email: {$user->email}\n";

    // Find Tenant(s) owned by this user
    $tenants = $user->tenants;

    DB::transaction(function () use ($user, $tenants) {
        // Delete UserTenant relations
        DB::table('user_tenants')->where('user_id', $user->id)->delete();
        echo "   ✅ Deleted user_tenants relations\n";

        // Delete WhatsApp Mappings if any
        if (class_exists(UserWhatsAppNumber::class)) {
            $count = UserWhatsAppNumber::where('user_id', $user->id)->delete();
            echo "   ✅ Deleted $count records from user_whatsapp_numbers\n";
        }

        // Delete the User
        $user->delete();
        echo "   ✅ Deleted User record\n";

        // Handling Tenants (Optional: Only delete if it's a personal/trial tenant to avoid deleting shared orgs)
        foreach ($tenants as $tenant) {
            // Check if tenant has other users?
            $memberCount = DB::table('user_tenants')->where('tenant_id', $tenant->id)->count();
            if ($memberCount == 0) {
                echo "   ⚠️ Tenant '{$tenant->name}' (ID: {$tenant->id}) has no more members. Deleting...\n";
                // Delete subscriptions
                DB::table('subscriptions')->where('tenant_id', $tenant->id)->delete();
                // Delete tenant
                $tenant->delete();
                echo "      ✅ Deleted Tenant and Subscriptions\n";
            } else {
                echo "   ℹ️ Tenant '{$tenant->name}' still has $memberCount members. Skipping tenant delete.\n";
            }
        }
    });
}

echo "\n✨ Cleanup complete. The number is now free for new registration.\n";
