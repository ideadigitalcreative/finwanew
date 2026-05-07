<?php

require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Channel;
use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;

$phone = '6285255021716';

echo "=== DIAGNOSTIK NOMOR: {$phone} ===\n\n";

// 1. Cek di tabel users
echo "--- 1. Tabel users (whatsapp_number) ---\n";
$user = User::where('whatsapp_number', $phone)->first();
if ($user) {
    echo "  ✅ DITEMUKAN: user_id={$user->id}, name={$user->name}, email={$user->email}, tenant_id={$user->tenant_id}\n";
} else {
    echo "  ❌ Tidak ditemukan di users.whatsapp_number\n";
}

// 2. Cek di tabel user_whatsapp_numbers
echo "\n--- 2. Tabel user_whatsapp_numbers ---\n";
$waNumbers = UserWhatsAppNumber::where('whatsapp_number', $phone)->get();
if ($waNumbers->count() > 0) {
    foreach ($waNumbers as $wn) {
        $u = User::find($wn->user_id);
        $userName = $u->name ?? 'N/A';
        echo "  ✅ ID={$wn->id}, user_id={$wn->user_id} ({$userName}), tenant_id={$wn->tenant_id}, is_primary={$wn->is_primary}, is_active={$wn->is_active}, created={$wn->created_at}\n";
    }
} else {
    echo "  ❌ Tidak ditemukan di user_whatsapp_numbers\n";
}

// 3. Cek di tabel user_lid_mappings
echo "\n--- 3. Tabel user_lid_mappings ---\n";
$lidMappings = UserLidMapping::where('phone_number', $phone)->get();
if ($lidMappings->count() > 0) {
    foreach ($lidMappings as $lm) {
        echo "  ✅ ID={$lm->id}, user_id={$lm->user_id}, lid={$lm->lid}, tenant_id={$lm->tenant_id}\n";
    }
} else {
    echo "  ❌ Tidak ditemukan di user_lid_mappings\n";
}

// 4. Cek di tabel channels
echo "\n--- 4. Tabel channels (channel_account) ---\n";
$channels = Channel::where('channel_account', $phone)->get();
if ($channels->count() > 0) {
    foreach ($channels as $ch) {
        echo "  ✅ ID={$ch->id}, tenant_id={$ch->tenant_id}, session_id={$ch->session_id}, session_status={$ch->session_status}, is_active={$ch->is_active}, is_shared={$ch->is_shared_channel}\n";
        echo '     Config session_id: '.($ch->config['session_id'] ?? 'NULL')."\n";
        echo '     Config session_status: '.($ch->config['session_status'] ?? 'NULL')."\n";
    }
} else {
    echo "  ❌ Tidak ditemukan di channels\n";
}

// 5. Cek shared channels yang aktif
echo "\n--- 5. Shared Channels Aktif ---\n";
$sharedChannels = Channel::where('type', 'whatsapp')
    ->where('is_shared_channel', true)
    ->where('is_active', true)
    ->orderBy('id', 'desc')
    ->get();

if ($sharedChannels->count() > 0) {
    foreach ($sharedChannels as $ch) {
        $configSessionId = $ch->config['session_id'] ?? 'NULL';
        $configSessionStatus = $ch->config['session_status'] ?? 'NULL';
        echo "  📡 Channel ID={$ch->id}, account={$ch->channel_account}\n";
        echo '     DB session_id: '.($ch->session_id ?? 'NULL')."\n";
        echo '     DB session_status: '.($ch->session_status ?? 'NULL')."\n";
        echo "     Config session_id: {$configSessionId}\n";
        echo "     Config session_status: {$configSessionStatus}\n";
        echo "     is_active: {$ch->is_active}\n";
        echo '     last_activity: '.($ch->last_activity_at ?? 'NULL')."\n";
        echo '     Full config: '.json_encode($ch->config)."\n\n";
    }
} else {
    echo "  ⚠️ TIDAK ADA shared channel aktif!\n";
}

// 6. Cek channels untuk tenant 10313
echo "\n--- 6. Channels untuk Tenant 10313 ---\n";
$tenantChannels = Channel::where('tenant_id', 10313)
    ->where('type', 'whatsapp')
    ->get();

if ($tenantChannels->count() > 0) {
    foreach ($tenantChannels as $ch) {
        echo "  📡 Channel ID={$ch->id}, account={$ch->channel_account}, is_active={$ch->is_active}, session_status=".($ch->session_status ?? 'NULL')."\n";
    }
} else {
    echo "  ❌ Tenant 10313 tidak punya channel WhatsApp sendiri\n";
}

// 7. Test getSharedWhatsAppChannel()
echo "\n--- 7. Test getSharedWhatsAppChannel() ---\n";
try {
    $mappingService = new \App\Services\WhatsAppUserMappingService;
    $sharedChannel = $mappingService->getSharedWhatsAppChannel();
    if ($sharedChannel) {
        echo "  ✅ Shared channel ditemukan: ID={$sharedChannel->id}, account={$sharedChannel->channel_account}\n";
        $sessionId = $sharedChannel->config['session_id'] ?? $sharedChannel->session_id ?? "wa_{$sharedChannel->tenant_id}_{$sharedChannel->channel_account}";
        echo "  📎 Session ID yang akan digunakan: {$sessionId}\n";
    } else {
        echo "  ❌ getSharedWhatsAppChannel() returned NULL\n";
    }
} catch (\Exception $e) {
    echo '  🔴 EXCEPTION: '.$e->getMessage()."\n";
    echo '  Trace: '.$e->getTraceAsString()."\n";
}

// 8. Test findUserByWhatsAppNumber()
echo "\n--- 8. Test findUserByWhatsAppNumber('{$phone}') ---\n";
try {
    $foundUser = $mappingService->findUserByWhatsAppNumber($phone);
    if ($foundUser) {
        echo "  ✅ User ditemukan: ID={$foundUser->id}, name={$foundUser->name}, tenant_id={$foundUser->tenant_id}\n";
    } else {
        echo "  ❌ User tidak ditemukan via findUserByWhatsAppNumber\n";
    }
} catch (\Exception $e) {
    echo '  🔴 EXCEPTION: '.$e->getMessage()."\n";
}

echo "\n=== SELESAI ===\n";
