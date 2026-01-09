<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Channel;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendWeeklyDigest extends Command
{
    protected $signature = 'digest:weekly {--test : Test mode - only send to first user} {--no-delay : Skip delay between batches}';
    protected $description = 'Send weekly financial digest to all active users';
    
    // Batch settings
    const BATCH_SIZE = 15;
    const BATCH_DELAY_SECONDS = 300; // 5 minutes

    public function handle(): int
    {
        $this->info('Starting Weekly Digest...');
        
        $startOfWeek = Carbon::now()->subWeek()->startOfWeek();
        $endOfWeek = Carbon::now()->subWeek()->endOfWeek();
        
        // Get active tenants with WhatsApp numbers
        $tenants = Tenant::whereHas('users', function ($q) {
            $q->whereNotNull('whatsapp_number');
        })->get();
        
        if ($this->option('test')) {
            $tenants = $tenants->take(1);
            $this->info('Test mode: Only processing first tenant');
        }
        
        $totalTenants = $tenants->count();
        $this->info("Found {$totalTenants} tenants to process");
        
        // Split into batches
        $batches = $tenants->chunk(self::BATCH_SIZE);
        $totalBatches = $batches->count();
        $this->info("Split into {$totalBatches} batches of " . self::BATCH_SIZE . " tenants each");
        
        $sent = 0;
        $errors = 0;
        $skipped = 0;
        $batchNumber = 0;
        
        foreach ($batches as $batch) {
            $batchNumber++;
            $this->info("\n--- Processing batch {$batchNumber}/{$totalBatches} ---");
            
            foreach ($batch as $tenant) {
                try {
                    $digest = $this->generateDigest($tenant, $startOfWeek, $endOfWeek);
                    
                    if (!$digest) {
                        $skipped++;
                        continue;
                    }
                    
                    // Get user's WhatsApp number
                    $user = $tenant->users()->whereNotNull('whatsapp_number')->first();
                    
                    if (!$user || !$user->whatsapp_number) {
                        $skipped++;
                        continue;
                    }
                    
                    // Send via WhatsApp
                    $this->sendDigestToWhatsApp($user->whatsapp_number, $digest);
                    $sent++;
                    
                    $this->info("Sent digest to tenant {$tenant->id}");
                    
                    // Small delay between messages in same batch (2 seconds)
                    if (!$this->option('test')) {
                        sleep(2);
                    }
                    
                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Failed to send weekly digest', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage()
                    ]);
                    $this->error("Error for tenant {$tenant->id}: {$e->getMessage()}");
                }
            }
            
            // Delay between batches (5 minutes), skip for last batch
            if ($batchNumber < $totalBatches && !$this->option('test') && !$this->option('no-delay')) {
                $delayMinutes = self::BATCH_DELAY_SECONDS / 60;
                $this->info("Batch {$batchNumber} complete. Waiting {$delayMinutes} minutes before next batch...");
                sleep(self::BATCH_DELAY_SECONDS);
            }
        }
        
        $this->info("\n=== Weekly Digest Complete ===");
        $this->info("Sent: {$sent}");
        $this->info("Skipped (no transactions): {$skipped}");
        $this->info("Errors: {$errors}");
        
        return Command::SUCCESS;
    }
    
    protected function generateDigest(Tenant $tenant, Carbon $startDate, Carbon $endDate): ?string
    {
        // Get week's transactions
        $transactions = Transaction::where('tenant_id', $tenant->id)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->with('category')
            ->get();
        
        if ($transactions->isEmpty()) {
            return null;
        }
        
        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $cashflow = $totalIncome - $totalExpense;
        
        // Top expense categories
        $topCategories = $transactions->where('type', 'expense')
            ->groupBy('category_id')
            ->map(fn ($items) => [
                'name' => $items->first()->category->name ?? 'Lainnya',
                'total' => $items->sum('amount')
            ])
            ->sortByDesc('total')
            ->take(3);
        
        // Compare with previous week
        $prevStart = $startDate->copy()->subWeek();
        $prevEnd = $endDate->copy()->subWeek();
        $prevExpense = Transaction::where('tenant_id', $tenant->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$prevStart, $prevEnd])
            ->sum('amount');
        
        $changePercent = 0;
        $changeText = '';
        if ($prevExpense > 0) {
            $changePercent = (($totalExpense - $prevExpense) / $prevExpense) * 100;
            if ($changePercent > 0) {
                $changeText = "📈 Naik " . abs(round($changePercent)) . "% dari minggu lalu";
            } elseif ($changePercent < 0) {
                $changeText = "📉 Hemat " . abs(round($changePercent)) . "% dari minggu lalu! 🎉";
            } else {
                $changeText = "📊 Sama dengan minggu lalu";
            }
        }
        
        // Build message
        $weekRange = $startDate->format('d') . '-' . $endDate->translatedFormat('d F Y');
        
        $message = "📊 *Ringkasan Mingguan*\n";
        $message .= "📅 {$weekRange}\n";
        $message .= "━━━━━━━━━━━━━━━\n\n";
        
        $message .= "💰 Pemasukan: Rp " . number_format($totalIncome, 0, ',', '.') . "\n";
        $message .= "💸 Pengeluaran: Rp " . number_format($totalExpense, 0, ',', '.') . "\n";
        
        $cashflowEmoji = $cashflow >= 0 ? '✅' : '❌';
        $message .= "⚖️ Cashflow: {$cashflowEmoji} Rp " . number_format($cashflow, 0, ',', '.') . "\n\n";
        
        if ($changeText) {
            $message .= "{$changeText}\n\n";
        }
        
        // Top categories
        if ($topCategories->isNotEmpty()) {
            $message .= "📁 *Top Pengeluaran:*\n";
            $index = 1;
            foreach ($topCategories as $cat) {
                $message .= "{$index}. {$cat['name']}: Rp " . number_format($cat['total'], 0, ',', '.') . "\n";
                $index++;
            }
            $message .= "\n";
        }
        
        // Transaction count
        $txCount = $transactions->count();
        $message .= "📝 Total {$txCount} transaksi tercatat\n\n";
        
        // Personalized tips based on spending analysis
        $tips = $this->generatePersonalizedTips($tenant, $transactions, $cashflow, $totalExpense, $prevExpense);
        if ($tips) {
            $message .= "💡 *Tips Minggu Ini:*\n{$tips}\n\n";
        }
        
        // Motivational message
        if ($cashflow >= 0) {
            $motivations = [
                "💪 Mantap! Cashflow positif, lanjutkan!",
                "🌟 Kerja bagus! Terus kelola keuanganmu!",
                "🎯 Bagus! Keuangan sehat minggu ini!",
            ];
        } else {
            $motivations = [
                "💪 Semangat! Minggu depan bisa lebih baik!",
                "💡 Review pengeluaran dan sesuaikan budget ya!",
                "🎯 Coba set budget untuk kontrol pengeluaran!",
            ];
        }
        $message .= $motivations[array_rand($motivations)];
        
        return $message;
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
                ->map(fn($items) => $items->sum('amount'))
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
        
        if (!$hasBudget && $totalExpense > 0) {
            $tips[] = "🎯 Set budget bulanan: _\"set budget makan 1jt\"_";
        }
        
        // Tip 3: If negative cashflow
        if ($cashflow < 0) {
            $tips[] = "⚖️ Fokus tingkatkan pemasukan atau kurangi pengeluaran";
        }
        
        // Tip 4: Suggest savings target if not set
        $hasSavingsGoal = \App\Models\SavingsGoal::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->exists();
        
        if (!$hasSavingsGoal && $cashflow > 0) {
            $tips[] = "💰 Cashflow positif! Set target tabungan: _\"set target 5jt\"_";
        }
        
        // Tip 5: Random feature highlight
        if (empty($tips)) {
            $featureTips = [
                "📸 Foto struk untuk catat otomatis dengan OCR",
                "📊 Ketik _\"cek insight\"_ untuk analisis spending",
                "🏆 Ketik _\"lihat achievement\"_ untuk cek badge Anda",
                "📱 Ketik _\"cek langganan\"_ untuk track pengeluaran rutin",
            ];
            $tips[] = $featureTips[array_rand($featureTips)];
        }
        
        return implode("\n", array_slice($tips, 0, 2)); // Max 2 tips
    }
    
    protected function sendDigestToWhatsApp(string $phoneNumber, string $message): void
    {
        try {
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
            
            $whatsappService = new WhatsAppService($channel);
            $whatsappService->sendMessage($phoneNumber, $message);
            
            Log::info('Weekly digest sent', [
                'phone' => substr($phoneNumber, 0, 6) . '***',
                'channel_id' => $channel->id
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to send digest via WhatsApp', [
                'phone' => substr($phoneNumber, 0, 6) . '***',
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
