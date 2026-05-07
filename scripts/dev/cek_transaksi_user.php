<?php

/**
 * Script untuk mengecek transaksi terakhir user berdasarkan nomor telepon
 * Jalankan dengan: php cek_transaksi_user.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\UserWhatsAppNumber;

$phoneNumber = '6285714725102';

echo "===========================================\n";
echo "CEK TRANSAKSI TERAKHIR USER\n";
echo "===========================================\n";
echo "Nomor Telepon: {$phoneNumber}\n";
echo "-------------------------------------------\n\n";

// Cari user berdasarkan nomor telepon
$user = User::where('whatsapp_number', $phoneNumber)
    ->orWhere('whatsapp_number', 'like', '%' . substr($phoneNumber, -10) . '%')
    ->first();

if (!$user) {
    // Coba cari via UserWhatsAppNumber mapping
    $waNumber = UserWhatsAppNumber::where('whatsapp_number', $phoneNumber)
        ->orWhere('whatsapp_number', 'like', '%' . substr($phoneNumber, -10) . '%')
        ->first();
    
    if ($waNumber) {
        $user = User::where('id', $waNumber->user_id)->first();
        echo "User ditemukan via WhatsApp mapping\n";
    }
}

if (!$user) {
    echo "❌ User dengan nomor {$phoneNumber} tidak ditemukan!\n";
    exit(1);
}

echo "✅ User ditemukan:\n";
echo "   ID: {$user->id}\n";
echo "   Nama: {$user->name}\n";
echo "   Email: {$user->email}\n";
echo "   Phone: {$user->phone}\n";

// Cari tenant
$tenant = $user->tenants()->first();
if (!$tenant) {
    echo "\n❌ Tenant tidak ditemukan untuk user ini!\n";
    exit(1);
}

echo "   Tenant ID: {$tenant->id}\n";
echo "   Tenant Nama: {$tenant->name}\n";

echo "\n-------------------------------------------\n";
echo "TRANSAKSI TERAKHIR:\n";
echo "-------------------------------------------\n";

// Cari transaksi terakhir
$lastTransaction = Transaction::where('tenant_id', $tenant->id)
    ->orderBy('transaction_date', 'desc')
    ->orderBy('created_at', 'desc')
    ->first();

if (!$lastTransaction) {
    echo "❌ Tidak ada transaksi untuk user ini!\n";
    exit(0);
}

echo "✅ Transaksi Terakhir:\n";
echo "   ID: {$lastTransaction->id}\n";
echo "   Tanggal: {$lastTransaction->transaction_date}\n";
echo "   Tipe: {$lastTransaction->type}\n";
echo "   Jumlah: Rp " . number_format($lastTransaction->amount, 0, ',', '.') . "\n";
echo "   Kategori: {$lastTransaction->category_type}\n";
echo "   Deskripsi: {$lastTransaction->description}\n";
echo "   Dibuat: {$lastTransaction->created_at}\n";

echo "\n-------------------------------------------\n";
echo "5 TRANSAKSI TERAKHIR:\n";
echo "-------------------------------------------\n";

$recentTransactions = Transaction::where('tenant_id', $tenant->id)
    ->orderBy('transaction_date', 'desc')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

foreach ($recentTransactions as $i => $trx) {
    $num = $i + 1;
    $typeIcon = $trx->type === 'income' ? '💰' : '💸';
    $amount = number_format($trx->amount, 0, ',', '.');
    echo "{$num}. {$typeIcon} {$trx->transaction_date} | Rp {$amount} | {$trx->category_type} | {$trx->description}\n";
}

echo "\n===========================================\n";
echo "Total transaksi user: " . Transaction::where('tenant_id', $tenant->id)->count() . "\n";
echo "===========================================\n";
