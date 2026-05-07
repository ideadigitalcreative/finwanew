<?php

use App\Models\User;
use App\Models\UserWhatsAppNumber;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$searchTerms = [
    '218442590343379',
    '218442590343379@lid',
    '218442590343379@c.us',
];

echo '🔍 Searching for LID/IDs: '.implode(', ', $searchTerms)."\n\n";

// 1. Search in UserWhatsAppNumbers
if (class_exists(UserWhatsAppNumber::class)) {
    echo "1. Checking 'user_whatsapp_numbers' table...\n";
    foreach ($searchTerms as $term) {
        $results = UserWhatsAppNumber::where('whatsapp_number', 'LIKE', "%$term%")->get();
        if ($results->count() > 0) {
            foreach ($results as $res) {
                echo "   🔴 FOUND MAPPING!\n";
                echo "      ID: {$res->id}\n";
                echo "      User ID: {$res->user_id}\n";
                echo "      WA Number: {$res->whatsapp_number}\n";
                echo '      Is LID: '.($res->is_lid ?? 'N/A')."\n";

                $u = User::find($res->user_id);
                if ($u) {
                    echo "      -> Linked User: {$u->name} ({$u->email})\n";
                    echo "      ⚠️ Deleting User and Mapping...\n";
                    $res->delete();
                    $u->delete();
                    DB::table('user_tenants')->where('user_id', $u->id)->delete();
                    echo "      ✅ Deleted.\n";
                } else {
                    echo "      -> Orphan Mapping. Deleting...\n";
                    $res->delete();
                    echo "      ✅ Deleted.\n";
                }
            }
        }
    }
}

// 2. Search in Users table
echo "\n2. Checking 'users' table (whatsapp_number column)...\n";
foreach ($searchTerms as $term) {
    $results = User::where('whatsapp_number', 'LIKE', "%$term%")->get();
    if ($results->count() > 0) {
        foreach ($results as $res) {
            echo "   🔴 FOUND USER!\n";
            echo "      ID: {$res->id}\n";
            echo "      Name: {$res->name}\n";
            echo "      WA Number: {$res->whatsapp_number}\n";
            echo "      ⚠️ Deleting User...\n";

            // Cleanup tenants
            DB::table('user_tenants')->where('user_id', $res->id)->delete();
            if (class_exists(UserWhatsAppNumber::class)) {
                UserWhatsAppNumber::where('user_id', $res->id)->delete();
            }
            $res->delete();
            echo "      ✅ Deleted.\n";
        }
    }
}

echo "\nDone.\n";
