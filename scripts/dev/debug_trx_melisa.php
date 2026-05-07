<?php

$tenantId = 10001;
echo "\n--- CEK TRX TENANT $tenantId ---\n";
$t = App\Models\Transaction::where('tenant_id', $tenantId)->latest()->first();
if ($t) {
    echo "✅ Trx Terakhir: {$t->created_at} \nDeskripsi: {$t->description} (Rp ".number_format($t->amount).")\n";
} else {
    echo "❌ Belum ada transaksi masuk di Tenant $tenantId\n";
}
echo "--- END ---\n";
