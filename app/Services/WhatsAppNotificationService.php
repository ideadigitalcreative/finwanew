<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Bank;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    protected $whatsappService;
    protected $systemTenantId = 1; // Tenant sistem untuk notifikasi
    protected $superAdminWhatsAppNumber = '6285242766676'; // Nomor WhatsApp super admin

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Get system channel that is connected
     * Priority: Find channel by super admin WhatsApp number, fallback to system tenant
     */
    protected function getSystemChannel(): ?Channel
    {
        try {
            // First, try to find channel by super admin WhatsApp number (from any tenant)
            $superAdminChannel = Channel::where('type', 'whatsapp')
                ->where('channel_account', $this->superAdminWhatsAppNumber)
                ->where('is_active', true)
                ->get()
                ->first(function ($channel) {
                    $config = $channel->config ?? [];
                    $sessionId = $config['session_id'] ?? null;
                    
                    if (!$sessionId) {
                        return false;
                    }
                    
                    // Check if session is connected
                    $statusResult = $this->whatsappService->getSessionStatus($sessionId);
                    if ($statusResult['success']) {
                        $status = strtolower($statusResult['status'] ?? 'unknown');
                        return in_array($status, ['connected', 'authenticated']);
                    }
                    
                    return false;
                });
            
            if ($superAdminChannel) {
                Log::info('Using super admin WhatsApp channel for notification', [
                    'channel_id' => $superAdminChannel->id,
                    'channel_account' => $superAdminChannel->channel_account,
                    'tenant_id' => $superAdminChannel->tenant_id
                ]);
                return $superAdminChannel;
            }
            
            // Fallback: Get first connected WhatsApp channel from system tenant
            $channel = Channel::where('tenant_id', $this->systemTenantId)
                ->where('type', 'whatsapp')
                ->where('is_active', true)
                ->get()
                ->first(function ($channel) {
                    $config = $channel->config ?? [];
                    $sessionId = $config['session_id'] ?? null;
                    
                    if (!$sessionId) {
                        return false;
                    }
                    
                    // Check if session is connected
                    $statusResult = $this->whatsappService->getSessionStatus($sessionId);
                    if ($statusResult['success']) {
                        $status = strtolower($statusResult['status'] ?? 'unknown');
                        return in_array($status, ['connected', 'authenticated']);
                    }
                    
                    return false;
                });
            
            if ($channel) {
                Log::info('Using system tenant WhatsApp channel for notification', [
                    'channel_id' => $channel->id,
                    'channel_account' => $channel->channel_account,
                    'tenant_id' => $channel->tenant_id
                ]);
            }
            
            return $channel;
        } catch (\Exception $e) {
            Log::error('Error getting system channel', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send notification message
     * Supports LID format for users who originally sent messages via LID
     * 
     * @param string $toNumber Phone number or LID address
     * @param string $message Message to send
     * @param int|null $userId Optional user ID to look up LID mapping
     */
    protected function sendNotification(string $toNumber, string $message, ?int $userId = null): bool
    {
        try {
            $channel = $this->getSystemChannel();
            
            if (!$channel) {
                Log::warning('System WhatsApp channel not available for notification', [
                    'to_number' => $toNumber
                ]);
                return false;
            }

            $config = $channel->config ?? [];
            $sessionId = $config['session_id'] ?? null;

            if (!$sessionId) {
                Log::warning('System WhatsApp channel has no session ID', [
                    'channel_id' => $channel->id,
                    'to_number' => $toNumber
                ]);
                return false;
            }

            // Check if we have a LID mapping for this user
            $lidAddress = null;
            if ($userId) {
                $lidMapping = \App\Models\UserWhatsAppNumber::where('user_id', $userId)
                    ->where('is_lid', true)
                    ->first();
                
                if ($lidMapping) {
                    $lidAddress = $lidMapping->whatsapp_number;
                    if (!str_contains($lidAddress, '@lid')) {
                        $lidAddress .= '@lid';
                    }
                    Log::info('Found LID mapping for user', [
                        'user_id' => $userId,
                        'lid_address' => $lidAddress
                    ]);
                }
            }
            
            // Also check if the number itself looks like an internal ID (not a valid phone number)
            // Indonesian phone numbers with country code: 628xxxx = 12-13 digits max
            // Internal WhatsApp IDs can be 15+ digits
            $cleanNumber = preg_replace('/[^0-9]/', '', $toNumber);
            $isInternalId = strlen($cleanNumber) >= 14; // Indonesian numbers are max ~13 digits with country code
            
            if ($isInternalId && !$lidAddress) {
                // This looks like an internal ID, try to send as LID
                $lidAddress = $cleanNumber . '@lid';
                Log::info('Number looks like internal ID, using as LID', [
                    'original' => $toNumber,
                    'lid_address' => $lidAddress,
                    'number_length' => strlen($cleanNumber)
                ]);
            }
            
            // Send message via LID if available, otherwise normal phone number
            if ($lidAddress) {
                $result = $this->whatsappService->sendMessageToLid($sessionId, $lidAddress, $message);
            } else {
                $result = $this->whatsappService->sendMessage($sessionId, $toNumber, $message);

            }

            if ($result['success']) {
                Log::info('WhatsApp notification sent successfully', [
                    'to_number' => $toNumber,
                    'lid_address' => $lidAddress,
                    'channel_id' => $channel->id
                ]);
                return true;
            } else {
                Log::error('Failed to send WhatsApp notification', [
                    'to_number' => $toNumber,
                    'lid_address' => $lidAddress,
                    'error' => $result['error'] ?? 'Unknown error',
                    'channel_id' => $channel->id
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception sending WhatsApp notification', [
                'to_number' => $toNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }


    /**
     * Send registration notification
     */
    public function sendRegistrationNotification(User $user, Tenant $tenant, ?\App\Models\Subscription $subscription = null): bool
    {
        if (!$user->whatsapp_number) {
            Log::warning('User has no WhatsApp number, skipping notification', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            return false;
        }

        // Get active banks for payment information
        $banks = Bank::where('is_active', true)
            ->orderBy('name')
            ->get();

        // Build message
        $message = "🎉 *Selamat! Akun Anda Berhasil Dibuat*\n\n";
        $message .= "Halo " . $user->name . ",\n\n";
        $message .= "Terima kasih telah mendaftar di FinWa.\n\n";
        
        $message .= "*📋 Informasi Akun Anda:*\n";
        $message .= "• Nama: " . $user->name . "\n";
        $message .= "• Email: " . $user->email . "\n";
        $message .= "• Nomor WhatsApp: " . $user->whatsapp_number . "\n";
        $message .= "• Organisasi: " . $tenant->name . "\n\n";

        if ($subscription) {
            $message .= "*💰 Informasi Pembayaran:*\n";
            $message .= "• Paket: " . ucfirst($subscription->plan) . "\n";
            $message .= "• Durasi: " . $subscription->duration_months . " bulan\n";
            $message .= "• Total: Rp " . number_format($subscription->price, 0, ',', '.') . "\n\n";
        }

        $isTrial = $subscription && $subscription->price == 0;

        if (!$isTrial && $banks->count() > 0) {
            $message .= "*🏦 Rekening Pembayaran:*\n";
            foreach ($banks as $bank) {
                $message .= "• " . $bank->name . "\n";
                $message .= "  No. Rek: " . $bank->account_number . "\n";
                $message .= "  A/N: " . $bank->account_name . "\n";
                if ($bank->description) {
                    $message .= "  " . $bank->description . "\n";
                }
                $message .= "\n";
            }
        }

        $message .= "*⏳ Status Akun:*\n";
        
        if ($isTrial) {
            $message .= "Akun Anda saat ini dalam status *ACTIVE*.✅\n\n";
            $message .= "━━━━━━━━━━━━━━━━━\n\n";
            $message .= "🚀 *Mulai Sekarang!*\n\n";
            $message .= "Langsung catat keuangan Anda dengan cara:\n";
            $message .= "• Ketik: _\"makan siang 25rb\"_\n";
            $message .= "• Ketik: _\"gaji bulan ini 5jt\"_\n";
            $message .= "• Kirim foto struk belanja 📸\n\n";
            $message .= "💡 Ketik *help* untuk panduan lengkap\n\n";
            $message .= "Selamat menggunakan FinWa! 🎉";
        } else {
            $message .= "Akun Anda saat ini dalam status *PENDING*.\n\n";
            $message .= "Akun akan diaktifkan setelah pembayaran dikonfirmasi oleh admin.\n\n";
            $message .= "Silakan lakukan transfer sesuai nominal di atas ke rekening yang tertera, lalu upload bukti transfer di halaman Subscription.\n\n";
            $message .= "Terima kasih! 🙏";
        }

        return $this->sendNotification($user->whatsapp_number, $message, $user->id);
    }

    /**
     * Send activation notification
     */
    public function sendActivationNotification(User $user, Tenant $tenant, \App\Models\Subscription $subscription): bool
    {
        if (!$user->whatsapp_number) {
            Log::warning('User has no WhatsApp number, skipping activation notification', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            return false;
        }

        // Build message
        $message = "✅ *Akun Anda Telah Diaktifkan!*\n\n";
        $message .= "Halo " . $user->name . ",\n\n";
        $message .= "Selamat! Pembayaran Anda telah dikonfirmasi. 🎉\n\n";
        
        // Duration label with discount info
        $durationLabel = $subscription->duration_months . " bulan";
        $discountLabel = "";
        switch ($subscription->duration_months) {
            case 3:
                $discountLabel = " (Hemat 5%!)";
                break;
            case 6:
                $discountLabel = " (Hemat 10%!)";
                break;
            case 12:
                $discountLabel = " (Hemat 15%! 🔥)";
                break;
        }
        
        $message .= "*📋 Informasi Langganan:*\n";
        $message .= "• Paket: " . ucfirst($subscription->plan) . " ✨\n";
        $message .= "• Durasi: " . $durationLabel . $discountLabel . "\n";
        $message .= "• Total: Rp " . number_format($subscription->price, 0, ',', '.') . "\n";
        $message .= "• Status: Aktif ✅\n";
        $message .= "• Berlaku hingga: " . $subscription->ends_at->format('d M Y') . "\n\n";
        
        $message .= "━━━━━━━━━━━━━━━━━\n\n";
        $message .= "🚀 *Mulai Sekarang!*\n\n";
        $message .= "Catat keuangan Anda dengan mudah:\n";
        $message .= "• Ketik: _\"makan siang 25rb\"_\n";
        $message .= "• Ketik: _\"gaji bulan ini 5jt\"_\n";
        $message .= "• Kirim foto struk belanja 📸\n\n";
        
        $message .= "*📊 Cek Keuangan:*\n";
        $message .= "• Ketik: _\"ringkasan bulan ini\"_\n";
        $message .= "• Ketik: _\"cek cashflow\"_\n\n";
        
        $message .= "💡 Ketik *help* untuk panduan lengkap\n\n";
        $message .= "🔗 Dashboard: " . config('app.url') . "/dashboard\n\n";
        $message .= "Terima kasih telah berlangganan FinWa! 🌟";

        return $this->sendNotification($user->whatsapp_number, $message, $user->id);
    }
}

