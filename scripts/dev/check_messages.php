<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Message;

echo "--- Recent messages from or involving '6285255021716' ---\n";
$messages = Message::where('content', 'like', '%Akun Anda sudah terhubung%')
    ->orWhere('sender_id', 'like', '%6285255021716%')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

foreach ($messages as $m) {
    echo "ID: {$m->id}, To: {$m->sender_id}, Content: ".substr($m->content, 0, 50)."..., Created: {$m->created_at}\n";
    if (isset($m->metadata['original_sender_id'])) {
        echo "  Original Sender (LID): {$m->metadata['original_sender_id']}\n";
    }
    if (isset($m->raw_data['originalFrom'])) {
        echo "  Raw Original From: {$m->raw_data['originalFrom']}\n";
    }
}
