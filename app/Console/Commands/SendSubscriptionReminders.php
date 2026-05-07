<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Subscription;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:send-reminders {--test : Test mode - only show what would be sent} {--phone= : Send only to specific phone number}';

    protected $description = 'Send reminder to users 2 days and 1 day before subscription expires';

    public function handle(): int
    {
        $this->info('Checking for subscriptions expiring soon...');

        if ($this->option('phone')) {
            $this->info('Targeting specific phone: '.$this->option('phone'));
        }

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
        $query = Subscription::where('status', 'active')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$targetDate, $nextDay]);

        // Filter by phone if provided
        if ($this->option('phone')) {
            $targetPhone = $this->option('phone');
            $query->whereHas('tenant.users', function ($q) use ($targetPhone) {
                $q->where('whatsapp_number', $targetPhone)
                    ->orWhere('whatsapp_number', 'like', '%'.substr($targetPhone, -8));
            });
        }

        $expiringSubscriptions = $query->with(['tenant.users'])->get();

        $this->info("Found {$expiringSubscriptions->count()} subscriptions expiring in {$daysBeforeExpiry} day(s).");

        $sent = 0;
        $errors = 0;

        foreach ($expiringSubscriptions as $subscription) {
            try {
                $tenant = $subscription->tenant;
                if (! $tenant) {
                    continue;
                }

                // Get user's WhatsApp number
                $user = $tenant->users()->whereNotNull('whatsapp_number')->first();

                if (! $user || ! $user->whatsapp_number) {
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
                    'error' => $e->getMessage(),
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
        $message .= "Butuh bantuan? Hubungi admin: https://wa.link/vcz1jx\n\n";

        $message .= '_Abaikan jika sudah perpanjang. Terima kasih telah menggunakan FinWa!_ 🙏';

        return $message;
    }

    protected function sendWhatsAppMessage(string $phoneNumber, string $message): void
    {
        // Failover strategy: Try ALL active channels starting with shared channel
        $channels = Channel::where('is_active', true)
            ->orderBy('is_shared_channel', 'desc') // Prioritize system/shared channel
            ->orderBy('updated_at', 'desc') // Then most recently active
            ->get();

        if ($channels->isEmpty()) {
            throw new \Exception('No active WhatsApp channel found in database');
        }

        $whatsappService = new WhatsAppService;
        $lastError = null;
        $success = false;

        foreach ($channels as $channel) {
            try {
                // Determine Session ID
                $sessionId = $channel->session_id;

                if (empty($sessionId) && ! empty($channel->config['session_id'])) {
                    $sessionId = $channel->config['session_id'];
                }

                if (empty($sessionId)) {
                    $sessionId = "wa_{$channel->tenant_id}_{$channel->channel_account}";
                }

                // DEBUG output
                if ($this->output->isVerbose() || true) {
                    $this->info("   -> Trying Channel ID: {$channel->id} ({$channel->channel_account}), Session: {$sessionId}");
                }

                // Prepare Phone Number
                $targetPhone = $phoneNumber;
                $isToLid = str_contains($targetPhone, '@lid');

                if (! $isToLid) {
                    // Normalize standard number
                    if (str_starts_with($targetPhone, '0')) {
                        $targetPhone = '62'.substr($targetPhone, 1);
                    }
                }

                // Attempt Send
                if ($isToLid) {
                    $result = $whatsappService->sendMessageToLid($sessionId, $targetPhone, $message);
                } else {
                    $result = $whatsappService->sendMessage($sessionId, $targetPhone, $message);
                }

                // Check Result
                if ($result['success']) {
                    Log::info('Subscription reminder sent successfully', [
                        'phone' => substr($targetPhone, 0, 8).'***',
                        'channel_id' => $channel->id,
                        'session_id' => $sessionId,
                    ]);
                    $success = true;

                    return; // SENT! Exit function.
                } else {
                    // Extract error
                    $error = $result['error'] ?? 'Unknown error';

                    // If Session Not Found, try to auto-reconnect basic session? (Optional optimization)
                    // For now, just log and try next channel.
                    throw new \Exception($error);
                }

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                if ($this->output->isVerbose() || true) {
                    $this->warn("      Failed with Channel {$channel->id}: {$lastError}");
                }

                continue; // Try next channel
            }
        }

        // If we get here, all channels failed
        throw new \Exception('Failed to send message via all '.$channels->count()." active channels. Last error: {$lastError}");
    }
}
