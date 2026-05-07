<?php

$tid = 10001;
echo "\n--- CHECK SETUP TENANT $tid ---\n";
$b = App\Models\Balance::where('tenant_id', $tid)->count();
$c = App\Models\Category::where('tenant_id', $tid)->count();
echo "Balances (Accounts): $b\n";
echo "Categories: $c\n";

if ($b == 0 || $c == 0) {
    echo "⚠️ Tenant kosong! Transaksi pasti gagal.\n";
    echo "   User perlu setup awal (balances & categories).\n";
} else {
    echo "✅ Setup Tenant OK.\n";
}
echo "--- END ---\n";
