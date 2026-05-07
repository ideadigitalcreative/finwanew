<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Category;

$tenantId = 129; // Rashid Family

echo "=== DAFTAR KATEGORI USER ID $tenantId ===\n";

$categories = Category::where('tenant_id', $tenantId)->get();

if ($categories->isEmpty()) {
    echo "Tidak ada kategori ditemukan.\n";
} else {
    foreach ($categories as $c) {
        echo "ID: {$c->id} | Name: [{$c->name}] | Type: [{$c->type}] | Group: {$c->category_group}\n";
    }
}

echo "\n=== DAFTAR TRANSAKSI USER ID $tenantId ===\n";
$txs = \App\Models\Transaction::where('tenant_id', $tenantId)->get();
if ($txs->isEmpty()) {
    echo "Tidak ada transaksi ditemukan.\n";
} else {
    foreach($txs as $t) {
       echo "Tx ID: {$t->id} | Amount: " . number_format($t->amount) . " | Type: {$t->type} | Cat ID: " . ($t->category_id ?? 'NULL') . "\n";
    }
}
