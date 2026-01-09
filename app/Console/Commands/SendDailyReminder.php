<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

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
                
                if (!$reminderEnabled) {
                    $skipped++;
                    $this->info("Tenant {$tenant->id}: Daily reminder disabled by user, skipping");
                    continue;
                }
                
                // Check if current hour matches user's preferred hour (bypass with --test or --force)
                if ($currentHour !== $reminderHour && !$this->option('test') && !$this->option('force')) {
                    continue; // Not time for this user's reminder
                }
                
                // Check if user already recorded transaction today
                $hasTransactionToday = Transaction::where('tenant_id', $tenant->id)
                    ->whereDate('transaction_date', $today)
                    ->exists();
                
                if ($hasTransactionToday && !$this->option('force')) {
                    $skipped++;
                    $this->info("Tenant {$tenant->id}: Already has transactions today, skipping");
                    continue;
                }
                
                // Get user's WhatsApp number
                $user = $tenant->users()->whereNotNull('whatsapp_number')->first();
                
                if (!$user || !$user->whatsapp_number) {
                    $skipped++;
                    continue;
                }
                
                // Generate and send reminder
                $message = $this->generateReminderMessage($tenant);
                $this->sendReminderToWhatsApp($user->whatsapp_number, $message);
                
                $sent++;
                $this->info("Sent reminder to tenant {$tenant->id}");
                
                // Small delay between messages
                if (!$this->option('test')) {
                    sleep(2);
                }
                
            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed to send daily reminder', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage()
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
        
        // Get yesterday's summary for context
        $yesterday = Carbon::now('Asia/Jakarta')->subDay();
        $yesterdayTransactions = Transaction::where('tenant_id', $tenant->id)
            ->whereDate('transaction_date', $yesterday)
            ->get();
        
        $yesterdayExpense = $yesterdayTransactions->where('type', 'expense')->sum('amount');
        
        // Get month-to-date summary
        $startOfMonth = Carbon::now('Asia/Jakarta')->startOfMonth();
        $monthlyExpense = Transaction::where('tenant_id', $tenant->id)
            ->where('type', 'expense')
            ->whereDate('transaction_date', '>=', $startOfMonth)
            ->sum('amount');
        
        $message = "👋 *Hai {$userName}!*\n\n";
        $message .= "📝 Sudahkah kamu mencatat transaksi hari ini?\n\n";
        
        if ($yesterdayExpense > 0) {
            $message .= "📊 FYI, kemarin kamu menghabiskan:\n";
            $message .= "💸 Rp " . number_format($yesterdayExpense, 0, ',', '.') . "\n\n";
        }
        
        $message .= "📈 Total pengeluaran bulan ini:\n";
        $message .= "💰 Rp " . number_format($monthlyExpense, 0, ',', '.') . "\n\n";
        
        $message .= "💡 _Catat sekarang dengan mengirim:_\n";
        $message .= "• _makan siang 25rb_\n";
        $message .= "• _naik ojol 15rb_\n\n";
        
        $message .= "---\n";
        $message .= "_Ketik 'matikan reminder' untuk nonaktifkan_";
        
        return $message;
    }
    
    protected function sendReminderToWhatsApp(string $phoneNumber, string $message): void
    {
        try {
            $whatsAppService = new WhatsAppService();
            
            // Find active WhatsApp session
            $sessionId = $this->getActiveWhatsAppSession();
            
            if (!$sessionId) {
                throw new \Exception('No active WhatsApp session found');
            }
            
            // Format phone number
            $formattedNumber = $this->formatPhoneNumber($phoneNumber);
            
            $result = $whatsAppService->sendMessage($sessionId, $formattedNumber, $message);
            
            if (!($result['success'] ?? false)) {
                throw new \Exception($result['error'] ?? 'Failed to send message');
            }
            
            Log::info('Daily reminder sent', [
                'phone' => substr($formattedNumber, 0, 8) . '***',
                'session' => $sessionId
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to send daily reminder via WhatsApp', [
                'error' => $e->getMessage()
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
            $number = '62' . substr($number, 1);
        }
        
        return $number;
    }
}
