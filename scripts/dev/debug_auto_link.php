<?php

echo "\n--- DEBUG AUTO-LINK ---\n";

// 1. Cek user terbaru dalam 1 jam
$recentUsers = App\Models\User::where('created_at', '>=', now()->subHour())
    ->whereNotNull('whatsapp_number')
    ->get();

echo 'Users registered in last 1 hour: '.$recentUsers->count()."\n";
foreach ($recentUsers as $u) {
    echo "  - {$u->id}: {$u->name} ({$u->whatsapp_number}) - {$u->created_at}\n";
}

// 2. Cek user yang TIDAK punya mapping
$usersWithoutMapping = App\Models\User::where('created_at', '>=', now()->subHour())
    ->whereNotNull('whatsapp_number')
    ->whereNotIn('id', function ($q) {
        $q->select('user_id')->from('user_whatsapp_numbers');
    })
    ->get();

echo "\nUsers WITHOUT mapping: ".$usersWithoutMapping->count()."\n";
foreach ($usersWithoutMapping as $u) {
    echo "  - {$u->id}: {$u->name} ({$u->whatsapp_number})\n";
}

// 3. Cek mapping untuk LID ini
$lid = '81733663870987';
$mapping = App\Models\UserWhatsAppNumber::where('whatsapp_number', $lid)->first();
if ($mapping) {
    echo "\nLID $lid SUDAH ADA mapping ke User ID: {$mapping->user_id}\n";
} else {
    echo "\nLID $lid BELUM ada mapping.\n";
}

// 4. Semua mapping
$allMappings = App\Models\UserWhatsAppNumber::all();
echo "\nTotal mappings: ".$allMappings->count()."\n";
foreach ($allMappings->take(10) as $m) {
    echo "  - User {$m->user_id}: {$m->whatsapp_number} ({$m->name})\n";
}

echo "\n--- END ---\n";
