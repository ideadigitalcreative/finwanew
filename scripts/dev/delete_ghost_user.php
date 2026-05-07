<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$userId = 12;
$user = User::find($userId);

if (! $user) {
    echo "❌ User with ID $userId not found.\n";
    exit;
}

echo "⚠️ Found User ID: {$user->id}\n";
echo "   Name: {$user->name}\n";
echo "   Email: {$user->email}\n";
echo "   WA: {$user->whatsapp_number}\n\n";

echo "Deleting user and related data...\n";

try {
    DB::transaction(function () use ($user) {
        // 1. Detach from tenants
        echo "   - Removed from user_tenants table.\n";
        DB::table('user_tenants')->where('user_id', $user->id)->delete();

        // 2. Delete/Nullify whatsapp number mappings if any (though script said none found, safe to check)
        if (class_exists(\App\Models\UserWhatsAppNumber::class)) {
            echo "   - Cleaning user_whatsapp_numbers.\n";
            \App\Models\UserWhatsAppNumber::where('user_id', $user->id)->delete();
        }

        // 3. Delete the user
        $user->delete();
        echo "   - User record deleted.\n";
    });

    echo "\n✅ Successfully deleted User ID 12.\n";

} catch (\Exception $e) {
    echo "\n❌ Error deleting user: ".$e->getMessage()."\n";
}
