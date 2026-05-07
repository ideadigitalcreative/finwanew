<?php

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;

echo "\n--- CLEANUP TENANT & SUBSCRIPTION 'Idea DigitalCreative' ---\n";

// 1. Hapus Subscription Cancelled (ID 227)
$sub = Subscription::find(227);
if ($sub && $sub->status === 'cancelled') {
    echo "🗑️ Menghapus Subscription Cancelled ID 227 (Plan: {$sub->plan})...\n";
    $sub->delete();
    echo "✅ Berhasil dihapus.\n";
} else {
    echo "ℹ️ Subscription 227 tidak perlu dihapus (tidak ada atau status bukan cancelled).\n";
}

// 2. Hapus Tenant Sampah (Tanpa Subscription)
// ID didapat dari debug sebelumnya: 10132, 10133, 10134
$garbageTenantIds = [10132, 10133, 10134];

foreach ($garbageTenantIds as $tid) {
    $t = Tenant::find($tid);
    if ($t) {
        $subCount = Subscription::where('tenant_id', $tid)->count();

        // Safety check: hanya hapus jika tidak punya subscription sama sekali
        if ($subCount == 0) {
            echo "🗑️ Menghapus Tenant Sampah ID {$tid} (0 Subscription)...\n";

            // Hapus user terkait tenant ini (jika ada)
            $users = User::where('tenant_id', $tid)->get();
            foreach ($users as $u) {
                echo "   -> Menghapus user terkait: {$u->name} ({$u->email})\n";
                $u->delete();
            }

            $t->delete();
            echo "✅ Tenant ID {$tid} Berhasil dihapus.\n";
        } else {
            echo "⚠️ Tenant ID {$tid} memiliki $subCount subscription. SKIPPED untuk keamanan.\n";
        }
    } else {
        echo "ℹ️ Tenant ID {$tid} sudah tidak ada.\n";
    }
}

echo "\nSelesai. Data Idea DigitalCreative seharusnya bersih (Tersisa ID 10135 Active).\n";
