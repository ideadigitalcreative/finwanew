<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\Channel;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendReminders extends Command
{
    protected $signature = 'finwa:send-reminders';
    protected $description = 'Send pending reminders to users via WhatsApp';

    public function handle(): int
    {
        $this->info('Checking for pending reminders...');
        
        $now = now();
        
        // Get active reminders that are due
        $reminders = Reminder::where('is_active', true)
            ->where('next_send_at', '<=', $now)
            ->with(['tenant.user', 'tenant.channels'])
            ->get();
        
        $sent = 0;
        $failed = 0;
        
        foreach ($reminders as $reminder) {
            try {
                $success = $this->sendReminderNotification($reminder);
                
                if ($success) {
                    $reminder->last_sent_at = $now;
                    
                    // Deactivate if one-time reminder
                    if ($reminder->type === 'once') {
                        $reminder->is_active = false;
                        $reminder->save();
                    } else {
                        // Calculate next send time for recurring reminders
                        $reminder->calculateNextSendAt();
                    }
                    
                    $sent++;
                    $this->info("✓ Sent reminder: {$reminder->title} to tenant {$reminder->tenant_id}");
                } else {
                    $failed++;
                    $this->warn("✗ Failed to send reminder: {$reminder->title}");
                }
                
            } catch (\Exception $e) {
                $failed++;
                Log::error('Error sending reminder', [
                    'reminder_id' => $reminder->id,
                    'error' => $e->getMessage()
                ]);
                $this->error("Error: {$e->getMessage()}");
            }
        }
        
        $this->info("Completed. Sent: {$sent}, Failed: {$failed}");
        
        return self::SUCCESS;
    }
    
    protected function sendReminderNotification(Reminder $reminder): bool
    {
        $tenant = $reminder->tenant;
        
        if (!$tenant || !$tenant->user) {
            return false;
        }
        
        $user = $tenant->user;
        $whatsappNumber = $user->whatsapp_number;
        
        if (!$whatsappNumber) {
            return false;
        }
        
        // Find active WhatsApp channel
        $channel = Channel::where('tenant_id', $tenant->id)
            ->where('type', 'whatsapp')
            ->where('is_active', true)
            ->first();
        
        if (!$channel) {
            // Try to find any connected channel for system notifications
            $channel = Channel::where('type', 'whatsapp')
                ->where('is_active', true)
                ->first();
        }
        
        if (!$channel) {
            return false;
        }
        
        $config = $channel->config ?? [];
        $sessionId = $config['session_id'] ?? null;
        
        if (!$sessionId) {
            return false;
        }
        
        // Build reminder message
        $message = "🔔 *Pengingat FinWa*\n\n";
        $message .= "Halo {$user->name}!\n\n";
        $message .= "📌 *{$reminder->title}*\n";
        
        if ($reminder->description) {
            $message .= "{$reminder->description}\n";
        }
        
        if ($reminder->amount > 0) {
            $amount = number_format($reminder->amount, 0, ',', '.');
            $message .= "💰 Jumlah: Rp {$amount}\n";
        }
        
        $message .= "\n";
        
        // Add helpful suggestions
        if ($reminder->amount > 0 && $reminder->category_type) {
            $message .= "💡 _Ketik untuk mencatat:_\n";
            $categoryLabel = $this->getCategoryLabel($reminder->category_type);
            $amountFormatted = $this->formatAmountShort($reminder->amount);
            $message .= "_\"{$categoryLabel} {$amountFormatted}\"_\n\n";
        }
        
        $message .= "━━━━━━━━━━━━━\n";
        $message .= "_Pengingat otomatis dari FinWa_";
        
        // Send via WhatsApp
        $whatsappService = app(WhatsAppService::class);
        $result = $whatsappService->sendMessage($sessionId, $whatsappNumber, $message);
        
        return $result['success'] ?? false;
    }
    
    protected function getCategoryLabel(string $categoryType): string
    {
        $labels = [
            'pengeluaran_tagihan' => 'bayar tagihan',
            'pengeluaran_utilitas' => 'bayar listrik',
            'pengeluaran_kesehatan' => 'biaya kesehatan',
            'pengeluaran_pendidikan' => 'biaya pendidikan',
            'pengeluaran_transport' => 'transport',
            'pengeluaran_makanan' => 'makan',
            'pengeluaran_belanja' => 'belanja',
            'pendapatan_gaji' => 'terima gaji',
            'pendapatan_bonus' => 'dapat bonus',
        ];
        
        return $labels[$categoryType] ?? 'catat pengeluaran';
    }
    
    protected function formatAmountShort(float $amount): string
    {
        if ($amount >= 1000000) {
            return round($amount / 1000000, 1) . 'jt';
        } elseif ($amount >= 1000) {
            return round($amount / 1000) . 'rb';
        }
        return (string) $amount;
    }
}
