<?php

/**
 * Script untuk menambahkan transaksi ke user 6285714725102 (Kory Manly)
 * Jalankan dengan: php tambah_transaksi_sarka.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Balance;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserWhatsAppNumber;

$phoneNumber = '6285714725102';

echo "===========================================\n";
echo "TAMBAH TRANSAKSI USER KORY MANLY\n";
echo "===========================================\n";
echo "Nomor Telepon: {$phoneNumber}\n";
echo "-------------------------------------------\n\n";

// Cari user
$user = User::where('whatsapp_number', $phoneNumber)
    ->orWhere('whatsapp_number', 'like', '%'.substr($phoneNumber, -10).'%')
    ->first();

if (! $user) {
    $waNumber = UserWhatsAppNumber::where('whatsapp_number', $phoneNumber)
        ->orWhere('whatsapp_number', 'like', '%'.substr($phoneNumber, -10).'%')
        ->first();

    if ($waNumber) {
        $user = User::where('id', $waNumber->user_id)->first();
    }
}

if (! $user) {
    echo "❌ User tidak ditemukan!\n";
    exit(1);
}

echo "✅ User: {$user->name} (ID: {$user->id})\n";

$tenant = $user->tenants()->first();
if (! $tenant) {
    echo "❌ Tenant tidak ditemukan!\n";
    exit(1);
}

echo "   Tenant: {$tenant->name} (ID: {$tenant->id})\n\n";

// Cari atau buat kategori
$categoryJajan = Category::where('tenant_id', $tenant->id)
    ->where(function ($q) {
        $q->where('type', 'like', '%jajan%')
            ->orWhere('name', 'like', '%jajan%')
            ->orWhere('type', 'like', '%belanja%');
    })->first();

$categoryTransport = Category::where('tenant_id', $tenant->id)
    ->where(function ($q) {
        $q->where('type', 'like', '%transport%')
            ->orWhere('name', 'like', '%transport%')
            ->orWhere('name', 'like', '%grab%')
            ->orWhere('name', 'like', '%bus%');
    })->first();

$categoryTransfer = Category::where('tenant_id', $tenant->id)
    ->where(function ($q) {
        $q->where('type', 'like', '%transfer%')
            ->orWhere('name', 'like', '%transfer%')
            ->orWhere('type', 'like', '%pemasukan%');
    })->first();

// Fallback
$defaultCategory = Category::where('tenant_id', $tenant->id)->first();

if (! $categoryJajan) {
    $categoryJajan = $defaultCategory;
    echo "⚠️ Kategori jajan tidak ada, pakai: {$defaultCategory->name}\n";
}
if (! $categoryTransport) {
    $categoryTransport = $defaultCategory;
    echo "⚠️ Kategori transport tidak ada, pakai: {$defaultCategory->name}\n";
}
if (! $categoryTransfer) {
    $categoryTransfer = $defaultCategory;
    echo "⚠️ Kategori transfer tidak ada, pakai: {$defaultCategory->name}\n";
}

echo "Kategori Jajan: {$categoryJajan->name} (ID: {$categoryJajan->id})\n";
echo "Kategori Transport: {$categoryTransport->name} (ID: {$categoryTransport->id})\n";
echo "Kategori Transfer: {$categoryTransfer->name} (ID: {$categoryTransfer->id})\n\n";

// Cari wallet/balance (opsional)
$balanceOvo = Balance::where('tenant_id', $tenant->id)
    ->where('account_name', 'like', '%ovo%')->first();
$balanceEmoney = Balance::where('tenant_id', $tenant->id)
    ->where(function ($q) {
        $q->where('account_name', 'like', '%e-money%')
            ->orWhere('account_name', 'like', '%emoney%')
            ->orWhere('account_name', 'like', '%e money%');
    })->first();
$balanceCash = Balance::where('tenant_id', $tenant->id)
    ->where(function ($q) {
        $q->where('account_name', 'like', '%cash%')
            ->orWhere('account_name', 'like', '%tunai%');
    })->first();

echo 'Balance OVO: '.($balanceOvo ? "{$balanceOvo->account_name} (ID: {$balanceOvo->id})" : 'Tidak ada')."\n";
echo 'Balance E-Money: '.($balanceEmoney ? "{$balanceEmoney->account_name} (ID: {$balanceEmoney->id})" : 'Tidak ada')."\n";
echo 'Balance Cash: '.($balanceCash ? "{$balanceCash->account_name} (ID: {$balanceCash->id})" : 'Tidak ada')."\n\n";

// Daftar transaksi (Januari 2026)
$transactions = [
    // Tanggal 1 Januari 2026
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryTransfer->id,
        'balance_id' => $balanceOvo?->id,
        'type' => 'income',
        'amount' => 30000,
        'description' => 'Transfer masuk ke Ovo',
        'transaction_date' => '2026-01-01',
        'source' => 'manual',
    ],

    // Tanggal 2 Januari 2026
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryJajan->id,
        'balance_id' => $balanceEmoney?->id,
        'type' => 'expense',
        'amount' => 2000,
        'description' => 'Jajan dari E-money',
        'transaction_date' => '2026-01-02',
        'source' => 'manual',
    ],
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryTransport->id,
        'balance_id' => $balanceEmoney?->id,
        'type' => 'expense',
        'amount' => 2000,
        'description' => 'Naik bus dari E-money',
        'transaction_date' => '2026-01-02',
        'source' => 'manual',
    ],
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryJajan->id,
        'balance_id' => $balanceCash?->id,
        'type' => 'expense',
        'amount' => 2000,
        'description' => 'Jajan dari Cash',
        'transaction_date' => '2026-01-02',
        'source' => 'manual',
    ],

    // Tanggal 4 Januari 2026
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryTransport->id,
        'balance_id' => $balanceOvo?->id,
        'type' => 'expense',
        'amount' => 10500,
        'description' => 'Naik Grab dari Ovo',
        'transaction_date' => '2026-01-04',
        'source' => 'manual',
    ],
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryTransport->id,
        'balance_id' => $balanceOvo?->id,
        'type' => 'expense',
        'amount' => 10500,
        'description' => 'Naik Grab dari Ovo',
        'transaction_date' => '2026-01-04',
        'source' => 'manual',
    ],

    // Tanggal 5 Januari 2026
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryTransfer->id,
        'balance_id' => $balanceOvo?->id,
        'type' => 'income',
        'amount' => 24000,
        'description' => 'Transfer masuk ke Ovo',
        'transaction_date' => '2026-01-05',
        'source' => 'manual',
    ],
    [
        'tenant_id' => $tenant->id,
        'category_id' => $categoryTransport->id,
        'balance_id' => $balanceOvo?->id,
        'type' => 'expense',
        'amount' => 10500,
        'description' => 'Naik Grab dari Ovo',
        'transaction_date' => '2026-01-05',
        'source' => 'manual',
    ],
];

echo 'Menambahkan '.count($transactions)." transaksi...\n";
echo "-------------------------------------------\n";

$success = 0;
$failed = 0;

foreach ($transactions as $i => $data) {
    try {
        // Filter null values
        $data = array_filter($data, fn ($v) => $v !== null);

        $trx = Transaction::create($data);
        $num = $i + 1;
        $typeIcon = $data['type'] === 'income' ? '💰' : '💸';
        $amount = number_format($data['amount'], 0, ',', '.');
        echo "✅ {$num}. {$typeIcon} {$data['transaction_date']} | Rp {$amount} | {$data['description']}\n";
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

// Ringkasan
$totalExpense = Transaction::where('tenant_id', $tenant->id)->where('type', 'expense')->sum('amount');
$totalIncome = Transaction::where('tenant_id', $tenant->id)->where('type', 'income')->sum('amount');

echo "\nRingkasan Keuangan:\n";
echo '💰 Total Pemasukan: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
echo '💸 Total Pengeluaran: Rp '.number_format($totalExpense, 0, ',', '.')."\n";
echo '📊 Saldo: Rp '.number_format($totalIncome - $totalExpense, 0, ',', '.')."\n";
