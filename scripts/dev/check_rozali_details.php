<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;

$userId = 412; // Muhammad Rozali

echo "--- User 412 (Muhammad Rozali) Details ---\n";
$user = User::find($userId);
echo "Name: {$user->name}, Tenant: {$user->tenant_id}, WhatsApp: {$user->whatsapp_number}\n";

echo "\n--- UserWhatsAppNumber entries for User 412 ---\n";
$numbers = UserWhatsAppNumber::where('user_id', $userId)->get();
foreach ($numbers as $n) {
    echo "Phone: {$n->whatsapp_number}, Is LID: ".($n->is_lid ? 'Yes' : 'No').', Active: '.($n->is_active ? 'Yes' : 'No').", Created: {$n->created_at}\n";
}

echo "\n--- UserLidMapping entries for User 412 ---\n";
$mappings = UserLidMapping::where('user_id', $userId)->get();
foreach ($mappings as $m) {
    echo "LID: {$m->lid}, Phone: {$m->phone_number}, Verified: ".($m->verified ? 'Yes' : 'No')."\n";
}
