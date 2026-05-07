<?php

$target = '6285179849922';
echo "\n--- DEBUG USER NEW ($target) ---\n";

// 1. Cek User
$user = App\Models\User::where('whatsapp_number', $target)->first();
if (! $user) {
    echo "❌ User tidak ditemukan dengan exact match '$target'.\n";
    // Cek fuzzy
    $user = App\Models\User::where('whatsapp_number', 'like', '%'.substr($target, 4))->first();
    if ($user) {
        echo "⚠️ Tapi ditemukan dengan LIKE search: {$user->whatsapp_number} (ID: {$user->id})\n";
        echo "   Ini berarti format nomor di DB berbeda dengan input!\n";
    }
} else {
    echo "✅ User ditemukan: {$user->name} (Tenant {$user->tenant_id})\n";
}

// 2. Cek Mapping
$map = App\Models\UserWhatsAppNumber::where('whatsapp_number', $target)->first();
if ($map) {
    echo "✅ Mapping ditemukan: Tenant {$map->tenant_id}\n";
} else {
    echo "❌ Mapping tidak ditemukan.\n";
}

echo "--- END ---\n";
