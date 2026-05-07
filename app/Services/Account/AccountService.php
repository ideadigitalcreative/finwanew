<?php

namespace App\Services\Account;

use App\Models\Balance;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Transaction;
use App\Services\SubscriptionTrackerService;
use Illuminate\Support\Facades\Log;

/**
 * AccountService - Handles account-level operations
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class AccountService
{
    protected Message $message;

    protected $sendReplyCallback;

    /**
     * Constructor
     *
     * @param  Message  $message  The message being processed
     * @param  callable  $sendReplyCallback  Callback to send reply via WhatsApp
     */
    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
    }

    /**
     * Send reply via callback
     */
    protected function sendReply(string $message): void
    {
        call_user_func($this->sendReplyCallback, $message);
    }

    /**
     * Handle reset account request
     * Allows user to reset all their wallets, transactions, and reminders
     * Requires confirmation with specific keyword to prevent accidents
     *
     * MOVED FROM: ProcessIncomingMessage::handleResetAccount()
     * LINES: 2711-2791
     * MODIFICATION: None (structural move only)
     */
    public function handleResetAccount(string $messageText): void
    {
        try {
            $textLower = strtolower($messageText);

            // Check if user confirmed with specific keyword
            $isConfirmed = str_contains($textLower, 'konfirmasi reset') ||
                           str_contains($textLower, 'confirm reset') ||
                           str_contains($textLower, 'ya reset semua');

            if (! $isConfirmed) {
                // Show warning and ask for confirmation
                $this->sendReply(
                    "⚠️ *PERINGATAN: Reset Data Akun*\n\n".
                    "Anda akan menghapus SEMUA data berikut:\n".
                    "• Semua transaksi (pemasukan & pengeluaran)\n".
                    "• Semua rekening/dompet\n".
                    "• Semua pengingat\n\n".
                    "🚨 *Tindakan ini TIDAK DAPAT dibatalkan!*\n\n".
                    "Untuk melanjutkan, ketik:\n".
                    "*KONFIRMASI RESET*\n\n".
                    'Untuk membatalkan, abaikan pesan ini.'
                );

                return;
            }

            // User confirmed - proceed with reset
            $tenantId = $this->message->tenant_id;

            // Count data before deletion
            $transactionCount = Transaction::where('tenant_id', $tenantId)->count();
            $walletCount = Balance::where('tenant_id', $tenantId)->count();
            $reminderCount = Reminder::where('tenant_id', $tenantId)->count();

            // Delete all transactions
            Transaction::where('tenant_id', $tenantId)->delete();

            // Delete all wallets/balances
            Balance::where('tenant_id', $tenantId)->delete();

            // Delete all reminders
            Reminder::where('tenant_id', $tenantId)->delete();

            Log::warning('Account reset performed via WhatsApp', [
                'message_id' => $this->message->id,
                'tenant_id' => $tenantId,
                'transactions_deleted' => $transactionCount,
                'wallets_deleted' => $walletCount,
                'reminders_deleted' => $reminderCount,
            ]);

            $this->sendReply(
                "🗑️ *Reset Akun Berhasil!*\n\n".
                "Data yang dihapus:\n".
                "• {$transactionCount} transaksi\n".
                "• {$walletCount} rekening/dompet\n".
                "• {$reminderCount} pengingat\n\n".
                "✅ Akun Anda sudah bersih!\n\n".
                "💡 Mulai catat keuangan baru dengan:\n".
                "• _tambah dompet BCA_\n".
                "• _gaji 5jt_\n".
                '• _makan siang 25rb_'
            );

        } catch (\Exception $e) {
            Log::error('Error resetting account', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mereset akun*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle check subscriptions request (cek langganan, subscription saya)
     *
     * MOVED FROM: ProcessIncomingMessage::handleCheckSubscriptions()
     * LINES: 6067-6094
     * MODIFICATION: None (structural move only)
     */
    public function handleCheckSubscriptions(): void
    {
        try {
            $trackerService = new SubscriptionTrackerService($this->message->tenant_id);
            $message = $trackerService->generateSummaryMessage();

            $this->sendReply($message);

            Log::info('Subscription tracker viewed', [
                'message_id' => $this->message->id,
                'tenant_id' => $this->message->tenant_id,
            ]);

        } catch (\Exception $e) {
            Log::error('Error viewing subscriptions', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal memuat langganan*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
            );
        }
    }
}
