<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Category;

$user = \App\Models\User::find(129); // Rashid Family User ID
if (! $user) {
    exit("❌ User ID 129 tidak ditemukan.\n");
}
$tenantId = $user->tenant_id;

echo "=== FIX USER CATEGORIES (Restoring Defaults) ===\n";
echo "User ID: {$user->id}\n";
echo "Target Tenant ID: $tenantId\n";

$categories = [
    // Pendapatan
    ['type' => 'pendapatan_gaji', 'name' => 'Gaji', 'slug' => 'gaji', 'icon' => '💰', 'color' => '#10b981'],
    ['type' => 'pendapatan_bonus', 'name' => 'Bonus', 'slug' => 'bonus', 'icon' => '🎁', 'color' => '#10b981'],
    ['type' => 'pendapatan_investasi', 'name' => 'Investasi', 'slug' => 'investasi', 'icon' => '📈', 'color' => '#10b981'],
    ['type' => 'pendapatan_lainnya', 'name' => 'Pendapatan Lainnya', 'slug' => 'pendapatan-lainnya', 'icon' => '💵', 'color' => '#10b981'],

    // Pengeluaran
    ['type' => 'pengeluaran_makanan', 'name' => 'Makanan & Minuman', 'slug' => 'makanan-minuman', 'icon' => '🍽️', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_transport', 'name' => 'Transport', 'slug' => 'transport', 'icon' => '🚗', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_hunian', 'name' => 'Hunian', 'slug' => 'hunian', 'icon' => '🏠', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_utilitas', 'name' => 'Utilitas', 'slug' => 'utilitas', 'icon' => '⚡', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_kesehatan', 'name' => 'Kesehatan', 'slug' => 'kesehatan', 'icon' => '🏥', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_pendidikan', 'name' => 'Pendidikan', 'slug' => 'pendidikan', 'icon' => '📚', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_belanja', 'name' => 'Belanja', 'slug' => 'belanja', 'icon' => '🛒', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_hiburan', 'name' => 'Hiburan', 'slug' => 'hiburan', 'icon' => '🎬', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_pulsa_token', 'name' => 'Pulsa & Token', 'slug' => 'pulsa-token', 'icon' => '📱', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_tagihan', 'name' => 'Tagihan', 'slug' => 'tagihan', 'icon' => '📄', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_investasi', 'name' => 'Investasi', 'slug' => 'investasi-pengeluaran', 'icon' => '💼', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_pinjaman', 'name' => 'Pinjaman', 'slug' => 'pinjaman', 'icon' => '💳', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_asuransi', 'name' => 'Asuransi', 'slug' => 'asuransi', 'icon' => '🛡️', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_pajak', 'name' => 'Pajak', 'slug' => 'pajak', 'icon' => '📊', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_donasi', 'name' => 'Donasi', 'slug' => 'donasi', 'icon' => '❤️', 'color' => '#ef4444'],
    // ['type' => 'pengeluaran_keluarga', 'name' => 'Keluarga', 'slug' => 'keluarga', 'icon' => '👨‍👩‍👧‍👦', 'color' => '#ef4444'],
    ['type' => 'pengeluaran_lainnya', 'name' => 'Pengeluaran Lainnya', 'slug' => 'pengeluaran-lainnya', 'icon' => '📝', 'color' => '#ef4444'],
];

$count = 0;
foreach ($categories as $cat) {
    Category::firstOrCreate(
        [
            'tenant_id' => $tenantId,
            'slug' => $cat['slug'],
        ],
        [
            'type' => $cat['type'],
            'name' => $cat['name'],
            'icon' => $cat['icon'],
            'color' => $cat['color'],
            'is_system' => true,
        ]
    );
    $count++;
}

echo "✅ Berhasil restore $count kategori untuk user $tenantId.\n";
