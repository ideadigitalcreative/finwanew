<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Reminder;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWeeklyDigest extends Command
{
    protected $signature = 'digest:weekly {--test : Test mode - only send to first user} {--no-delay : Skip delay between batches} {--tenant=* : Send to specific tenant ID(s)} {--recent : Only send to users with transactions in last 3 days}';

    protected $description = 'Send weekly financial digest to all active users';

    const BATCH_SIZE = 15;

    const BATCH_DELAY_SECONDS = 300;

    public function handle(): int
    {
        $this->info('Starting Weekly Digest...');

        $useLastWeek = ! $this->option('recent');

        if ($useLastWeek) {
            $startOfWeek = Carbon::now()->subWeek()->startOfWeek();
            $endOfWeek = Carbon::now()->subWeek()->endOfWeek();
        } else {
            $startOfWeek = Carbon::now()->subDays(7)->startOfDay();
            $endOfWeek = Carbon::now()->endOfDay();
        }

        $tenants = Tenant::whereHas('users', function ($q) {
            $q->whereNotNull('whatsapp_number');
        })->get();

        if ($this->option('recent')) {
            $recentTenantIds = Transaction::whereDate('transaction_date', '>=', $startOfWeek)
                ->whereDate('transaction_date', '<=', $endOfWeek)
                ->distinct()
                ->pluck('tenant_id');
            $tenants = $tenants->whereIn('id', $recentTenantIds);
            $this->info('Recent mode: Only processing tenants with transactions in digest range');
        } elseif ($tenantIds = $this->option('tenant')) {
            $tenants = $tenants->whereIn('id', $tenantIds);
            $this->info('Target mode: Only processing specified tenants');
        }

        if ($this->option('test')) {
            $tenants = $tenants->take(1);
            $this->info('Test mode: Only processing first tenant');
        }

        $this->info("Date range: {$startOfWeek->format('Y-m-d')} to {$endOfWeek->format('Y-m-d')}");

        $totalTenants = $tenants->count();
        $this->info("Found {$totalTenants} tenants to process");

        $batches = $tenants->chunk(self::BATCH_SIZE);
        $totalBatches = $batches->count();
        $this->info("Split into {$totalBatches} batches of ".self::BATCH_SIZE.' tenants each');

        $sent = 0;
        $errors = 0;
        $skipped = 0;
        $disabled = 0;
        $batchNumber = 0;

        foreach ($batches as $batch) {
            $batchNumber++;
            $this->info("\n--- Processing batch {$batchNumber}/{$totalBatches} ---");

            foreach ($batch as $tenant) {
                try {
                    $settings = $tenant->settings ?? [];
                    $digestEnabled = $settings['weekly_digest_enabled'] ?? true;

                    if (! $digestEnabled) {
                        $disabled++;
                        $this->info("Tenant {$tenant->id}: Weekly digest disabled, skipping");

                        continue;
                    }

                    $txCount = Transaction::where('tenant_id', $tenant->id)
                        ->whereDate('transaction_date', '>=', $startOfWeek)
                        ->whereDate('transaction_date', '<=', $endOfWeek)
                        ->count();
                    $this->info("Tenant {$tenant->id}: Found {$txCount} transactions in range ({$startOfWeek->format('Y-m-d')} to {$endOfWeek->format('Y-m-d')})");

                    $digest = $this->generateDigest($tenant, $startOfWeek, $endOfWeek);

                    if (! $digest) {
                        $skipped++;
                        $this->info("Tenant {$tenant->id}: No digest generated (empty data)");

                        continue;
                    }

                    $user = $tenant->users()->whereNotNull('whatsapp_number')->first();

                    if (! $user || ! $user->whatsapp_number) {
                        $skipped++;

                        continue;
                    }

                    $this->sendDigestToWhatsApp($user->whatsapp_number, $digest);
                    $sent++;

                    $this->info("Sent digest to tenant {$tenant->id}");

                    if (! $this->option('test')) {
                        sleep(2);
                    }

                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Failed to send weekly digest', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error("Error for tenant {$tenant->id}: {$e->getMessage()}");
                }
            }

            if ($batchNumber < $totalBatches && ! $this->option('test') && ! $this->option('no-delay')) {
                $delayMinutes = self::BATCH_DELAY_SECONDS / 60;
                $this->info("Batch {$batchNumber} complete. Waiting {$delayMinutes} minutes before next batch...");
                sleep(self::BATCH_DELAY_SECONDS);
            }
        }

        $this->info("\n=== Weekly Digest Complete ===");
        $this->info("Sent: {$sent}");
        $this->info("Skipped (no transactions): {$skipped}");
        $this->info("Disabled by user: {$disabled}");
        $this->info("Errors: {$errors}");

        return Command::SUCCESS;
    }

    public function generateDigest(Tenant $tenant, Carbon $startDate, Carbon $endDate): ?string
    {
        $transactions = Transaction::where('tenant_id', $tenant->id)
            ->whereDate('transaction_date', '>=', $startDate)
            ->whereDate('transaction_date', '<=', $endDate)
            ->with('category')
            ->get();

        if ($transactions->isEmpty()) {
            return null;
        }

        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $cashflow = $totalIncome - $totalExpense;

        $topCategories = $transactions->where('type', 'expense')
            ->groupBy('category_id')
            ->map(fn ($items) => [
                'name' => $items->first()->category->name ?? 'Lainnya',
                'total' => $items->sum('amount'),
            ])
            ->sortByDesc('total')
            ->take(3);

        $prevStart = $startDate->copy()->subWeek();
        $prevEnd = $endDate->copy()->subWeek();
        $prevExpense = Transaction::where('tenant_id', $tenant->id)
            ->where('type', 'expense')
            ->whereDate('transaction_date', '>=', $prevStart)
            ->whereDate('transaction_date', '<=', $prevEnd)
            ->sum('amount');

        $changePercent = 0;
        $changeText = '';
        if ($prevExpense > 0) {
            $changePercent = (($totalExpense - $prevExpense) / $prevExpense) * 100;
            if ($changePercent > 0) {
                $changeText = '📈 Naik '.abs(round($changePercent)).'% dari minggu lalu';
            } elseif ($changePercent < 0) {
                $changeText = '📉 Hemat '.abs(round($changePercent)).'% dari minggu lalu! 🎉';
            } else {
                $changeText = '📊 Sama dengan minggu lalu';
            }
        }

        $weekRange = $startDate->format('d').'-'.$endDate->translatedFormat('d F Y');

        $message = "📊 *Ringkasan Mingguan*\n";
        $message .= "📅 {$weekRange}\n";
        $message .= "━━━━━━━━━━━━━━━\n\n";

        $message .= '💰 Pemasukan: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
        $message .= '💸 Pengeluaran: Rp '.number_format($totalExpense, 0, ',', '.')."\n";

        $cashflowEmoji = $cashflow >= 0 ? '✅' : '❌';
        $message .= "⚖️ Cashflow: {$cashflowEmoji} Rp ".number_format($cashflow, 0, ',', '.')."\n\n";

        if ($changeText) {
            $message .= "{$changeText}\n\n";
        }

        // Daily breakdown
        $dailyBreakdown = $this->buildDailyBreakdown($transactions, $startDate, $endDate, $totalExpense);
        if ($dailyBreakdown) {
            $message .= "📅 *Per Hari:*\n{$dailyBreakdown}\n";
        }

        // Top categories
        if ($topCategories->isNotEmpty()) {
            $message .= "\n📁 *Top Pengeluaran:*\n";
            $index = 1;
            foreach ($topCategories as $cat) {
                $pct = $totalExpense > 0 ? round(($cat['total'] / $totalExpense) * 100) : 0;
                $message .= "{$index}. {$cat['name']}: Rp ".number_format($cat['total'], 0, ',', '.')." ({$pct}%)\n";
                $index++;
            }
            $message .= "\n";
        }

        // Upcoming bills for next week
        $upcomingBills = $this->getUpcomingBills($tenant, $endDate);
        if ($upcomingBills) {
            $message .= "📅 *Tagihan Minggu Depan:*\n{$upcomingBills}\n";
        }

        $txCount = $transactions->count();
        $message .= "📝 Total {$txCount} transaksi tercatat\n\n";

        $tips = $this->generatePersonalizedTips($tenant, $transactions, $cashflow, $totalExpense, $prevExpense);
        if ($tips) {
            $message .= "💡 *Tips Minggu Ini:*\n{$tips}\n\n";
        }

        if ($cashflow >= 0) {
            $motivations = [
                '💪 Mantap! Cashflow positif, lanjutkan!',
                '🌟 Kerja bagus! Terus kelola keuanganmu!',
                '🎯 Bagus! Keuangan sehat minggu ini!',
            ];
        } else {
            $motivations = [
                '💪 Semangat! Minggu depan bisa lebih baik!',
                '💡 Review pengeluaran dan sesuaikan budget ya!',
                '🎯 Coba set budget untuk kontrol pengeluaran!',
            ];
        }
        $message .= $motivations[array_rand($motivations)];
        $message .= "\n\n_Ketik 'matikan ringkasan mingguan' untuk nonaktifkan_";

        return $message;
    }

    protected function buildDailyBreakdown($transactions, Carbon $startDate, Carbon $endDate, float $totalExpense): ?string
    {
        $dayNames = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $lines = [];
        $maxExpense = 0;

        $dailyData = [];
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dayTransactions = $transactions->filter(fn ($tx) => Carbon::parse($tx->transaction_date)->isSameDay($date));
            $dayExpense = $dayTransactions->where('type', 'expense')->sum('amount');
            $dailyData[] = [
                'name' => $dayNames[$date->dayOfWeekIso - 1] ?? '?',
                'expense' => $dayExpense,
            ];
            $maxExpense = max($maxExpense, $dayExpense);
        }

        if ($maxExpense <= 0) {
            return null;
        }

        foreach ($dailyData as $day) {
            $barLength = $day['expense'] > 0 ? max(1, round(($day['expense'] / $maxExpense) * 8)) : 0;
            $bar = str_repeat('█', $barLength);
            $amount = $day['expense'] > 0 ? 'Rp '.number_format($day['expense'], 0, ',', '.') : '-';
            $lines[] = "{$day['name']}  {$amount}  {$bar}";
        }

        return implode("\n", $lines);
    }

    protected function getUpcomingBills(Tenant $tenant, Carbon $fromDate): ?string
    {
        $nextWeekStart = $fromDate->copy()->addDay()->startOfDay();
        $nextWeekEnd = $fromDate->copy()->addDays(7)->endOfDay();

        $reminders = Reminder::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get()
            ->filter(function ($reminder) use ($nextWeekStart, $nextWeekEnd) {
                if ($reminder->type === 'once' && $reminder->reminder_date) {
                    return Carbon::parse($reminder->reminder_date)->between($nextWeekStart, $nextWeekEnd);
                }

                if ($reminder->type === 'monthly' && $reminder->reminder_day) {
                    $day = (int) $reminder->reminder_day;
                    for ($d = $nextWeekStart->copy(); $d->lte($nextWeekEnd); $d->addDay()) {
                        if ((int) $d->format('d') === $day) {
                            return true;
                        }
                    }
                }

                if ($reminder->type === 'weekly' && $reminder->reminder_day !== null) {
                    $targetDay = (int) $reminder->reminder_day;
                    for ($d = $nextWeekStart->copy(); $d->lte($nextWeekEnd); $d->addDay()) {
                        if ((int) $d->dayOfWeek === $targetDay) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->sortBy(fn ($r) => $r->reminder_date ?? $r->created_at)
            ->take(5);

        if ($reminders->isEmpty()) {
            return null;
        }

        $lines = [];
        foreach ($reminders as $reminder) {
            $amount = $reminder->amount ? 'Rp '.number_format($reminder->amount, 0, ',', '.') : '';
            $lines[] = "• {$reminder->title} {$amount}";
        }

        return implode("\n", $lines)."\n\n";
    }

    /**
     * Generate personalized tips based on user's spending patterns
     */
    protected function generatePersonalizedTips($tenant, $transactions, float $cashflow, float $totalExpense, float $prevExpense): ?string
    {
        $tips = [];

        // Tip 1: If spending increased significantly
        if ($prevExpense > 0 && $totalExpense > $prevExpense * 1.3) {
            $topCategory = $transactions->where('type', 'expense')
                ->groupBy('category_id')
                ->map(fn ($items) => $items->sum('amount'))
                ->sortDesc()
                ->keys()
                ->first();

            if ($topCategory) {
                $categoryName = $transactions->where('category_id', $topCategory)->first()->category->name ?? 'kategori utama';
                $tips[] = "📊 Pengeluaran naik minggu ini. Coba review *{$categoryName}*";
            }
        }

        // Tip 2: If no budget set
        $hasBudget = \App\Models\Budget::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->exists();

        if (! $hasBudget && $totalExpense > 0) {
            $tips[] = '🎯 Set budget bulanan: _"set budget makan 1jt"_';
        }

        // Tip 3: If negative cashflow
        if ($cashflow < 0) {
            $tips[] = '⚖️ Fokus tingkatkan pemasukan atau kurangi pengeluaran';
        }

        // Tip 4: Suggest savings target if not set
        $hasSavingsGoal = \App\Models\SavingsGoal::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->exists();

        if (! $hasSavingsGoal && $cashflow > 0) {
            $tips[] = '💰 Cashflow positif! Set target tabungan: _"set target 5jt"_';
        }

        // Tip 5: Random feature highlight
        if (empty($tips)) {
            $featureTips = [
                '📸 Foto struk untuk catat otomatis dengan OCR',
                '📊 Ketik _"cek insight"_ untuk analisis spending',
                '🏆 Ketik _"lihat achievement"_ untuk cek badge Anda',
                '📱 Ketik _"cek langganan"_ untuk track pengeluaran rutin',
            ];
            $tips[] = $featureTips[array_rand($featureTips)];
        }

        return implode("\n", array_slice($tips, 0, 2)); // Max 2 tips
    }

    protected function sendDigestToWhatsApp(string $phoneNumber, string $message): void
    {
        try {
            $whatsAppService = new WhatsAppService;

            $sessionId = $this->getActiveWhatsAppSession();

            if (! $sessionId) {
                throw new \Exception('No active WhatsApp session found');
            }

            $formattedNumber = $this->formatPhoneNumber($phoneNumber);

            $result = $whatsAppService->sendMessage($sessionId, $formattedNumber, $message);

            if (! ($result['success'] ?? false)) {
                throw new \Exception($result['error'] ?? 'Failed to send message');
            }

            Log::info('Weekly digest sent', [
                'phone' => substr($formattedNumber, 0, 8).'***',
                'session' => $sessionId,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send digest via WhatsApp', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function getActiveWhatsAppSession(): ?string
    {
        $whatsAppService = new WhatsAppService;

        $channel = Channel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->get()
            ->first(function ($channel) use ($whatsAppService) {
                $config = is_array($channel->config) ? $channel->config : [];
                $sessionId = $config['session_id'] ?? null;

                if (! $sessionId) {
                    return false;
                }

                $statusResult = $whatsAppService->getSessionStatus($sessionId);
                if ($statusResult['success']) {
                    $status = strtolower($statusResult['status'] ?? 'unknown');

                    return in_array($status, ['connected', 'authenticated']);
                }

                return false;
            });

        if ($channel) {
            $config = is_array($channel->config) ? $channel->config : [];

            return $config['session_id'] ?? null;
        }

        return null;
    }

    protected function formatPhoneNumber(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number);

        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        } elseif (! str_starts_with($number, '62')) {
            $number = '62'.$number;
        }

        return $number;
    }
}
