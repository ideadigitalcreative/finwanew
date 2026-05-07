<?php

$phone = '6285712782628';
echo "\n--- DEBUG DATA USER ARYA ($phone) ---\n";

$user = App\Models\User::where('whatsapp_number', 'like', "%$phone%")->first();

if ($user) {
    echo "✅ USER DITEMUKAN:\n";
    echo "   Nama: {$user->name}\n";
    echo "   User ID: {$user->id}\n";
    echo "   Tenant ID: {$user->tenant_id}\n";

    if ($user->tenant_id == 1) {
        echo "   🚨 BAHAYA! User ini terhubung ke Tenant ID 1 (Default/Admin)!\n";
        echo "   Ini menjelaskan kenapa dia melihat saldo orang lain.\n";
    } else {
        echo "   ✅ Tenant ID User tampaknya unik (Bukan 1).\n";
    }

    // Cek Transaksi User Ini
    $trxCount = App\Models\Transaction::where('tenant_id', $user->tenant_id)->count();
    echo "   Total Transaksi di ID {$user->tenant_id}: $trxCount\n";

    if ($trxCount > 0) {
        $firstTrx = App\Models\Transaction::where('tenant_id', $user->tenant_id)->oldest()->first();
        echo "   Transaksi Pertama: {$firstTrx->created_at} - {$firstTrx->description}\n";
    }

    // Cek Mapping
    $maps = App\Models\UserWhatsAppNumber::where('whatsapp_number', 'like', "%$phone%")->get();
    foreach ($maps as $m) {
        echo "   Mapping WA: {$m->whatsapp_number} -> Tenant {$m->tenant_id}\n";
    }

} else {
    echo "❌ User tidak ditemukan dengan nomor $phone.\n";
}
echo "--- SELESAI ---\n";
