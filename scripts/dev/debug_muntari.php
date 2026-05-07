<?php

$phone = '6281460486803';
echo "\n--- DEBUG MUNTARI ($phone) ---\n";

$user = App\Models\User::where('whatsapp_number', $phone)->first();

if ($user) {
    echo "ID: {$user->id}\nName: {$user->name}\nTenant: {$user->tenant_id}\nCreated: {$user->created_at}\n";

    // Cek Total Transaksi di tenant ini
    $expense = App\Models\Transaction::where('tenant_id', $user->tenant_id)
        ->where('type', 'expense')
        ->sum('amount');

    echo 'Total Pengeluaran di DB: Rp '.number_format($expense)."\n";

    // Cek Pesan
    $msgs = App\Models\Message::where('sender_id', $phone)->get();
    echo "\nPesan dari $phone:\n";
    foreach ($msgs as $m) {
        $isCorrect = ($m->tenant_id == $user->tenant_id) ? '✅' : "❌ (Tenant {$m->tenant_id})";
        echo "- [{$m->created_at}] $isCorrect {$m->content}\n";
    }

} else {
    echo "User tidak ditemukan di tabel users.\n";
}
echo "--- END ---\n";
