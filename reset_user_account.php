<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Transaction;
use App\Models\Balance;

if (!isset($argv[1])) {
    die("❌ Error used: php reset_user_account.php [NOMOR_WA]\n");
}

$rawNumber = $argv[1];
// Normalize Number (08 -> 628)
$waNumber = $rawNumber;
if (str_starts_with($rawNumber, '08')) {
    $waNumber = '62' . substr($rawNumber, 1);
}

echo "=== RESET AKUN USER ===\n";
echo "Target: $waNumber (Raw: $rawNumber)\n";

$user = User::where('whatsapp_number', $waNumber)->first();

if (!$user) {
    echo "❌ User tidak ditemukan dengan nomor $waNumber!\n";
    // Coba cari di UserWhatsAppNumber
    $mapping = \App\Models\UserWhatsAppNumber::where('whatsapp_number', $waNumber)->first();
    if ($mapping) {
        $user = User::find($mapping->user_id);
        echo "✅ Ditemukan via Mapping LID: Tenant ID {$user->tenant_id}\n";
    } else {
        // Coba cari di Message Log
        echo "Searching in Message Log...\n";
        $msg = \App\Models\Message::where('sender_id', 'like', "%{$rawNumber}%")
            ->orWhere('sender_id', 'like', "%{$waNumber}%")
            ->orWhere('sender_id', 'like', "%" . substr($rawNumber, -8) . "%")
            ->latest()
            ->first();
            
        if ($msg) {
             $user = User::find($msg->tenant_id);
             echo "✅ Ditemukan via Message History! Tenant ID {$user->tenant_id} ({$user->name})\n";
        } else {
             die("User benar-benar tidak ditemukan di Database.\n");
        }
    }
}

echo "Tenant Name : {$user->name}\n";
echo "Tenant ID   : {$user->tenant_id}\n\n";

$txCount = Transaction::where('tenant_id', $user->tenant_id)->count();
$balances = Balance::where('tenant_id', $user->tenant_id)->get();
$totalSaldo = $balances->sum('balance');

echo "Data yang akan DIHAPUS/RESET:\n";
echo "- $txCount Transaksi\n";
echo "- " . $balances->count() . " Dompet (Total Saldo: Rp " . number_format($totalSaldo, 0, ',', '.') . ")\n";

echo "\n⚠️  PERINGATAN KERAS: Data akan hilang permanen!\n";
echo "Ketik 'RESET' untuk melanjutkan: ";

$handle = fopen ("php://stdin","r");
$line = trim(fgets($handle));
if($line !== 'RESET'){
    die("Dibatalkan.\n");
}

echo "Processing...\n";

// 1. Delete Transactions
$deleted = Transaction::where('tenant_id', $user->tenant_id)->delete();
echo "✅ $deleted Transaksi dihapus.\n";

// 2. Reset Balances
$updated = Balance::where('tenant_id', $user->tenant_id)->update(['balance' => 0]);
echo "✅ $updated Dompet di-reset ke 0.\n";

echo "\n🎉 Akun berhasil di-reset bersih!\n";
