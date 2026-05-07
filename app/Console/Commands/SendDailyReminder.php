<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\Reminder;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\UserStreak;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailyReminder extends Command
{
    protected $signature = 'reminder:daily {--test : Test mode - only process first tenant} {--force : Send even if user has transactions today} {--limit=0 : Limit number of reminders to send (0 = unlimited)}';

    protected $description = 'Send daily reminder to users who have not recorded any transaction today';

    // Default reminder hour (WIB = UTC+7, so 20:00 WIB = 13:00 UTC)
    const DEFAULT_REMINDER_HOUR = 20;

    public function handle(): int
    {
        $this->info('Starting Daily Reminder...');

        $currentHour = Carbon::now('Asia/Jakarta')->hour;
        $currentMinute = Carbon::now('Asia/Jakarta')->minute;
        $today = Carbon::now('Asia/Jakarta')->startOfDay();

        $this->info("Current time (WIB): {$currentHour}:{$currentMinute}");

        // Batch settings: 30 users every 3 minutes
        $batchSize = 30;
        $batchInterval = 3; // minutes

        // Calculate which batch to process (0, 1, 2, 3... based on minute)
        $batchNumber = intdiv($currentMinute, $batchInterval);
        $offset = $batchNumber * $batchSize;

        // Get all active tenants
        $allTenants = Tenant::where('is_active', true)->get();
        $totalTenants = $allTenants->count();

        if ($this->option('test')) {
            $tenants = $allTenants->take(1);
            $this->info('Test mode: Only processing first tenant');
        } elseif ($limit = (int) $this->option('limit')) {
            $tenants = $allTenants->take($limit);
            $this->info("Limit mode: Processing only {$limit} tenants");
        } else {
            // Batch mode: take 30 tenants starting from offset
            $tenants = $allTenants->slice($offset, $batchSize);
            $this->info("Batch mode: Processing batch #{$batchNumber} (offset: {$offset}, batch size: {$batchSize})");
        }

        $this->info("Found {$tenants->count()} tenants in current batch (total: {$totalTenants})");

        $sent = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            try {
                $settings = $tenant->settings ?? [];
                $reminderHour = $settings['reminder_hour'] ?? self::DEFAULT_REMINDER_HOUR;

                // CRITICAL: Check if user has disabled daily reminder
                $reminderEnabled = $settings['daily_reminder_enabled'] ?? true; // Default true for backward compatibility

                if (! $reminderEnabled) {
                    $skipped++;
                    $this->info("Tenant {$tenant->id}: Daily reminder disabled by user, skipping");

                    continue;
                }

                // Check if current hour matches user's preferred hour (bypass with --test or --force)
                if ($currentHour !== $reminderHour && ! $this->option('test') && ! $this->option('force')) {
                    continue; // Not time for this user's reminder
                }

                // Check if user already recorded transaction today
                $hasTransactionToday = Transaction::where('tenant_id', $tenant->id)
                    ->whereDate('transaction_date', $today)
                    ->exists();

                if ($hasTransactionToday && ! $this->option('force')) {
                    $skipped++;
                    $this->info("Tenant {$tenant->id}: Already has transactions today, skipping");

                    continue;
                }

                // Get user's WhatsApp number
                $user = $tenant->users()->whereNotNull('whatsapp_number')->first();

                if (! $user || ! $user->whatsapp_number) {
                    $skipped++;

                    continue;
                }

                // Generate and send reminder
                $message = $this->generateReminderMessage($tenant);
                $this->sendReminderToWhatsApp($user->whatsapp_number, $message);

                $sent++;
                $this->info("Sent reminder to tenant {$tenant->id}");

                // Small delay between messages
                if (! $this->option('test')) {
                    sleep(2);
                }

            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed to send daily reminder', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Error for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $this->info("\n=== Daily Reminder Complete ===");
        $this->info("Sent: {$sent}");
        $this->info("Skipped: {$skipped}");
        $this->info("Errors: {$errors}");

        return Command::SUCCESS;
    }

    protected function generateReminderMessage(Tenant $tenant): string
    {
        $settings = $tenant->settings ?? [];
        $userName = $settings['user_name'] ?? 'Kak';
        $inactivityDays = $this->getInactivityDays($tenant);

        $parts = [];

        if ($inactivityDays >= 3) {
            $parts[] = "😢 *{$userName}, kamu sudah {$inactivityDays} hari tidak catat transaksi!*";

            $lastTxSummary = $this->getLastTransactionSummary($tenant);
            if ($lastTxSummary) {
                $parts[] = $lastTxSummary;
            }

            $streak = UserStreak::where('tenant_id', $tenant->id)->first();
            if ($streak && $streak->current_streak > 0) {
                $parts[] = "🔥 Streak *{$streak->current_streak} hari* sudah terancam putus!";
            }

            $parts[] = 'Jangan khawatir, mulai lagi hari ini! 💪';
            $parts[] = '💡 _Kirim transaksi pertamamu:_';
            $parts[] = '• _makan siang 25rb_';
            $parts[] = '• _naik ojol 15rb_';
        } elseif ($inactivityDays >= 2) {
            $parts[] = "🔔 *{$userName}, sudah 2 hari tidak tercatat!*";

            $lastTxSummary = $this->getLastTransactionSummary($tenant);
            if ($lastTxSummary) {
                $parts[] = $lastTxSummary;
            }

            $streak = UserStreak::where('tenant_id', $tenant->id)->first();
            if ($streak && $streak->current_streak > 0) {
                $parts[] = "🔥 Streak *{$streak->current_streak} hari* terancam putus!";
            }

            $budgetAlerts = $this->getBudgetAlerts($tenant);
            if ($budgetAlerts) {
                $parts[] = $budgetAlerts;
            }

            $parts[] = '💡 _Kirim transaksi untuk menyelamatkan streak!_';
            $parts[] = '• _makan siang 25rb_';
        } else {
            $parts[] = '🔔 *Pengingat Harian FinWa*';
            $parts[] = "Halo {$userName}! 👋";

            $streakInfo = $this->getStreakInfo($tenant);
            if ($streakInfo) {
                $parts[] = $streakInfo;
            }

            $yesterdaySummary = $this->getYesterdaySummary($tenant);
            if ($yesterdaySummary) {
                $parts[] = $yesterdaySummary;
            }

            $budgetAlerts = $this->getBudgetAlerts($tenant);
            if ($budgetAlerts) {
                $parts[] = $budgetAlerts;
            }

            $upcomingBills = $this->getUpcomingBills($tenant);
            if ($upcomingBills) {
                $parts[] = $upcomingBills;
            }

            $parts[] = '💡 _Catat sekarang dengan mengirim:_';
            $parts[] = '• _makan siang 25rb_';
            $parts[] = '• _naik ojol 15rb_';
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

    protected function getLastTransactionSummary(Tenant $tenant): ?string
    {
        $lastTx = Transaction::where('tenant_id', $tenant->id)
            ->orderByDesc('transaction_date')
            ->first();

        if (! $lastTx) {
            return null;
        }

        $date = $lastTx->transaction_date instanceof Carbon
            ? $lastTx->transaction_date
            : Carbon::parse($lastTx->transaction_date);

        $daysAgo = Carbon::now('Asia/Jakarta')->startOfDay()
            ->diffInDays($date->startOfDay());

        $desc = $this->truncateText($lastTx->description, 25);
        $amount = number_format($lastTx->amount, 0, ',', '.');
        $dateLabel = $date->format('d M');

        return "📊 Transaksi terakhir ({$daysAgo} hari lalu, {$dateLabel}):\n".
            "   {$desc}: Rp {$amount}";
    }

    protected function getStreakInfo(Tenant $tenant): ?string
    {
        $streak = UserStreak::where('tenant_id', $tenant->id)->first();
        if (! $streak || $streak->current_streak < 1) {
            return null;
        }

        $streakCount = $streak->current_streak;

        if ($streakCount >= 7) {
            return "🔥 Streak *{$streakCount} hari* berturut-turut! Luar biasa!";
        }

        if ($streakCount >= 3) {
            return "🔥 Streak *{$streakCount} hari* — pertahankan!";
        }

        return "🔥 Streak *{$streakCount} hari* — jangan putus!";
    }

    protected function getYesterdaySummary(Tenant $tenant): ?string
    {
        $yesterday = Carbon::now('Asia/Jakarta')->subDay();
        $transactions = Transaction::where('tenant_id', $tenant->id)
            ->whereDate('transaction_date', $yesterday)
            ->get();

        if ($transactions->isEmpty()) {
            return null;
        }

        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $count = $transactions->count();

        $lines = ["📊 *Kemarin ({$count} transaksi):*"];

        $topExpenses = $transactions->where('type', 'expense')
            ->sortByDesc('amount')
            ->take(3);

        foreach ($topExpenses as $tx) {
            $desc = $this->truncateText($tx->description, 25);
            $amount = number_format($tx->amount, 0, ',', '.');
            $lines[] = "  💸 {$desc}: Rp {$amount}";
        }

        if ($totalExpense > 0) {
            $lines[] = '  Total keluar: Rp '.number_format($totalExpense, 0, ',', '.');
        }

        if ($totalIncome > 0) {
            $lines[] = '  💰 Masuk: Rp '.number_format($totalIncome, 0, ',', '.');
        }

        return implode("\n", $lines);
    }

    protected function getBudgetAlerts(Tenant $tenant): ?string
    {
        $budgets = Budget::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('period', 'monthly')
            ->get();

        if ($budgets->isEmpty()) {
            return null;
        }

        $now = Carbon::now('Asia/Jakarta');
        $startOfMonth = $now->copy()->startOfMonth();
        $daysInMonth = $now->daysInMonth;
        $currentDay = $now->day;
        $daysRemaining = $daysInMonth - $currentDay;
        $monthProgress = ($currentDay / $daysInMonth) * 100;

        $alerts = [];
        $totalBudget = 0;
        $totalSpending = 0;

        foreach ($budgets as $budget) {
            $totalBudget += $budget->amount;
            $spending = $budget->getCurrentSpending();
            $totalSpending += $spending;

            if ($budget->amount <= 0) {
                continue;
            }

            $usagePercent = ($spending / $budget->amount) * 100;
            $remaining = $budget->amount - $spending;
            $categoryName = $budget->category->name ?? '';

            if ($usagePercent >= 100) {
                $over = number_format(abs($remaining), 0, ',', '.');
                $alerts[] = "  🚨 *{$categoryName}* sudah habis! (lebih Rp {$over})";
            } elseif ($usagePercent >= 80 && $monthProgress < 80) {
                $dailyLimit = $daysRemaining > 0 ? $remaining / $daysRemaining : 0;
                $formattedRemaining = number_format($remaining, 0, ',', '.');
                $formattedDaily = number_format($dailyLimit, 0, ',', '.');
                $alerts[] = "  ⚠️ *{$categoryName}* tinggal Rp {$formattedRemaining} (Rp {$formattedDaily}/hari)";
            }
        }

        if (! empty($alerts)) {
            $total = number_format($totalSpending, 0, ',', '.');
            $message = "⚠️ *Budget Alert:*\n";
            $message .= implode("\n", $alerts);
            $message .= "\n  � Total bulan ini: Rp {$total}";

            return $message;
        }

        return null;
    }

    protected function getUpcomingBills(Tenant $tenant): ?string
    {
        $tomorrow = Carbon::now('Asia/Jakarta')->addDay()->startOfDay();
        $dayAfterTomorrow = $tomorrow->copy()->addDay();

        $upcomingReminders = Reminder::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(function ($q) use ($tomorrow, $dayAfterTomorrow) {
                $q->whereBetween('next_send_at', [$tomorrow, $dayAfterTomorrow])
                    ->orWhere(function ($q2) use ($tomorrow) {
                        $q2->whereNull('next_send_at')
                            ->whereDate('remind_date', $tomorrow);
                    });
            })
            ->get();

        if ($upcomingReminders->isEmpty()) {
            return null;
        }

        $lines = ['💡 *Besok jatuh tempo:*'];
        foreach ($upcomingReminders as $reminder) {
            $title = $reminder->title;
            $lines[] = "  📌 {$title}";
        }

        return implode("\n", $lines);
    }

    protected function truncateText(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length).'...';
    }

    protected function sendReminderToWhatsApp(string $phoneNumber, string $message): void
    {
        try {
            $whatsAppService = new WhatsAppService;

            // Find active WhatsApp session
            $sessionId = $this->getActiveWhatsAppSession();

            if (! $sessionId) {
                throw new \Exception('No active WhatsApp session found');
            }

            // Format phone number
            $formattedNumber = $this->formatPhoneNumber($phoneNumber);

            $result = $whatsAppService->sendMessage($sessionId, $formattedNumber, $message);

            if (! ($result['success'] ?? false)) {
                throw new \Exception($result['error'] ?? 'Failed to send message');
            }

            Log::info('Daily reminder sent', [
                'phone' => substr($formattedNumber, 0, 8).'***',
                'session' => $sessionId,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send daily reminder via WhatsApp', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function getActiveWhatsAppSession(): ?string
    {
        $channel = \App\Models\Channel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->first();

        if ($channel) {
            $config = $channel->config ?? [];

            return $config['session_id'] ?? null;
        }

        return null;
    }

    protected function formatPhoneNumber(string $number): string
    {
        // Remove any non-numeric characters
        $number = preg_replace('/[^0-9]/', '', $number);

        // Convert 08xxx to 628xxx
        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        }

        return $number;
    }
}
