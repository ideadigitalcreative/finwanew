<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$users = App\Models\User::where('whatsapp_number', 'like', '%766676%')->get();
if ($users->count() > 0) {
    echo "Found Users:\n";
    foreach ($users as $u) {
        echo 'Found: '.$u->name.' (ID: '.$u->id.', WA: '.$u->whatsapp_number.')\n';
    }
} else {
    echo "No users found with 766676.\n";
}

$mappings = App\Models\UserWhatsAppNumber::where('whatsapp_number', 'like', '%766676%')->get();
if ($mappings->count() > 0) {
    echo "\nFound Mappings:\n";
    foreach ($mappings as $m) {
        echo 'Found Mapping: '.$m->name.' (ID: '.$m->id.', WA: '.$m->whatsapp_number.', User: '.$m->user_id.', Tenant: '.$m->tenant_id.")\n";
    }
} else {
    echo "\nNo mappings found with 766676.\n";
}

echo "\nDone.\n";
