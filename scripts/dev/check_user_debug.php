<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;

$phone = '6285255021716';
$userId = 411;

echo "--- User 411 Details ---\n";
$user = User::find($userId);
if ($user) {
    echo "Name: {$user->name}\n";
    echo "Tenant ID: {$user->tenant_id}\n";
    echo "WhatsApp: {$user->whatsapp_number}\n";
} else {
    echo "User 411 NOT FOUND in users table!\n";
}

echo "\n--- UserWhatsAppNumber entries for User 411 ---\n";
$numbers = UserWhatsAppNumber::where('user_id', $userId)->get();
foreach ($numbers as $n) {
    echo "Phone: {$n->whatsapp_number}, Tenant: {$n->tenant_id}, Is LID: ".($n->is_lid ? 'Yes' : 'No').', Active: '.($n->is_active ? 'Yes' : 'No').", Created: {$n->created_at}\n";
}

echo "\n--- UserLidMapping entries for User 411 ---\n";
$mappings = UserLidMapping::where('user_id', $userId)->get();
foreach ($mappings as $m) {
    echo "LID: {$m->lid}, Tenant: {$m->tenant_id}, Phone: {$m->phone_number}, Verified: ".($m->verified ? 'Yes' : 'No')."\n";
}

echo "\n--- Searching for phone $phone anywhere ---\n";
$anyNumber = UserWhatsAppNumber::where('whatsapp_number', 'like', "%$phone%")->get();
foreach ($anyNumber as $n) {
    echo "[UWN] User: {$n->user_id}, Tenant: {$n->tenant_id}, Num: {$n->whatsapp_number}\n";
}

$anyUser = User::where('whatsapp_number', 'like', "%$phone%")->get();
foreach ($anyUser as $u) {
    echo "[User] ID: {$u->id}, Tenant: {$u->tenant_id}, Name: {$u->name}\n";
}
