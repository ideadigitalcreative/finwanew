<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Category\CategoryManagerService;
use Illuminate\Support\Facades\DB;

$manager = app(CategoryManagerService::class);

$allTypes = [
    'pendapatan_gaji', 'pendapatan_bonus', 'pendapatan_investasi', 'pendapatan_transfer',
    'pendapatan_lainnya', 'pengeluaran_makanan', 'pengeluaran_transport', 'pengeluaran_hunian',
    'pengeluaran_utilitas', 'pengeluaran_kesehatan', 'pengeluaran_pendidikan', 'pengeluaran_belanja',
    'pengeluaran_hiburan', 'pengeluaran_pulsa_token', 'pengeluaran_tagihan', 'pengeluaran_investasi',
    'pengeluaran_pinjaman', 'pengeluaran_cicilan', 'pengeluaran_asuransi', 'pengeluaran_pajak',
    'pengeluaran_donasi', 'pengeluaran_gaji', 'pengeluaran_keluarga', 'pengeluaran_langganan',
    'pengeluaran_modal', 'pengeluaran_operasional', 'pengeluaran_transfer', 'pengeluaran_lainnya',
    'debit_internal', 'kredit_internal',
];

$placeholders = implode(',', array_fill(0, count($allTypes), '?'));

$missingTenants = DB::select("
    SELECT t.id as tenant_id
    FROM tenants t
    WHERE EXISTS (SELECT 1 FROM categories c WHERE c.tenant_id = t.id)
      AND (
        SELECT COUNT(DISTINCT c.type)
        FROM categories c
        WHERE c.tenant_id = t.id AND c.type IN ({$placeholders})
      ) < ?
", array_merge($allTypes, [count($allTypes)]));

echo 'Found '.count($missingTenants)." tenants with missing categories\n\n";

$added = 0;

foreach ($missingTenants as $row) {
    $tenantId = $row->tenant_id;
    $existing = DB::table('categories')
        ->where('tenant_id', $tenantId)
        ->whereIn('type', $allTypes)
        ->pluck('type')
        ->toArray();
    $missing = array_diff($allTypes, $existing);
    if (empty($missing)) {
        continue;
    }

    echo "Tenant {$tenantId}: missing ".count($missing).' → '.implode(', ', $missing)."\n";
    $manager->createCategoriesForTenant($tenantId);
    $added++;
}

echo "\n=== DONE: {$added} tenants updated ===\n";
