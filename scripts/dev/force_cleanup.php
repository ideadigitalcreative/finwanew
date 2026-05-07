<?php

use App\Models\User;
use App\Models\UserWhatsAppNumber;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$targetNumber = '6285159205506';
$userId = 12; // From previous discovery

echo "🔍 Cleaning up data for User ID $userId and Number $targetNumber...\n";

try {
    DB::transaction(function () use ($userId, $targetNumber) {
        $deleted = false;

        // 1. Delete by User ID
        $user = User::find($userId);
        if ($user) {
            echo "✅ Found User ID $userId ({$user->name}). Deleting...\n";

            // Delete associations
            DB::table('user_tenants')->where('user_id', $userId)->delete();
            echo "   - Deleted user_tenants\n";

            if (class_exists(UserWhatsAppNumber::class)) {
                UserWhatsAppNumber::where('user_id', $userId)->delete();
                echo "   - Deleted user_whatsapp_numbers\n";
            }

            // Remove from tenants where this user is the ONLY user (if any) could be complex,
            // but for now let's just detach.
            // Actually, if we leave a tenant without users, it's orphan.
            // Let's find tenants owned by this user.
            $ownedTenants = DB::table('user_tenants')
                ->where('user_id', $userId)
                ->where('role_id', function ($q) {
                    $q->select('id')->from('roles')->where('slug', 'owner')->limit(1);
                })->pluck('tenant_id');

            // Deleting the user instance
            $user->delete();
            echo "   - Deleted User record\n";
            $deleted = true;
        } else {
            echo "⚠️ User ID $userId not found.\n";
        }

        // 2. Cleanup by Number (in case of orphans)
        $usersByNum = User::where('whatsapp_number', $targetNumber)->get();
        foreach ($usersByNum as $u) {
            if ($u->id != $userId) { // Don't double delete
                echo "⚠️ Found another user with number $targetNumber (ID: {$u->id}). Deleting...\n";
                DB::table('user_tenants')->where('user_id', $u->id)->delete();
                if (class_exists(UserWhatsAppNumber::class)) {
                    UserWhatsAppNumber::where('user_id', $u->id)->delete();
                }
                $u->delete();
                echo "   - Deleted User ID {$u->id}\n";
                $deleted = true;
            }
        }

        // 3. Cleanup Orphan UserWhatsAppNumbers
        if (class_exists(UserWhatsAppNumber::class)) {
            $orphans = UserWhatsAppNumber::where('whatsapp_number', 'LIKE', '%85159205506%')->get();
            foreach ($orphans as $orphan) {
                echo "⚠️ Found orphan WhatsApp mapping for {$orphan->whatsapp_number}. Deleting...\n";
                $orphan->delete();
                $deleted = true;
            }
        }

        if ($deleted) {
            echo "\n✅ Cleanup Successful. Number $targetNumber should now be free.\n";
        } else {
            echo "\nℹ️ No matching records found to delete.\n";
        }
    });

} catch (\Exception $e) {
    echo '❌ Error during cleanup: '.$e->getMessage()."\n";
}
