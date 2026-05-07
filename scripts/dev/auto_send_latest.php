<?php

/**
 * AUTO SEND CREDENTIALS TO LATEST USER
 * Find latest user and send credentials automatically
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\WhatsAppService;
use App\Models\User;
use App\Models\Channel;
use App\Models\UserWhatsAppNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

echo "=== AUTO SEND CREDENTIALS TO LATEST USER ===\n\n";

// 1. Get latest user (registered in last 10 minutes)
$latestUser = User::where('created_at', '>=', now()->subMinutes(10))
    ->orderBy('created_at', 'desc')
    ->first();

if (!$latestUser) {
    echo "❌ No user registered in last 10 minutes\n";
    exit;
}

echo "✅ Latest user found:\n";
echo "  ID: {$latestUser->id}\n";
echo "  Name: {$latestUser->name}\n";
echo "  Email: {$latestUser->email}\n";
echo "  Created: {$latestUser->created_at}\n";
echo "  Tenant ID: {$latestUser->tenant_id}\n\n";

// 2. Find WhatsApp number/LID
$whatsappNumber = UserWhatsAppNumber::where('user_id', $latestUser->id)
    ->where('is_active', true)
    ->first();

if (!$whatsappNumber) {
    echo "❌ No WhatsApp number found for this user\n";
    exit;
}

echo "✅ WhatsApp number found: {$whatsappNumber->whatsapp_number}\n";
echo "  Is LID: " . ($whatsappNumber->is_lid ? 'Yes' : 'No') . "\n\n";

// 3. Check if password is in log
echo "Searching password in log...\n";
$logFile = storage_path('logs/laravel.log');
$password = null;

if (file_exists($logFile)) {
    $lines = file($logFile);
    
    // Search from bottom (most recent)
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = $lines[$i];
        
        if (stripos($line, 'Registration Success') !== false && stripos($line, $latestUser->email) !== false) {
            for ($j = $i; $j < min($i + 10, count($lines)); $j++) {
                if (preg_match('/"password":"([^"]+)"/', $lines[$j], $matches)) {
                    $password = $matches[1];
                    break 2;
                }
            }
        }
    }
}

if (!$password) {
    echo "❌ Password not found in log, generating new one...\n";
    $password = strtoupper(Str::random(3)) . rand(100, 999);
    $latestUser->password = Hash::make($password);
    $latestUser->save();
    echo "✅ New password: {$password}\n\n";
} else {
    echo "✅ Password found in log: {$password}\n\n";
}

// 4. Get WhatsApp channel
$channel = Channel::where('is_active', true)->first();

if (!$channel) {
    echo "❌ No active WhatsApp channel found\n";
    echo "\nPlease send this manually:\n";
    echo "---\n";
    echo "🎉 Akun Berhasil Dibuat!\n\n";
    echo "📧 Email: {$latestUser->email}\n";
    echo "🔑 Password: {$password}\n\n";
    echo "🌐 Login di: https://finwa.web.id\n";
    echo "✨ Trial 30 hari sudah aktif!\n";
    echo "---\n";
    exit;
}

// 5. Send message
$message = "🎉 *Akun Berhasil Dibuat!*\n\n"
    . "📧 Email: *{$latestUser->email}*\n"
    . "🔑 Password: *{$password}*\n\n"
    . "🌐 Login di: https://finwa.web.id\n\n"
    . "✨ Trial 30 hari sudah aktif!\n\n"
    . "Sekarang Anda bisa langsung kirim transaksi ke saya. Contoh:\n"
    . "• _beli makan 25rb_\n"
    . "• _terima gaji 5jt_\n\n"
    . "Selamat mencoba! 🚀";

echo "Sending message to {$whatsappNumber->whatsapp_number}...\n";

try {
    $sessId = $channel->config['session_id'] ?? "wa_{$channel->tenant_id}_{$channel->channel_account}";
    
    $whatsappService = app(WhatsAppService::class);
    
    // Use originalLid if it's a LID
    $originalLid = $whatsappNumber->is_lid ? $whatsappNumber->whatsapp_number : null;
    
    $whatsappService->sendMessage(
        $sessId, 
        $whatsappNumber->whatsapp_number, 
        $message, 
        'text',
        $originalLid
    );
    
    echo "✅ Message sent successfully!\n";
    
} catch (\Exception $e) {
    echo "❌ Failed to send message: " . $e->getMessage() . "\n";
    echo "\nPlease send this manually:\n";
    echo "---\n{$message}\n---\n";
}
