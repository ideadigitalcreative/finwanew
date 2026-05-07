<?php

use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

// === KONFIGURASI ===
// Ganti nomor ini sesuai nomor WA user yang mau dites
$targetPhone = '6285159205506';
// ===================

echo "\n============================================\n";
echo "   UJICOBA REMINDER SUBSCRIPTION (MANUAL)   \n";
echo "============================================\n";

echo "🔍 Mencari user dengan nomor: $targetPhone ...\n";

$user = User::where('whatsapp_number', $targetPhone)->first();

// Coba format lain (08 vs 628)
if (! $user) {
    $altPhone = str_starts_with($targetPhone, '62') ? '0'.substr($targetPhone, 2) : '62'.substr($targetPhone, 1);
    $user = User::where('whatsapp_number', $altPhone)->first();
}

if (! $user) {
    echo "❌ User tidak ditemukan! Pastikan nomor WA benar.\n";

    return;
}

echo "✅ Ditemukan User: {$user->name}\n";
echo "🏢 Tenant ID: {$user->tenant_id}\n";

$tenant = $user->tenant;
if (! $tenant) {
    echo "❌ Tenant user tidak valid.\n";

    return;
}

// Cari atau buat subscription
$sub = Subscription::firstOrCreate(
    ['tenant_id' => $tenant->id],
    ['status' => 'active', 'plan' => 'pro'] // Default ke pro, bukan premium
);

// Backup tanggal asli agar tidak merusak data real
$originalDate = $sub->ends_at;
$originalStatus = $sub->status;

echo '💾 Backup data asli: '.($originalDate ? $originalDate->format('Y-m-d') : 'Null')." (Plan: {$sub->plan})\n";

// Pastikan status active agar reminder jalan
$sub->status = 'active';
// Tidak mengubah plan agar tidak error enum/truncation
$sub->save();

try {
    // ----------------------------------------------------
    // TEST 1: SKENARIO H-2 (2 HARI LAGI EXPIRED)
    // ----------------------------------------------------
    echo "\n--------------------------------------------\n";
    echo "[TEST 1] Setting Expired H-2 (2 Hari Lagi)\n";

    // Set expired lusa (H+2)
    // Kita tambah 2 hari dari sekarang
    $targetDateH2 = Carbon::now()->addDays(2)->startOfDay()->addHours(12); // Siang hari
    $sub->ends_at = $targetDateH2;
    $sub->save();

    echo '📅 Tanggal Expired di-set ke: '.$sub->ends_at->translatedFormat('l, d F Y H:i')."\n";
    echo "🚀 Menjalankan command reminder...\n";

    Artisan::call('subscriptions:send-reminders', [
        '--phone' => $targetPhone,
    ]);

    echo "📋 Output System:\n";
    echo trim(Artisan::output())."\n";
    echo "✅ Cek WhatsApp Anda sekarang (Pesan '2 hari' harusnya masuk).\n";

    // Jeda sejenak
    echo "⏳ Menunggu 2 detik...\n";
    sleep(2);

    // ----------------------------------------------------
    // TEST 2: SKENARIO H-1 (BESOK EXPIRED)
    // ----------------------------------------------------
    echo "\n--------------------------------------------\n";
    echo "[TEST 2] Setting Expired H-1 (Besok)\n";

    // Set expired besok (H+1)
    $targetDateH1 = Carbon::now()->addDays(1)->startOfDay()->addHours(12);
    $sub->ends_at = $targetDateH1;
    $sub->save();

    echo '📅 Tanggal Expired di-set ke: '.$sub->ends_at->translatedFormat('l, d F Y H:i')."\n";
    echo "🚀 Menjalankan command reminder...\n";

    Artisan::call('subscriptions:send-reminders', [
        '--phone' => $targetPhone,
    ]);

    echo "📋 Output System:\n";
    echo trim(Artisan::output())."\n";
    echo "✅ Cek WhatsApp Anda sekarang (Pesan 'BESOK' harusnya masuk).\n";

} catch (\Exception $e) {
    echo '❌ Terjadi Error: '.$e->getMessage()."\n";
} finally {
    // ----------------------------------------------------
    // RESTORE DATA
    // ----------------------------------------------------
    echo "\n--------------------------------------------\n";
    echo "🔄 RESTORE DATA...\n";
    $sub->ends_at = $originalDate;
    $sub->status = $originalStatus;
    $sub->save();
    echo "✅ Data subscription dikembalikan ke tanggal semula.\n";
    echo "============================================\n";
}
