<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;

$phone = '6285255021716';
$ownerPhone = '6285242766676';

echo "Checking for user with phone: $phone\n";
$user = User::where('whatsapp_number', 'like', "%$phone%")->first();
if ($user) {
    echo "Found User: {$user->name} (ID: {$user->id}, Tenant ID: {$user->tenant_id})\n";
} else {
    echo "User not found in users table.\n";
}

echo "\nChecking UserWhatsAppNumber table:\n";
$numbers = UserWhatsAppNumber::where('whatsapp_number', 'like', "%$phone%")->get();
foreach ($numbers as $n) {
    echo "Number: {$n->whatsapp_number}, User ID: {$n->user_id}, Tenant ID: {$n->tenant_id}, Is LID: ".($n->is_lid ? 'Yes' : 'No').', Active: '.($n->is_active ? 'Yes' : 'No')."\n";
}

echo "\nChecking UserLidMapping table:\n";
$mappings = UserLidMapping::where('whatsapp_number', 'like', "%$phone%")->get();
foreach ($mappings as $m) {
    echo "LID: {$m->lid}, User ID: {$m->user_id}, Tenant ID: {$m->tenant_id}, Phone: {$m->whatsapp_number}\n";
}

echo "\nChecking Owner User (+62 852-4276-6676):\n";
$owner = User::where('whatsapp_number', 'like', "%$ownerPhone%")->first();
if ($owner) {
    echo "Owner User: {$owner->name} (ID: {$owner->id}, Tenant ID: {$owner->tenant_id})\n";
} else {
    echo "Owner not found.\n";
}
