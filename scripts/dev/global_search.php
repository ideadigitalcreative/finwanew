<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\UserWhatsAppNumber;

$phone = '6285255021716';

echo "--- Global Search for number $phone ---\n";
$numbers = UserWhatsAppNumber::where('whatsapp_number', $phone)->get();
foreach ($numbers as $n) {
    $u = User::find($n->user_id);
    echo "UWN ID: {$n->id}, User ID: {$n->user_id}, Name: ".($u ? $u->name : 'N/A').", Tenant ID: {$n->tenant_id}, Is LID: ".($n->is_lid ? 'Yes' : 'No')."\n";
}

echo "\n--- Global Search for name 'Muhammad Rozali' ---\n";
$users = User::where('name', 'like', '%Muhammad Rozali%')->get();
foreach ($users as $u) {
    echo "User ID: {$u->id}, Name: {$u->name}, Phone: {$u->whatsapp_number}, Tenant ID: {$u->tenant_id}\n";

    echo "  Mapping in UserWhatsAppNumber:\n";
    $unums = UserWhatsAppNumber::where('user_id', $u->id)->get();
    foreach ($unums as $un) {
        echo "  - {$un->whatsapp_number} (LID: ".($un->is_lid ? 'Yes' : 'No').")\n";
    }
}
