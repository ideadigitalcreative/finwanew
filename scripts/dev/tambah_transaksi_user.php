<?php

/**
 * Script untuk menambahkan transaksi ke user berdasarkan nomor telepon
 * Jalankan dengan: php tambah_transaksi_user.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserWhatsAppNumber;

$phoneNumber = '6285257216244';

echo "===========================================\n";
echo "TAMBAH TRANSAKSI USER\n";
echo "===========================================\n";
echo "Nomor Telepon: {$phoneNumber}\n";
echo "-------------------------------------------\n\n";

// Cari user berdasarkan nomor telepon
$user = User::where('whatsapp_number', $phoneNumber)
    ->orWhere('whatsapp_number', 'like', '%'.substr($phoneNumber, -10).'%')
    ->first();

if (! $user) {
    // Coba cari via UserWhatsAppNumber mapping
    $waNumber = UserWhatsAppNumber::where('whatsapp_number', $phoneNumber)
        ->orWhere('whatsapp_number', 'like', '%'.substr($phoneNumber, -10).'%')
        ->first();

    if ($waNumber) {
        $user = User::where('id', $waNumber->user_id)->first();
        echo "User ditemukan via WhatsApp mapping\n";
    }
}

if (! $user) {
    echo "❌ User dengan nomor {$phoneNumber} tidak ditemukan!\n";
    exit(1);
}

echo "✅ User ditemukan: {$user->name} (ID: {$user->id})\n";

// Cari tenant
$tenant = $user->tenants()->first();
if (! $tenant) {
    echo "❌ Tenant tidak ditemukan untuk user ini!\n";
    exit(1);
}

echo "   Tenant: {$tenant->name} (ID: {$tenant->id})\n\n";

// Cari kategori yang dibutuhkan
$categoryMakan = Category::where('tenant_id', $tenant->id)
    ->where(function ($q) {
        $q->where('type', 'like', '%makan%')
            ->orWhere('name', 'like', '%makan%')
            ->orWhere('type', 'makanan');
    })
    ->first();

$categoryTransportasi = Category::where('tenant_id', $tenant->id)
    ->where(function ($q) {
        $q->where('type', 'like', '%transport%')
            ->orWhere('name', 'like', '%transport%')
            ->orWhere('name', 'like', '%bensin%')
            ->orWhere('type', 'transportasi');
    })
    ->first();

// Fallback: ambil kategori expense apapun jika tidak ada
if (! $categoryMakan) {
    $categoryMakan = Category::where('tenant_id', $tenant->id)->first();
    echo "⚠️ Kategori makan tidak ditemukan, menggunakan: {$categoryMakan->name}\n";
}
if (! $categoryTransportasi) {
    $categoryTransportasi = Category::where('tenant_id', $tenant->id)->first();
    echo "⚠️ Kategori transportasi tidak ditemukan, menggunakan: {$categoryTransportasi->name}\n";
}

echo "Kategori Makan: {$categoryMakan->name} (ID: {$categoryMakan->id})\n";
echo "Kategori Transportasi: {$categoryTransportasi->name} (ID: {$categoryTransportasi->id})\n\n";

// Daftar transaksi yang akan ditambahkan
$transactions = [
    // Tanggal 06 January 2026
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryMakan->id,
        'type' => 'expense',
        'amount' => 23000,
        'description' => 'Makan siang',
        'transaction_date' => '2026-01-06',
        'source' => 'manual',
    ],

    // Tanggal 07 January 2026
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryMakan->id,
        'type' => 'expense',
        'amount' => 23000,
        'description' => 'Makan siang',
        'transaction_date' => '2026-01-07',
        'source' => 'manual',
    ],
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryTransportasi->id,
        'type' => 'expense',
        'amount' => 115000,
        'description' => 'Bensin CB150R',
        'transaction_date' => '2026-01-07',
        'source' => 'manual',
    ],
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryTransportasi->id,
        'type' => 'expense',
        'amount' => 6000,
        'description' => 'Cek Angin CB150R',
        'transaction_date' => '2026-01-07',
        'source' => 'manual',
    ],
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryTransportasi->id,
        'type' => 'expense',
        'amount' => 20000,
        'description' => 'Cuci Motor CB150R',
        'transaction_date' => '2026-01-07',
        'source' => 'manual',
    ],
];

echo 'Menambahkan '.count($transactions)." transaksi...\n";
echo "-------------------------------------------\n";

$success = 0;
$failed = 0;

foreach ($transactions as $i => $data) {
    try {
        $trx = Transaction::create($data);
        $num = $i + 1;
        $amount = number_format($data['amount'], 0, ',', '.');
        echo "✅ {$num}. {$data['transaction_date']} | Rp {$amount} | {$data['description']}\n";
        $success++;
    } catch (\Exception $e) {
        $num = $i + 1;
        echo "❌ {$num}. Gagal: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\n-------------------------------------------\n";
echo "Hasil: {$success} berhasil, {$failed} gagal\n";
echo "===========================================\n";

// Tampilkan total
$totalExpense = Transaction::where('tenant_id', $tenant->id)
    ->where('type', 'expense')
    ->sum('amount');

$totalIncome = Transaction::where('tenant_id', $tenant->id)
    ->where('type', 'income')
    ->sum('amount');

echo "\nRingkasan Keuangan User:\n";
echo '💰 Total Pemasukan: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
echo '💸 Total Pengeluaran: Rp '.number_format($totalExpense, 0, ',', '.')."\n";
echo '📊 Saldo: Rp '.number_format($totalIncome - $totalExpense, 0, ',', '.')."\n";
