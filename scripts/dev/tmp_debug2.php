<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SavingsGoal;

$goals = SavingsGoal::all();
echo 'Total goals in DB: '.$goals->count()."\n";
foreach ($goals as $goal) {
    echo "- ID: {$goal->id}, Name: '{$goal->name}', Status: {$goal->status}, Tenant: {$goal->tenant_id}\n";
}
