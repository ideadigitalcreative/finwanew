<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$numbers = \App\Models\UserWhatsAppNumber::where('whatsapp_number', 'like', '%6285255021716%')
    ->orWhere('whatsapp_number', 'like', '%239100930052305%')
    ->get();

foreach ($numbers as $num) {
    echo 'ID: '.$num->id."\n";
    echo 'Number: '.$num->whatsapp_number."\n";
    echo 'User ID: '.$num->user_id."\n";
    echo 'Tenant ID: '.$num->tenant_id."\n";
    echo 'Is Primary: '.($num->is_primary ? 'Yes' : 'No')."\n";
    echo 'Is Active: '.($num->is_active ? 'Yes' : 'No')."\n";
    echo 'Is LID: '.($num->is_lid ? 'Yes' : 'No')."\n";
    echo "-------------------\n";
}
