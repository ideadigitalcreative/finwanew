<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Balance;
use App\Models\Transaction;

// Konfigurasi Target
$targetWalletId = 87; // Dompet Utama User Haerul Hadi
$userId = 12; // Tenant ID (Haerul Hadi)

// Ambil input dari command line
if (!isset($argv[1])) {
    die("❌ Error: Harap masukkan nominal saldo yang diinginkan.\nContoh: php set_balance_user.php 1000000\n");
}

$newBalance = floatval($argv[1]);

echo "=== MENGUBAH SALDO DOMPET USER ===\n";
echo "Wallet ID: $targetWalletId\n";
echo "Target Saldo: Rp " . number_format($newBalance, 0, ',', '.') . "\n\n";

$balance = Balance::find($targetWalletId);

if (!$balance) {
    die("❌ Wallet tidak ditemukan!\n");
}

if ($balance->tenant_id != $userId) {
    die("❌ Security Error: Wallet ini milik tenant {$balance->tenant_id}, bukan tenant $userId.\n");
}

$currentBalance = floatval($balance->balance);
$difference = $newBalance - $currentBalance;

echo "Saldo Saat Ini: Rp " . number_format($currentBalance, 0, ',', '.') . "\n";
echo "Selisih       : Rp " . number_format($difference, 0, ',', '.') . "\n";

if ($difference == 0) {
    die("✅ Saldo sudah sesuai. Tidak ada perubahan.\n");
}

// Konfirmasi
echo "\n⚠️  PERINGATAN: Script ini akan membuat transaksi otomatis untuk menyesuaikan saldo.\n";
echo "Lanjutkan? (y/n): ";
$handle = fopen ("php://stdin","r");
$line = fgets($handle);
if(trim($line) != 'y'){
    die("Dibatalkan.\n");
}

// Buat Transaksi Penyesuaian
$type = $difference > 0 ? 'income' : 'expense';
$absDiff = abs($difference);

// Cari Kategori Valid
$category = \App\Models\Category::where('tenant_id', $userId)
    ->where(function($q) {
        $q->where('name', 'like', '%Lain%')
          ->orWhere('name', 'like', '%Adjustment%')
          ->orWhere('name', 'like', '%Saldo%');
    })
    ->first();

// Fallback to ANY category
if (!$category) {
    $category = \App\Models\Category::where('tenant_id', $userId)->first();
}

if (!$category) {
    die("❌ Error: User tidak memiliki kategori apapun. Buat kategori dulu.\n");
}

$trx = Transaction::create([
    'tenant_id' => $userId,
    'balance_id' => $targetWalletId, // Link ke wallet
    'category_id' => $category->id, // Use valid category ID
    'amount' => $absDiff,
    'type' => $type,
    'transaction_date' => now(),
    'description' => 'Penyesuaian Saldo Manual (Via Script)',
    'payment_method' => 'manual_correction',
    'status' => 'confirmed'
]);

// Update Saldo
$balance->balance = $newBalance;
$balance->save();

echo "\n✅ BERHASIL!\n";
echo "Saldo Dompet Utama sekarang: Rp " . number_format($balance->balance, 0, ',', '.') . "\n";
echo "Transaksi Koreksi ID: {$trx->id}\n";
