<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$messages = App\Models\Message::orderBy('id', 'desc')->limit(5)->get();
foreach ($messages as $m) {
    echo "ID: {$m->id} | Tenant: {$m->tenant_id} | Sender: {$m->sender_id} | Content: {$m->content}\n";
}
echo "\nQueue count: ".DB::table('jobs')->count()."\n";
echo 'Failed jobs: '.DB::table('failed_jobs')->count()."\n";
