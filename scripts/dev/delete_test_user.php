<?php
$phone = '6285179849922';
$lid = '81733663870987';

echo "\n--- HAPUS USER & MAPPING ---\n";

// Hapus mapping LID dulu
$mapDeleted = App\Models\UserWhatsAppNumber::where('whatsapp_number', $lid)->delete();
echo "LID mapping deleted: $mapDeleted\n";

$user = App\Models\User::where('whatsapp_number', $phone)->first();
if (!$user) {
    echo "❌ User tidak ditemukan.\n";
    exit;
}

$tenantId = $user->tenant_id;
echo "User: {$user->name} (ID: {$user->id}, Tenant: $tenantId)\n";

// Hapus semua data terkait
App\Models\UserWhatsAppNumber::where('tenant_id', $tenantId)->delete();
App\Models\Transaction::where('tenant_id', $tenantId)->delete();
App\Models\Balance::where('tenant_id', $tenantId)->delete();
App\Models\Category::where('tenant_id', $tenantId)->delete();
App\Models\Message::where('tenant_id', $tenantId)->delete();
App\Models\Channel::where('tenant_id', $tenantId)->delete();
App\Models\Subscription::where('tenant_id', $tenantId)->delete();

// Hapus User
$user->delete();

// Hapus Tenant
$tenant = App\Models\Tenant::find($tenantId);
if ($tenant) $tenant->delete();

echo "✅ User, Tenant, dan LID mapping berhasil dihapus.\n";
echo "   Silakan daftar ulang.\n";
