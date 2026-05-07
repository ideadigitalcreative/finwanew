<?php

/**
 * Script untuk menambahkan kategori pengeluaran_keluarga ke semua tenant yang ada.
 *
 * Jalankan dengan: php artisan tinker < add_keluarga_category.php
 * Atau: php add_keluarga_category.php (jika di-bootstrap manual)
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use App\Models\Tenant;

$tenants = Tenant::all();
$count = 0;

foreach ($tenants as $tenant) {
    $exists = Category::where('tenant_id', $tenant->id)
        ->where('type', 'pengeluaran_keluarga')
        ->exists();

    if (! $exists) {
        Category::create([
            'tenant_id' => $tenant->id,
            'type' => 'pengeluaran_keluarga',
            'name' => 'Keluarga',
            'slug' => 'keluarga-'.$tenant->id,
            'description' => 'Pengeluaran untuk keluarga (orang tua, istri, anak, adik, kakak)',
            'icon' => '👨‍👩‍👧‍👦',
            'color' => '#ef4444',
            'is_system' => true,
        ]);
        $count++;
        echo "Created category for tenant {$tenant->id}\n";
    }
}

echo "\n✅ Created pengeluaran_keluarga category for {$count} tenants\n";
