<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

echo "--- Searching for 'Muhammad Rozali' ---\n";
$users = User::where('name', 'like', '%Muhammad Rozali%')->get();
foreach ($users as $u) {
    echo "ID: {$u->id}, Name: {$u->name}, Tenant: {$u->tenant_id}, WhatsApp: {$u->whatsapp_number}\n";
}

echo "\n--- Searching for '6285255021716' in users table ---\n";
$usersByPhone = User::where('whatsapp_number', 'like', '%6285255021716%')->get();
foreach ($usersByPhone as $u) {
    echo "ID: {$u->id}, Name: {$u->name}, Tenant: {$u->tenant_id}, WhatsApp: {$u->whatsapp_number}\n";
}
