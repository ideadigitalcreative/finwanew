<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$mappings = \App\Models\UserLidMapping::where('lid', '239100930052305')
    ->orWhere('phone_number', '6285255021716')
    ->get();

foreach ($mappings as $m) {
    echo 'ID: '.$m->id."\n";
    echo 'LID: '.$m->lid."\n";
    echo 'Phone Number: '.$m->phone_number."\n";
    echo 'User ID: '.$m->user_id."\n";
    echo 'Tenant ID: '.$m->tenant_id."\n";
    echo "-------------------\n";
}
