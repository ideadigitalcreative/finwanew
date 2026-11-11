<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;
use App\Services\MemberNotificationService;
use Illuminate\Support\Facades\Log;

class SendTransactionNotificationToGroupMembers
{
    protected MemberNotificationService $notificationService;

    public function __construct(MemberNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function handle(TransactionCreated $event): void
    {
        $transaction = $event->transaction;

        // Gunakan sender dari event (dashboard) atau resolve dari transaksi (WhatsApp)
        $sender = $event->sender ?? $this->resolveSender($transaction);
        if (! $sender) {
            Log::info('Transaction notification skipped: sender not resolved', [
                'transaction_id' => $transaction->id,
                'user_whatsapp_number_id' => $transaction->user_whatsapp_number_id,
                'tenant_id' => $transaction->tenant_id,
            ]);
            return;
        }

        Log::info('Transaction notification: sender resolved', [
            'transaction_id' => $transaction->id,
            'sender_id' => $sender->id,
            'sender_name' => $sender->name,
        ]);

        $this->notificationService->notifyTransaction($transaction, $sender);
    }

    /**
     * Resolve siapa yang mencatat transaksi dari data transaksi.
     */
    protected function resolveSender(Transaction $transaction): ?User
    {
        // 1. Via UserWhatsAppNumber ID (langsung dari transaksi)
        if ($transaction->user_whatsapp_number_id) {
            $mapping = UserWhatsAppNumber::find($transaction->user_whatsapp_number_id);
            if ($mapping?->user) {
                return $mapping->user;
            }
        }

        // 2. Via Message → sender_id (LID atau nomor telepon)
        if ($transaction->message_id) {
            $message = \App\Models\Message::find($transaction->message_id);
            if ($message?->sender_id) {
                $senderId = $message->sender_id;

                // Coba cari via LID mapping
                $rawDigits = preg_replace('/[^0-9@]/', '', $senderId);
                $cleanDigits = str_replace('@lid', '', $rawDigits);
                $lidMapping = UserLidMapping::findByLid($cleanDigits);
                if ($lidMapping?->user) {
                    return $lidMapping->user;
                }

                // Coba cari via WhatsAppUserMappingService
                try {
                    $user = app(\App\Services\WhatsAppUserMappingService::class)
                        ->findUserByWhatsAppNumber($senderId);
                    if ($user) {
                        return $user;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Transaction notification: failed to resolve sender via WhatsAppUserMappingService', [
                        'sender_id' => $senderId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return null;
    }
}
