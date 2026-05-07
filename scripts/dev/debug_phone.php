<?php

$targetPhone = '6285749447208';
echo "\n--- DIAGNOSA DATA NOMOR: $targetPhone ---\n";

// 1. Cek Tabel User Utama (Akun Login)
$user = App\Models\User::where('whatsapp_number', $targetPhone)->first();

echo "[1] USER UTAMA (TABLE USERS)\n";
if ($user) {
    echo "   ✅ DITEMUKAN:\n";
    echo "   Nama: {$user->name}\n";
    echo "   User ID: {$user->id}\n";
    echo "   Tenant ID: {$user->tenant_id}\n";

    // Cek Transaksi
    $trxCount = App\Models\Transaction::where('tenant_id', $user->tenant_id)->count();
    $latestTrx = App\Models\Transaction::where('tenant_id', $user->tenant_id)->latest()->first();
    echo "   Total Transaksi: $trxCount\n";
    if ($latestTrx) {
        echo "   Transaksi Terakhir: {$latestTrx->created_at} ({$latestTrx->description})\n";
    }
} else {
    echo "   ❌ TIDAK DITEMUKAN user dengan nomor ini sebagai wa utama.\n";
}

// 2. Cek Mapping WhatsApp & LID (Termasuk konflik)
$mappings = App\Models\UserWhatsAppNumber::where('whatsapp_number', 'like', "%$targetPhone%")
    ->orWhere('name', 'like', "%$targetPhone%") // Cek jika nomor ini jadi nama LID
    ->with(['user', 'tenant'])
    ->get();

echo "\n[2] MAPPING WHATSAPP (TABLE USER_WHATSAPP_NUMBERS)\n";
if ($mappings->count() > 0) {
    foreach ($mappings as $m) {
        $owner = $m->user ? $m->user->name : 'Unknown';

        // Cek Konflik
        $status = 'OK';
        if ($user && $m->tenant_id != $user->tenant_id) {
            $status = '❌ KONFLIK (Milik Tenant Lain)';
        } elseif (! $user) {
            $status = 'ℹ️ Mapping Tanpa User Utama';
        } else {
            $status = '✅ Cocok';
        }

        echo "   Status: $status\n";
        echo "   Map ID: {$m->id}\n";
        echo "   Nomor WA/LID: {$m->whatsapp_number}\n";
        echo "   Label Nama: {$m->name}\n";
        echo "   Tenant ID: {$m->tenant_id} (Owner: $owner)\n";
        echo "   ------------------------\n";
    }
} else {
    echo "   Tidak ditemukan mapping apapun.\n";
}

echo "\n--- SELESAI ---\n";
