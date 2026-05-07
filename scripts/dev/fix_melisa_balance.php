<?php

$tid = 10001;

echo "\n--- CREATING DEFAULT BALANCES FOR TENANT $tid ---\n";

// Cek apakah sudah ada
$existing = App\Models\Balance::where('tenant_id', $tid)->count();
if ($existing > 0) {
    echo "⚠️ Tenant sudah punya $existing balance(s). Skip.\n";
    exit;
}

// Buat Balance Default (Kas)
$kas = new App\Models\Balance;
$kas->tenant_id = $tid;
$kas->account_name = 'Kas';
$kas->account_type = 'cash';
$kas->balance = 0;
$kas->balance_date = now();
$kas->is_active = true;
$kas->is_default = true;
$kas->currency = 'IDR';
$kas->save();

echo "✅ Balance 'Kas' dibuat (ID: {$kas->id})\n";

// Buat Balance Bank
$bank = new App\Models\Balance;
$bank->tenant_id = $tid;
$bank->account_name = 'Bank';
$bank->account_type = 'bank';
$bank->balance = 0;
$bank->balance_date = now();
$bank->is_active = true;
$bank->is_default = false;
$bank->currency = 'IDR';
$bank->save();

echo "✅ Balance 'Bank' dibuat (ID: {$bank->id})\n";

echo "\n✅ Setup selesai! User sekarang bisa bertransaksi.\n";
echo "   Silakan minta user mencoba kirim pesan lagi.\n";
echo "--- END ---\n";
