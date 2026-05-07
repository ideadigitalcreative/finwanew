<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Balance;

$tenantId = 129; // Rashid Family

echo "=== DAFTAR DOMPET USER ID $tenantId ===\n";

$wallets = Balance::where('tenant_id', $tenantId)->get();

if ($wallets->isEmpty()) {
    echo "Tidak ada dompet ditemukan.\n";
} else {
    foreach ($wallets as $w) {
        $status = $w->is_active ? '✅ Active' : '❌ Inactive';
        echo "ID: {$w->id} | Name: [{$w->name}] | Saldo: Rp ".number_format($w->balance, 0, ',', '.')." | $status\n";
    }
}
