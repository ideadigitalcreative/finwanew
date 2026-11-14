<?php
// Skrip diagnostik sementara — cek efek migration pending sudah ada atau belum
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

$checks = [
    // [tabel, [kolom]] — null kolom = cek tabel saja
    ['messages', ['metadata', 'status']],
    ['user_whatsapp_numbers', ['is_lid']],
    ['budgets', ['rollover_enabled', 'rollover_amount']],
    ['seo_pages', ['primary_keyword', 'deleted_at']],
    ['users', ['avatar']],
    ['transactions', ['internal_transfer_type']],
];

foreach ($checks as [$table, $cols]) {
    $exists = Schema::hasTable($table);
    echo "TABEL {$table}: " . ($exists ? 'ADA' : 'TIDAK ADA') . PHP_EOL;
    if ($exists && $cols) {
        foreach ($cols as $col) {
            echo "  kolom {$col}: " . (Schema::hasColumn($table, $col) ? 'ADA' : 'TIDAK ADA') . PHP_EOL;
        }
    }
}

// Cek enum categories
$results = DB::select("SHOW COLUMNS FROM categories WHERE Field = 'type'");
if ($results) {
    $type = $results[0]->Type;
    echo "ENUM categories.type: {$type}" . PHP_EOL;
} else {
    echo "ENUM categories.type: TIDAK DITEMUKAN" . PHP_EOL;
}

$results2 = DB::select("SHOW COLUMNS FROM transactions WHERE Field = 'type'");
if ($results2) {
    $type2 = $results2[0]->Type;
    echo "ENUM transactions.type: {$type2}" . PHP_EOL;
}