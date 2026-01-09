<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Channel;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:send-reminders {--test : Test mode - only show what would be sent}';
    protected $description = 'Send reminder to users 2 days and 1 day before subscription expires';

    public function handle(): int
    {
        $this->info('Checking for subscriptions expiring soon...');
        
        $sent = 0;
        $errors = 0;
        
        // Check for H-2 (2 days before)
        $this->info('--- Checking H-2 (2 days before) ---');
        $result2 = $this->sendRemindersForDays(2);
        $sent += $result2['sent'];
        $errors += $result2['errors'];
        
        // Check for H-1 (1 day before)
        $this->info('--- Checking H-1 (1 day before) ---');
        $result1 = $this->sendRemindersForDays(1);
        $sent += $result1['sent'];
        $errors += $result1['errors'];
        
        $this->info("Subscription Reminders Complete: {$sent} sent, {$errors} errors");
        
        return Command::SUCCESS;
    }
    
    protected function sendRemindersForDays(int $daysBeforeExpiry): array
    {
        $targetDate = Carbon::now()->addDays($daysBeforeExpiry)->startOfDay();
        $nextDay = Carbon::now()->addDays($daysBeforeExpiry + 1)->startOfDay();
        
        // Find active subscriptions expiring on target date
        $expiringSubscriptions = Subscription::where('status', 'active')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$targetDate, $nextDay])
            ->with(['tenant.users'])
            ->get();
        
        $this->info("Found {$expiringSubscriptions->count()} subscriptions expiring in {$daysBeforeExpiry} day(s).");
        
        $sent = 0;
        $errors = 0;
        
        foreach ($expiringSubscriptions as $subscription) {
            try {
                $tenant = $subscription->tenant;
                if (!$tenant) {
                    continue;
                }
                
                // Get user's WhatsApp number
                $user = $tenant->users()->whereNotNull('whatsapp_number')->first();
                
                if (!$user || !$user->whatsapp_number) {
                    $this->warn("No WhatsApp number for tenant {$tenant->id}");
                    continue;
                }
                
                $message = $this->buildReminderMessage($subscription, $tenant, $daysBeforeExpiry);
                
                if ($this->option('test')) {
                    $this->info("Would send to {$user->whatsapp_number}:");
                    $this->line($message);
                    $this->newLine();
                    continue;
                }
                
                // Send via WhatsApp
                $this->sendWhatsAppMessage($user->whatsapp_number, $message);
                $sent++;
                
                $this->info("Sent reminder to tenant {$tenant->id} ({$user->whatsapp_number})");
                
            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed to send subscription reminder', [
                    'subscription_id' => $subscription->id,
                    'days_before' => $daysBeforeExpiry,
                    'error' => $e->getMessage()
                ]);
                $this->error("Error for subscription {$subscription->id}: {$e->getMessage()}");
            }
        }
        
        return ['sent' => $sent, 'errors' => $errors];
    }
    
    protected function buildReminderMessage(Subscription $subscription, $tenant, int $daysBeforeExpiry = 2): string
    {
        $planName = ucfirst($subscription->plan);
        $expiryDate = $subscription->ends_at->translatedFormat('d F Y');
        
        // Use urgency emoji based on days left
        $urgencyEmoji = $daysBeforeExpiry <= 1 ? '🚨' : '⏰';
        $urgencyText = $daysBeforeExpiry <= 1 ? 'BESOK' : "{$daysBeforeExpiry} hari";
        
        $message = "{$urgencyEmoji} *Pengingat Langganan*\n";
        $message .= "━━━━━━━━━━━━━━━\n\n";
        
        if ($daysBeforeExpiry <= 1) {
            $message .= "⚠️ *PERHATIAN!* Langganan *{$planName}* Anda akan berakhir *{$urgencyText}*!\n\n";
        } else {
            $message .= "Halo! Langganan *{$planName}* Anda akan berakhir dalam *{$urgencyText}*.\n\n";
        }
        
        $message .= "📅 Tanggal Berakhir: {$expiryDate}\n";
        $message .= "📦 Paket: {$planName}\n\n";
        
        if ($subscription->plan === 'trial') {
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
        
        $message .= "_Abaikan jika sudah perpanjang. Terima kasih telah menggunakan FinWa!_ 🙏";
        
        return $message;
    }
    
    protected function sendWhatsAppMessage(string $phoneNumber, string $message): void
    {
        // Find active shared channel
        $channel = Channel::where('is_active', true)
            ->where('is_shared_channel', true)
            ->first();
        
        if (!$channel) {
            $channel = Channel::where('is_active', true)->first();
        }
        
        if (!$channel) {
            throw new \Exception('No active WhatsApp channel found');
        }
        
        // Get session_id from channel config
        $config = $channel->config ?? [];
        $sessionId = $config['session_id'] ?? null;
        
        if (!$sessionId) {
            throw new \Exception("Channel {$channel->id} has no session_id configured");
        }
        
        // Create WhatsAppService without parameters (uses config values)
        $whatsappService = new WhatsAppService();
        $result = $whatsappService->sendMessage($sessionId, $phoneNumber, $message);
        
        if (!$result['success']) {
            throw new \Exception($result['error'] ?? 'Failed to send WhatsApp message');
        }
        
        Log::info('Subscription reminder sent', [
            'phone' => substr($phoneNumber, 0, 6) . '***',
            'channel_id' => $channel->id,
            'session_id' => $sessionId
        ]);
    }
}
