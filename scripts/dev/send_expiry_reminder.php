<?php

/**
 * Script untuk mengirim notifikasi manual ke user yang akan expired
 *
 * Usage:
 *   php send_expiry_reminder.php                    # Lihat daftar user yang akan expired
 *   php send_expiry_reminder.php send <user_id>    # Kirim reminder ke user tertentu
 *   php send_expiry_reminder.php send all          # Kirim reminder ke semua yang akan expired dalam 7 hari
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Channel;
use App\Models\Subscription;
use App\Services\WhatsAppService;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║       🔔 MANUAL SUBSCRIPTION EXPIRY REMINDER SENDER          ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo '║  Current Time: '.str_pad(now()->format('Y-m-d H:i:s'), 45)."║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$action = $argv[1] ?? 'list';
$target = $argv[2] ?? null;

// Get subscriptions expiring in next 7 days
$expiringSubscriptions = Subscription::where('status', 'active')
    ->whereNotNull('ends_at')
    ->where('ends_at', '>', now())
    ->where('ends_at', '<', now()->addDays(7))
    ->with(['tenant.users'])
    ->orderBy('ends_at')
    ->get();

if ($action === 'list' || ! $target) {
    echo "📋 SUBSCRIPTIONS EXPIRING IN NEXT 7 DAYS:\n";
    echo str_repeat('─', 80)."\n";

    if ($expiringSubscriptions->count() === 0) {
        echo "✅ Tidak ada subscription yang akan expired dalam 7 hari ke depan.\n\n";
    } else {
        printf("%-5s | %-8s | %-15s | %-20s | %-15s | %s\n",
            'ID', 'SubID', 'Plan', 'Ends At', 'Days Left', 'WhatsApp');
        echo str_repeat('─', 80)."\n";

        foreach ($expiringSubscriptions as $sub) {
            $user = $sub->tenant?->users()->whereNotNull('whatsapp_number')->first();
            $phone = $user ? $user->whatsapp_number : '-';
            $daysLeft = round(now()->diffInDays($sub->ends_at, false), 1);
            $userId = $user ? $user->id : '-';

            printf("%-5s | %-8s | %-15s | %-20s | %-15s | %s\n",
                $userId,
                $sub->id,
                $sub->plan,
                $sub->ends_at->format('Y-m-d H:i'),
                "{$daysLeft} hari",
                $phone
            );
        }

        echo str_repeat('─', 80)."\n";
        echo "Total: {$expiringSubscriptions->count()} subscription(s)\n\n";
    }

    echo "📌 USAGE:\n";
    echo "  php send_expiry_reminder.php send <user_id>  - Kirim ke user tertentu\n";
    echo "  php send_expiry_reminder.php send all        - Kirim ke semua yang mau expired\n\n";
    exit(0);
}

if ($action === 'send') {
    // Check WhatsApp channel
    $channel = Channel::where('is_active', true)
        ->where('is_shared_channel', true)
        ->first();

    if (! $channel) {
        $channel = Channel::where('is_active', true)->first();
    }

    if (! $channel) {
        echo "❌ ERROR: Tidak ada WhatsApp channel yang aktif!\n";
        exit(1);
    }

    $config = $channel->config ?? [];
    $sessionId = $config['session_id'] ?? null;

    if (! $sessionId) {
        echo "❌ ERROR: Channel tidak memiliki session_id!\n";
        exit(1);
    }

    echo "✅ Using channel: {$channel->channel_account} (Session: {$sessionId})\n\n";

    $whatsappService = new WhatsAppService;
    $sent = 0;
    $errors = 0;

    // Filter by user ID if specified
    if ($target !== 'all') {
        $userId = (int) $target;
        $expiringSubscriptions = $expiringSubscriptions->filter(function ($sub) use ($userId) {
            $user = $sub->tenant?->users()->whereNotNull('whatsapp_number')->first();

            return $user && $user->id === $userId;
        });

        if ($expiringSubscriptions->count() === 0) {
            echo "❌ Tidak ditemukan subscription untuk user ID: {$userId}\n";
            exit(1);
        }
    }

    foreach ($expiringSubscriptions as $sub) {
        $user = $sub->tenant?->users()->whereNotNull('whatsapp_number')->first();

        if (! $user || ! $user->whatsapp_number) {
            echo "⚠️  Skip Sub #{$sub->id}: No WhatsApp number\n";

            continue;
        }

        $daysLeft = ceil(now()->diffInDays($sub->ends_at, false));
        $message = buildReminderMessage($sub, $daysLeft);

        echo "📤 Sending to {$user->name} ({$user->whatsapp_number})...\n";

        try {
            $result = $whatsappService->sendMessage($sessionId, $user->whatsapp_number, $message);

            if ($result['success']) {
                echo "   ✅ Sent successfully!\n";
                $sent++;
            } else {
                echo '   ❌ Failed: '.($result['error'] ?? 'Unknown error')."\n";
                $errors++;
            }
        } catch (\Exception $e) {
            echo '   ❌ Error: '.$e->getMessage()."\n";
            $errors++;
        }

        // Small delay to avoid rate limiting
        usleep(500000); // 0.5 second
    }

    echo "\n".str_repeat('═', 50)."\n";
    echo "📊 SUMMARY: {$sent} sent, {$errors} errors\n";
    echo str_repeat('═', 50)."\n\n";
}

function buildReminderMessage(Subscription $subscription, int $daysLeft): string
{
    $planName = ucfirst($subscription->plan);
    $expiryDate = $subscription->ends_at->translatedFormat('d F Y');

    // Use urgency emoji based on days left
    $urgencyEmoji = $daysLeft <= 1 ? '🚨' : '⏰';
    $urgencyText = $daysLeft <= 1 ? 'BESOK' : "{$daysLeft} hari";

    $message = "{$urgencyEmoji} *Pengingat Langganan*\n";
    $message .= "━━━━━━━━━━━━━━━\n\n";

    if ($daysLeft <= 1) {
        $message .= "⚠️ *PERHATIAN!* Langganan *{$planName}* Anda akan berakhir *{$urgencyText}*!\n\n";
    } else {
        $message .= "Halo! Langganan *{$planName}* Anda akan berakhir dalam *{$urgencyText}*.\n\n";
    }

    $message .= "📅 Tanggal Berakhir: {$expiryDate}\n";
    $message .= "📦 Paket: {$planName}\n\n";

    if ($subscription->plan === 'trial' || $subscription->plan === 'free') {
        $message .= "🎁 *Upgrade ke Premium* untuk fitur lengkap:\n";
        $message .= "• Unlimited transaksi\n";
        $message .= "• Export laporan PDF/Excel\n";
        $message .= "• Multi dompet\n";
        $message .= "• Prioritas support\n\n";
        $message .= "💰 Hanya Rp 20.000/bulan\n\n";
    } else {
        $message .= "🔄 *Perpanjang sekarang* untuk terus menikmati:\n";
        $message .= "• Catat transaksi unlimited\n";
        $message .= "• Laporan keuangan lengkap\n";
        $message .= "• Budget & target tabungan\n\n";
    }

    $message .= "Kunjungi dashboard untuk perpanjang:\n";
    $message .= "🔗 https://finwa.web.id/dashboard\n\n";
    $message .= "Butuh bantuan? Hubungi admin: https://wa.link/vcz1jx\n\n";

    $message .= '_Abaikan jika sudah perpanjang. Terima kasih telah menggunakan FinWa!_ 🙏';

    return $message;
}
