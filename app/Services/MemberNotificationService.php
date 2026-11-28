<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserTelegramMapping;
use App\Models\UserWhatsAppNumber;
use Illuminate\Support\Facades\Log;

class MemberNotificationService
{
    protected WhatsAppService $whatsappService;
    protected TelegramService $telegramService;

    public function __construct(
        WhatsAppService $whatsappService,
        TelegramService $telegramService,
    ) {
        $this->whatsappService = $whatsappService;
        $this->telegramService = $telegramService;
    }

    /**
     * Kirim notifikasi transaksi baru ke nomor anggota lain dalam satu tenant.
     *
     * Logika: cari semua nomor WA aktif (bukan LID) di tenant yang sama,
     * kecuali nomor pengirim. Mendukung skenario 1 user dengan banyak nomor
     * (mis. Suami, Istri, Anak) — notifikasi dikirim ke nomor-nomor lain.
     */
    public function notifyTransaction(Transaction $transaction, User $sender): void
    {
        // SEMENTARA: notifikasi broadcast ke anggota lain dinonaktifkan
        // Aktifkan kembali jika sudah ada fitur exclude sender dari penerimaan notif
        Log::info('Transaction broadcast notification temporarily disabled', [
            'transaction_id' => $transaction->id,
            'sender_id' => $sender->id,
        ]);
        return;

        $tenant = $transaction->tenant;
        if (! $tenant) {
            Log::warning('Transaction notification skipped: tenant missing', [
                'transaction_id' => $transaction->id,
            ]);
            return;
        }

        // Cari nomor WA pengirim untuk dikecualikan
        $senderNumbers = $this->getSenderPhoneNumbers($sender, $tenant->id, $transaction);

        // Cari semua nomor WA aktif lain di tenant ini (bukan LID pseudo phone)
        // Handle is_lid: false, NULL, atau empty string
        $recipientNumbers = UserWhatsAppNumber::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_lid', false)
                    ->orWhereNull('is_lid')
                    ->orWhere('is_lid', '');
            })
            ->whereNotIn('whatsapp_number', $senderNumbers)
            ->get();

        // Fallback: jika tidak ada di user_whatsapp_numbers, cari dari users.whatsapp_number
        if ($recipientNumbers->isEmpty()) {
            $otherUsers = $tenant->users()
                ->where('users.id', '!=', $sender->id)
                ->whereNotNull('whatsapp_number')
                ->get();

            foreach ($otherUsers as $otherUser) {
                if (! in_array($otherUser->whatsapp_number, $senderNumbers)) {
                    $recipientNumbers->push((object) [
                        'whatsapp_number' => $otherUser->whatsapp_number,
                        'user_id' => $otherUser->id,
                        'name' => $otherUser->name,
                    ]);
                }
            }
        }

        Log::info('Transaction notification preparing', [
            'transaction_id' => $transaction->id,
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'sender_id' => $sender->id,
            'sender_name' => $sender->name,
            'sender_numbers' => $senderNumbers,
            'recipients_count' => $recipientNumbers->count(),
            'recipient_numbers' => $recipientNumbers->pluck('whatsapp_number')->toArray(),
        ]);

        if ($recipientNumbers->isEmpty()) {
            Log::info('Transaction notification skipped: no other WhatsApp numbers in tenant', [
                'transaction_id' => $transaction->id,
                'tenant_id' => $tenant->id,
            ]);
            return;
        }

        // Cari shared WhatsApp channel (admin channel) sekali saja
        $sharedChannel = $this->resolveSharedChannel($tenant->id);

        $message = $this->buildNotificationMessage($transaction, $sender, $tenant->name);

        foreach ($recipientNumbers as $index => $recipient) {
            // Jeda 2 detik antar notifikasi untuk hindari spam
            if ($index > 0) {
                sleep(2);
            }

            $recipientNumber = $recipient->whatsapp_number;
            $recipientName = $recipient->name ?? $recipientNumber;

            try {
                $sent = $this->sendToWhatsAppNumber($recipientNumber, $tenant->id, $message, $sharedChannel);

                if ($sent) {
                    Log::info('Transaction notification sent', [
                        'transaction_id' => $transaction->id,
                        'recipient' => $recipientName,
                        'recipient_number' => $recipientNumber,
                        'tenant_id' => $tenant->id,
                    ]);
                } else {
                    Log::info('Transaction notification failed', [
                        'transaction_id' => $transaction->id,
                        'recipient' => $recipientName,
                        'recipient_number' => $recipientNumber,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to send transaction notification', [
                    'transaction_id' => $transaction->id,
                    'recipient' => $recipientName,
                    'recipient_number' => $recipientNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dapatkan nomor telepon pengirim (untuk dikecualikan dari daftar penerima).
     * Hanya eksklusi nomor yang BENAR-BENAR mengirim pesan, bukan semua nomor milik user.
     */
    protected function getSenderPhoneNumbers(User $sender, int $tenantId, Transaction $transaction): array
    {
        $numbers = [];

        // 1. Dari message sender_id — ini nomor yang BENAR-BENAR mengirim pesan
        //    (bisa LID pseudo phone atau nomor telepon biasa)
        if ($transaction->message_id) {
            $message = \App\Models\Message::find($transaction->message_id);
            if ($message?->sender_id) {
                $rawDigits = preg_replace('/[^0-9]/', '', str_replace(['@lid', '@c.us', '@g.us'], '', $message->sender_id));
                if (! empty($rawDigits)) {
                    $numbers[] = $rawDigits;

                    // Jika ini LID (panjang > 13 digit), cari nomor asli
                    if (strlen($rawDigits) > 13) {
                        // Cari via UserLidMapping
                        $lidMapping = \App\Models\UserLidMapping::findByLid($rawDigits);
                        if ($lidMapping && !empty($lidMapping->phone_number)) {
                            $lidPhone = preg_replace('/[^0-9]/', '', $lidMapping->phone_number);
                            if (!empty($lidPhone) && strlen($lidPhone) <= 13) {
                                $numbers[] = $lidPhone;
                            }
                        }

                        // Cari nomor asli dari UserWhatsAppNumber yang terkait LID ini
                        $lidWaNumber = UserWhatsAppNumber::where('whatsapp_number', $rawDigits)
                            ->where('is_active', true)
                            ->first();
                        if ($lidWaNumber) {
                            $realNumbers = UserWhatsAppNumber::where('user_id', $lidWaNumber->user_id)
                                ->where('tenant_id', $tenantId)
                                ->where('is_active', true)
                                ->where(function ($q) {
                                    $q->where('is_lid', false)
                                        ->orWhereNull('is_lid')
                                        ->orWhere('is_lid', '');
                                })
                                ->pluck('whatsapp_number')
                                ->toArray();
                            $numbers = array_merge($numbers, $realNumbers);
                        }
                    }
                }
            }
        }

        // 2. Dari User->whatsapp_number (nomor utama user yang terdaftar)
        if (! empty($sender->whatsapp_number)) {
            $numbers[] = $sender->whatsapp_number;
        }

        return array_values(array_unique(array_filter($numbers)));
    }

    /**
     * Cari shared WhatsApp channel (admin channel) yang bisa digunakan untuk mengirim.
     */
    protected function resolveSharedChannel(int $tenantId): ?Channel
    {
        // 1. Cari shared channel di tenant ini
        $channel = Channel::where('tenant_id', $tenantId)
            ->where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('is_shared_channel', true)
            ->first();

        if ($channel) {
            return $channel;
        }

        // 2. Cari channel WhatsApp aktif apapun di tenant ini
        $channel = Channel::where('tenant_id', $tenantId)
            ->where('type', 'whatsapp')
            ->where('is_active', true)
            ->first();

        if ($channel) {
            return $channel;
        }

        // 3. Fallback: gunakan channel WhatsApp sistem (tenant_id=1)
        $channel = Channel::where('tenant_id', 1)
            ->where('type', 'whatsapp')
            ->where('is_active', true)
            ->first();

        return $channel;
    }

    /**
     * Kirim pesan ke nomor WhatsApp tertentu menggunakan shared channel.
     */
    protected function sendToWhatsAppNumber(string $toNumber, int $tenantId, string $message, ?Channel $sharedChannel): bool
    {
        if (! $sharedChannel) {
            Log::warning('Transaction notification skipped: no shared WhatsApp channel', [
                'to' => $toNumber,
                'tenant_id' => $tenantId,
            ]);
            return false;
        }

        // Dapatkan session ID dari shared channel
        $sessionId = $sharedChannel->session_id
            ?? $sharedChannel->config['session_id']
            ?? $this->whatsappService->generateSessionId($sharedChannel->tenant_id, $sharedChannel->channel_account);

        // Cek session status
        try {
            $status = $this->whatsappService->getSessionStatus($sessionId);
            if (! (($status['success'] ?? false) && ($status['data']['status'] ?? '') === 'connected')) {
                Log::warning('Transaction notification skipped: WhatsApp session not connected', [
                    'to' => $toNumber,
                    'session_id' => $sessionId,
                    'status' => $status['data']['status'] ?? $status['error'] ?? 'unknown',
                ]);
                return false;
            }
        } catch (\Throwable $e) {
            Log::error('Transaction notification WhatsApp session status failed', [
                'to' => $toNumber,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        // Kirim pesan
        try {
            $this->whatsappService->sendMessage(
                $sessionId,
                $toNumber,
                $message,
                'text',
            );
            Log::info('Transaction notification sent via WhatsApp', [
                'session_id' => $sessionId,
                'to' => $toNumber,
                'tenant_id' => $tenantId,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Transaction notification WhatsApp send failed', [
                'session_id' => $sessionId,
                'to' => $toNumber,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Bangun pesan notifikasi (singkat dan jelas).
     */
    protected function buildNotificationMessage(Transaction $transaction, User $sender, string $tenantName): string
    {
        $typeLabel = $transaction->type === 'income' ? 'Pemasukan' : 'Pengeluaran';
        $emoji = $transaction->type === 'income' ? '📈' : '📉';
        $amount = number_format((float) $transaction->amount, 0, ',', '.');
        $categoryName = $transaction->category?->name ?? '-';

        $lines = [
            '*Transaksi Baru*',
            '',
            "{$emoji} {$typeLabel} Rp {$amount} — {$categoryName}",
        ];

        if (! empty($transaction->description)) {
            $lines[2] .= " ({$transaction->description})";
        }

        $lines[] = "👤 Oleh: {$sender->name}";

        return implode("\n", $lines);
    }
}
