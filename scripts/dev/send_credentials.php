<?php

/**
 * SEND CREDENTIALS TO USER
 * Send account credentials to registered user via WhatsApp
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\WhatsAppService;
use App\Models\User;
use App\Models\Channel;

$email = $argv[1] ?? null;
$phoneOrLid = $argv[2] ?? null;

if (!$email || !$phoneOrLid) {
    echo "Usage: php send_credentials.php <email> <phone_or_lid>\n";
    echo "Example: php send_credentials.php Rafada32@gmail.com 6285762000079\n";
    exit;
}

echo "=== SENDING CREDENTIALS ===\n\n";

// 1. Find user
$user = User::where('email', $email)->first();
if (!$user) {
    echo "❌ User not found with email: {$email}\n";
    exit;
}

echo "✅ User found:\n";
echo "  ID: {$user->id}\n";
echo "  Name: {$user->name}\n";
echo "  Email: {$user->email}\n";
echo "  Created: {$user->created_at}\n\n";

// 2. Search password in log
echo "Searching password in log...\n";
$logFile = storage_path('logs/laravel.log');
$password = null;

if (file_exists($logFile)) {
    $lines = file($logFile);
    
    // Search from bottom (most recent)
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = $lines[$i];
        
        if (stripos($line, 'Registration Success') !== false && stripos($line, $email) !== false) {
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
    echo "❌ Password not found in log\n";
    echo "\nPlease reset password manually:\n";
    echo "  php artisan tinker\n";
    echo "  \$user = User::find({$user->id});\n";
    echo "  \$user->password = Hash::make('NewPassword123');\n";
    echo "  \$user->save();\n";
    exit;
}

echo "✅ Password found: {$password}\n\n";

// 3. Get WhatsApp channel
$channel = Channel::where('channel_type', 'whatsapp')
    ->where('is_active', true)
    ->first();

if (!$channel) {
    echo "❌ No active WhatsApp channel found\n";
    exit;
}

echo "✅ WhatsApp channel found (ID: {$channel->id})\n\n";

// 4. Prepare message
$message = "🎉 *Akun Berhasil Dibuat!*\n\n"
    . "📧 Email: *{$email}*\n"
    . "🔑 Password: *{$password}*\n\n"
    . "🌐 Login di: https://finwa.web.id\n\n"
    . "✨ Trial 30 hari sudah aktif!\n\n"
    . "Sekarang Anda bisa langsung kirim transaksi ke saya. Contoh:\n"
    . "• _beli makan 25rb_\n"
    . "• _terima gaji 5jt_\n\n"
    . "Selamat mencoba! 🚀";

echo "Message to send:\n";
echo "---\n{$message}\n---\n\n";

// 5. Send message
try {
    $sessId = $channel->config['session_id'] ?? "wa_{$channel->tenant_id}_{$channel->channel_account}";
    
    $whatsappService = app(WhatsAppService::class);
    $whatsappService->sendMessage($sessId, $phoneOrLid, $message, 'text');
    
    echo "✅ Message sent successfully to {$phoneOrLid}!\n";
    
} catch (\Exception $e) {
    echo "❌ Failed to send message: " . $e->getMessage() . "\n";
    echo "\nPlease send this message manually to user:\n";
    echo "---\n{$message}\n---\n";
}
