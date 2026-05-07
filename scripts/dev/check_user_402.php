<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$u = \App\Models\User::find(402);
if ($u) {
    echo 'Name: '.$u->name."\n";
    echo 'WhatsApp: '.$u->whatsapp_number."\n";
    echo 'Tenant ID: '.$u->tenant_id."\n";
} else {
    echo "User 402 not found\n";
}
