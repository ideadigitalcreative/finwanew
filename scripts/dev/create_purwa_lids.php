<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== CREATING LID MAPPINGS FOR PURWA ===\n\n";

$lids = ['122213311238273', '171652679762073'];

foreach ($lids as $lid) {
    $existing = \App\Models\UserWhatsAppNumber::where('whatsapp_number', $lid)->first();

    if (! $existing) {
        \App\Models\UserWhatsAppNumber::create([
            'user_id' => 106,
            'tenant_id' => 10012,
            'whatsapp_number' => $lid,
            'name' => 'LID - '.substr($lid, 0, 8),
            'is_primary' => false,
            'is_active' => true,
            'is_lid' => true,
        ]);
        echo "✅ Created LID: $lid\n";
    } else {
        echo "⚠️  LID already exists: $lid\n";
    }
}

echo "\n=== DONE ===\n";
echo "User Purwa sekarang bisa kirim pesan dari kedua nomor!\n";
