<?php

echo "\n--- 10 PESAN TERAKHIR ---\n";
$msgs = App\Models\Message::latest()->take(10)->get();
foreach ($msgs as $m) {
    echo "[ID: {$m->id}] {$m->created_at}\n";
    echo "   Tenant: {$m->tenant_id} | Sender: '{$m->sender_id}'\n";
    echo "   Content: {$m->content}\n";
    echo "---------------------------------\n";
}
echo "--- END ---\n";
