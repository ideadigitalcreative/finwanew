<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\UserStreak;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendMorningEscalation extends Command
{
    protected $signature = 'reminder:morning-escalation {--test : Test mode} {--limit=0 : Limit tenants (0 = all)}';

    protected $description = 'Send morning escalation to users inactive 2+ days';

    public function handle(): int
    {
        $this->info('Starting Morning Escalation...');

        $now = Carbon::now('Asia/Jakarta');
        $tenants = Tenant::where('is_active', true)->get();

        if ($limit = (int) $this->option('limit')) {
            $tenants = $tenants->take($limit);
        }

        $this->info("Processing {$tenants->count()} tenants");

        $sent = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            try {
                $settings = $tenant->settings ?? [];

                if (! ($settings['daily_reminder_enabled'] ?? true)) {
                    $skipped++;

                    continue;
                }

                $cacheKey = "morning_escalation_{$tenant->id}_".$now->format('Y-m-d');
                if (Cache::has($cacheKey)) {
                    $skipped++;

                    continue;
                }

                $inactivityDays = $this->getInactivityDays($tenant);

                if ($inactivityDays < 2) {
                    $skipped++;

                    continue;
                }

                $userName = $settings['user_name'] ?? 'Kak';
                $message = $this->buildEscalationMessage($tenant, $userName, $inactivityDays);

                if ($this->option('test')) {
                    $this->info("Tenant {$tenant->id}: Inactive {$inactivityDays} days (test mode)");
                    $this->line($message);
                    $this->line('---');

                    continue;
                }

                $user = $tenant->users()->whereNotNull('whatsapp_number')->first();

                if (! $user || ! $user->whatsapp_number) {
                    $skipped++;

                    continue;
                }

                $success = $this->sendToWhatsApp($tenant, $user->whatsapp_number, $message);

                if ($success) {
                    $sent++;
                    Cache::put($cacheKey, true, $now->copy()->endOfDay());
                    $this->info("Tenant {$tenant->id}: Sent (inactive {$inactivityDays} days)");
                } else {
                    $errors++;
                }

                sleep(2);

            } catch (\Exception $e) {
                $errors++;
                Log::error('Morning escalation failed', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("\n=== Morning Escalation Complete ===");
        $this->info("Sent: {$sent}");
        $this->info("Skipped: {$skipped}");
        $this->info("Errors: {$errors}");

        return Command::SUCCESS;
    }

    protected function buildEscalationMessage(Tenant $tenant, string $userName, int $inactivityDays): string
    {
        $parts = [];

        if ($inactivityDays >= 3) {
            $parts[] = "😢 *{$userName}, sudah {$inactivityDays} hari tidak mencatat!*";

            $streak = UserStreak::where('tenant_id', $tenant->id)->first();
            if ($streak && $streak->current_streak > 0) {
                $parts[] = "🔥 Streak *{$streak->current_streak} hari* sudah terancam putus.";
            }

            $lastTx = $this->getLastTransaction($tenant);
            if ($lastTx) {
                $date = Carbon::parse($lastTx->transaction_date)->format('d M');
                $desc = $this->truncateText($lastTx->description, 25);
                $amount = number_format($lastTx->amount, 0, ',', '.');
                $parts[] = "📊 Transaksi terakhir ({$date}):\n   {$desc}: Rp {$amount}";
            }

            $parts[] = 'Jangan khawatir! Mulai lagi hari ini 🚀';
            $parts[] = "💡 _Kirim transaksi pertamamu:_\n• _makan siang 25rb_";
        } else {
            $parts[] = "☀️ *Pagi, {$userName}!*";

            $streak = UserStreak::where('tenant_id', $tenant->id)->first();
            if ($streak && $streak->current_streak > 0) {
                $parts[] = "🔥 Streak *{$streak->current_streak} hari* terancam putus!";
            }

            $lastTx = $this->getLastTransaction($tenant);
            if ($lastTx) {
                $date = Carbon::parse($lastTx->transaction_date)->format('d M');
                $desc = $this->truncateText($lastTx->description, 25);
                $amount = number_format($lastTx->amount, 0, ',', '.');
                $parts[] = "📊 Transaksi terakhir ({$date}):\n   {$desc}: Rp {$amount}";
            }

            $parts[] = 'Jangan lupa catat transaksi hari ini! 💪';
        }

        $parts[] = '---';
        $parts[] = "_Ketik 'matikan reminder' untuk nonaktifkan_";

        return implode("\n\n", $parts);
    }

    protected function getInactivityDays(Tenant $tenant): int
    {
        $lastTx = Transaction::where('tenant_id', $tenant->id)
            ->orderByDesc('transaction_date')
            ->first();

        if (! $lastTx) {
            return 7;
        }

        $lastDate = $lastTx->transaction_date instanceof Carbon
            ? $lastTx->transaction_date
            : Carbon::parse($lastTx->transaction_date);

        return (int) Carbon::now('Asia/Jakarta')->startOfDay()
            ->diffInDays($lastDate->startOfDay());
    }

    protected function getLastTransaction(Tenant $tenant): ?Transaction
    {
        return Transaction::where('tenant_id', $tenant->id)
            ->orderByDesc('transaction_date')
            ->first();
    }

    protected function truncateText(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length).'...';
    }

    protected function sendToWhatsApp(Tenant $tenant, string $phoneNumber, string $message): bool
    {
        try {
            $whatsappService = new WhatsAppService;
            $sessionId = $this->getActiveSession($tenant);

            if (! $sessionId) {
                $sessionId = $this->getSharedSession();
            }

            if (! $sessionId) {
                Log::warning('No WhatsApp session for morning escalation', [
                    'tenant_id' => $tenant->id,
                ]);

                return false;
            }

            $formattedNumber = $this->formatPhoneNumber($phoneNumber);
            $result = $whatsappService->sendMessage($sessionId, $formattedNumber, $message);

            if ($result['success'] ?? false) {
                Log::info('Morning escalation sent', [
                    'tenant_id' => $tenant->id,
                    'phone' => substr($formattedNumber, 0, 8).'***',
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Exception morning escalation', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function getActiveSession(Tenant $tenant): ?string
    {
        $channel = Channel::where('tenant_id', $tenant->id)
            ->where('type', 'whatsapp')
            ->where('is_active', true)
            ->first();

        if ($channel) {
            $config = $channel->config ?? [];

            return $config['session_id'] ?? $channel->session_id ?? null;
        }

        return null;
    }

    protected function getSharedSession(): ?string
    {
        $channel = Channel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('is_shared_channel', true)
            ->first();

        if (! $channel) {
            $channel = Channel::where('type', 'whatsapp')
                ->where('is_active', true)
                ->first();
        }

        if ($channel) {
            $config = $channel->config ?? [];

            return $config['session_id'] ?? $channel->session_id ?? null;
        }

        return null;
    }

    protected function formatPhoneNumber(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number);

        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        }

        return $number;
    }
}
