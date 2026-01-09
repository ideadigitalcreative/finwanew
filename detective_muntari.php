<?php
echo "\n--- DETEKTIF TRANSAKSI MUNTARI ---\n";

// Cari transaksi spesifik "Sarapan pagi 11 rb"
$trx = App\Models\Transaction::where('amount', 11000)
    ->where('description', 'like', '%Sarapan pagi%')
    ->orderBy('created_at', 'desc')
    ->first();

if ($trx) {
    echo "✅ TRANSAKSI DITEMUKAN!\n";
    echo "   Trx ID: {$trx->id}\n";
    echo "   Deskripsi: '{$trx->description}'\n";
    echo "   Waktu: {$trx->created_at}\n";
    echo "\n   🚨 MASUK KE TENANT ID: {$trx->tenant_id} 🚨\n";
    
    // Inspect Tenant Pemilik
    $tenant = App\Models\Tenant::find($trx->tenant_id);
    echo "   Tenant Name: " . ($tenant ? $tenant->name : 'Unknown') . "\n";
    
    // Cek User Pemilik Tenant Tersebut
    $owner = App\Models\User::where('tenant_id', $trx->tenant_id)->first();
    if ($owner) {
        echo "   User Pemilik Tenant: {$owner->name}\n";
        echo "   Nomor HP Pemilik: {$owner->whatsapp_number}\n";
    }
    
    // Cek Total Pengeluaran Tenant Ini (Cocokkan dengan 10jt)
    $totalExp = App\Models\Transaction::where('tenant_id', $trx->tenant_id)
        ->where('type', 'expense')
        ->whereMonth('transaction_date', date('m'))
        ->whereYear('transaction_date', date('Y'))
        ->sum('amount');
        
    echo "   Total Pengeluaran Des: Rp " . number_format($totalExp) . "\n";
    
} else {
    echo "❌ Transaksi tidak ditemukan. Aneh sekali.\n";
}
echo "--- END ---\n";
