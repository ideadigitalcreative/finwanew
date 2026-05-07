<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== LINKING LID FOR FEBRIANSYAH ===\n\n";

$lid = '261512321085485';
$userId = 107;
$tenantId = 10013;

$existing = \App\Models\UserWhatsAppNumber::where('whatsapp_number', $lid)->first();

if (! $existing) {
    \App\Models\UserWhatsAppNumber::create([
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'whatsapp_number' => $lid,
        'name' => 'LID - '.substr($lid, 0, 8),
        'is_primary' => false,
        'is_active' => true,
        'is_lid' => true,
    ]);
    echo "✅ Created LID: $lid\n";
    echo "   User: Febriansyah Nur Alvino (ID: $userId)\n";
    echo "   Tenant: $tenantId\n";
} else {
    echo "⚠️  LID already exists: $lid\n";
}

echo "\n=== DONE ===\n";
echo "User Febriansyah sekarang bisa kirim pesan!\n";
