<?php

use App\Models\Subscription;

echo "\n--- CLEANUP SUBSCRIPTION DUPLIKAT ---\n";

// Target: ID 222 (Free) yang duplikat dengan 223 (Growth)
$duplicateId = 222;
$correctId = 223;

$sub = Subscription::find($duplicateId);
$correctSub = Subscription::find($correctId);

if ($sub && $correctSub && $sub->tenant_id === $correctSub->tenant_id) {
    echo "Ditemukan Subscription Duplikat:\n";
    echo "   🗑️  ID: {$sub->id} | Plan: {$sub->plan} | Status: {$sub->status}\n";
    echo "   ✅ ID: {$correctSub->id} | Plan: {$correctSub->plan} | Status: {$correctSub->status}\n";

    echo "Sedang menghapus ID {$sub->id}...\n";
    $sub->delete();
    echo "✅ Berhasil dihapus. Data 'Rizal Ahmad' sekarang bersih dari tab Free.\n";
} else {
    echo "❌ Data tidak valid atau sudah dihapus. Pastikan ID 222 dan 223 ada dan milik tenant yang sama.\n";
}
