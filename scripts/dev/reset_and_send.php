<?php

/**
 * RESET PASSWORD & SEND CREDENTIALS
 * Reset password and send to user via WhatsApp
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\WhatsAppService;
use App\Models\User;
use App\Models\Channel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$userId = $argv[1] ?? null;
$phoneOrLid = $argv[2] ?? null;

if (!$userId || !$phoneOrLid) {
    echo "Usage: php reset_and_send.php <user_id> <phone_or_lid>\n";
    echo "Example: php reset_and_send.php 111 6285159205506\n";
    exit;
}

echo "=== RESET PASSWORD & SEND CREDENTIALS ===\n\n";

// 1. Find user
$user = User::find($userId);
if (!$user) {
    echo "❌ User not found with ID: {$userId}\n";
    exit;
}

echo "✅ User found:\n";
echo "  ID: {$user->id}\n";
echo "  Name: {$user->name}\n";
echo "  Email: {$user->email}\n\n";

// 2. Generate new password
$newPassword = strtoupper(Str::random(3)) . rand(100, 999);
echo "Generated new password: {$newPassword}\n\n";

// 3. Update password
$user->password = Hash::make($newPassword);
$user->save();
echo "✅ Password updated in database\n\n";

// 4. Get WhatsApp channel
$channel = Channel::where('is_active', true)
    ->first();

if (!$channel) {
    echo "❌ No active WhatsApp channel found\n";
    echo "\nPlease send this manually:\n";
    echo "---\n";
    echo "🎉 Akun Berhasil Dibuat!\n\n";
    echo "📧 Email: {$user->email}\n";
    echo "🔑 Password: {$newPassword}\n\n";
    echo "🌐 Login di: https://finwa.web.id\n";
    echo "✨ Trial 30 hari sudah aktif!\n";
    echo "---\n";
    exit;
}

// 5. Prepare message
$message = "🎉 *Akun Berhasil Dibuat!*\n\n"
    . "📧 Email: *{$user->email}*\n"
    . "🔑 Password: *{$newPassword}*\n\n"
    . "🌐 Login di: https://finwa.web.id\n\n"
    . "✨ Trial 30 hari sudah aktif!\n\n"
    . "Sekarang Anda bisa langsung kirim transaksi ke saya. Contoh:\n"
    . "• _beli makan 25rb_\n"
    . "• _terima gaji 5jt_\n\n"
    . "Selamat mencoba! 🚀";

echo "Message:\n---\n{$message}\n---\n\n";

// 6. Send message
try {
    $sessId = $channel->config['session_id'] ?? "wa_{$channel->tenant_id}_{$channel->channel_account}";
    
    $whatsappService = app(WhatsAppService::class);
    $whatsappService->sendMessage($sessId, $phoneOrLid, $message, 'text');
    
    echo "✅ Message sent successfully to {$phoneOrLid}!\n";
    
} catch (\Exception $e) {
    echo "❌ Failed to send message: " . $e->getMessage() . "\n";
    echo "\nPlease send the message above manually to user.\n";
}
