<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

$ownerPhone = '6285242766676';

echo "--- Searching for Owner User ($ownerPhone) ---\n";
$owner = User::where('whatsapp_number', 'like', "%$ownerPhone%")->first();
if ($owner) {
    echo "ID: {$owner->id}, Name: {$owner->name}, Tenant: {$owner->tenant_id}\n";
} else {
    echo "Owner not found in users table.\n";
    $ownerNum = \App\Models\UserWhatsAppNumber::where('whatsapp_number', 'like', "%$ownerPhone%")->first();
    if ($ownerNum) {
        echo "Found in UserWhatsAppNumber table: User ID {$ownerNum->user_id}, Tenant {$ownerNum->tenant_id}\n";
        $owner = User::find($ownerNum->user_id);
        if ($owner) {
            echo "Owner Name: {$owner->name}, Tenant: {$owner->tenant_id}\n";
        }
    }
}
