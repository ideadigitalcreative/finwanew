<?php

$email = 'melisatulris22ki@gmail.com';
$phone = '6285179849922';

echo "\n--- HAPUS USER MELISA ---\n";

$user = App\Models\User::where('email', $email)->first();
if (! $user) {
    echo "❌ User tidak ditemukan.\n";
    exit;
}

$tenantId = $user->tenant_id;
echo "User: {$user->name} (ID: {$user->id})\n";
echo "Tenant ID: $tenantId\n";

// 1. Hapus UserWhatsAppNumber
$maps = App\Models\UserWhatsAppNumber::where('tenant_id', $tenantId)->get();
echo "\nMenghapus {$maps->count()} WhatsApp Number mappings...\n";
foreach ($maps as $m) {
    $m->delete();
}

// 2. Hapus Transactions
$trx = App\Models\Transaction::where('tenant_id', $tenantId)->count();
echo "Menghapus $trx transaksi...\n";
App\Models\Transaction::where('tenant_id', $tenantId)->delete();

// 3. Hapus Balances
$bal = App\Models\Balance::where('tenant_id', $tenantId)->count();
echo "Menghapus $bal balances...\n";
App\Models\Balance::where('tenant_id', $tenantId)->delete();

// 4. Hapus Categories
$cat = App\Models\Category::where('tenant_id', $tenantId)->count();
echo "Menghapus $cat categories...\n";
App\Models\Category::where('tenant_id', $tenantId)->delete();

// 5. Hapus Messages
$msg = App\Models\Message::where('tenant_id', $tenantId)->count();
echo "Menghapus $msg messages...\n";
App\Models\Message::where('tenant_id', $tenantId)->delete();

// 6. Hapus Channels
$ch = App\Models\Channel::where('tenant_id', $tenantId)->count();
echo "Menghapus $ch channels...\n";
App\Models\Channel::where('tenant_id', $tenantId)->delete();

// 7. Hapus User
echo "Menghapus user...\n";
$user->delete();

// 8. Hapus Tenant
echo "Menghapus tenant...\n";
$tenant = App\Models\Tenant::find($tenantId);
if ($tenant) {
    $tenant->delete();
}

echo "\n✅ User Melisa dan semua data terkait berhasil dihapus.\n";
echo "   Silakan daftar ulang melalui website.\n";
echo "--- SELESAI ---\n";
