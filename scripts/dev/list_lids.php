<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\UserLidMapping;

echo "--- Global Search for LIDs ---\n";
$mappings = UserLidMapping::all();
foreach ($mappings as $m) {
    echo "LID: {$m->lid}, User: {$m->user_id}, Phone: {$m->phone_number}\n";
}
