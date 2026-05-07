<?php

namespace App\Services\Account;

use App\Models\Balance;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * AccountCommandService - Handles account management commands (reset, delete data)
 */
class AccountCommandService
{
    protected Message $message;

    protected $sendReplyCallback;

    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
    }

    protected function sendReply(string $text): void
    {
        call_user_func($this->sendReplyCallback, $text);
    }

    /**
     * Handle reset account request
     * Allows user to reset all their wallets, transactions, and reminders
     */
    public function handleResetAccount(string $messageText): void
    {
        try {
            // Normalize: convert all unicode/non-breaking spaces to regular space
            // WhatsApp often sends non-breaking spaces (U+00A0) which break str_contains
            $normalizedText = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', trim($messageText));
            $textLower = mb_strtolower($normalizedText, 'UTF-8');

            // Check if user confirmed with specific keyword
            $confirmKeywords = [
                'konfirmasi reset', 'confirm reset',
                'ya reset semua', 'ya reset',
                'lanjut reset', 'reset sekarang',
                'oke reset', 'ok reset',
                'setuju reset', 'lakukan reset',
            ];
            $isConfirmed = false;
            foreach ($confirmKeywords as $confirmKey) {
                if (str_contains($textLower, $confirmKey)) {
                    $isConfirmed = true;
                    break;
                }
            }

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
     * Handle delete all transactions (keeps wallets and reminders)
     */
    public function handleDeleteAllTransactions(string $messageText): void
    {
        try {
            $textLower = strtolower($messageText);

            // Check if user confirmed with specific keyword
            $isConfirmed = str_contains($textLower, 'konfirmasi hapus transaksi') ||
                           str_contains($textLower, 'confirm delete') ||
                           str_contains($textLower, 'ya hapus semua transaksi');

            if (! $isConfirmed) {
                // Show warning and ask for confirmation
                $this->sendReply(
                    "⚠️ *PERINGATAN: Hapus Semua Transaksi*\n\n".
                    "Anda akan menghapus SEMUA transaksi:\n".
                    "• Semua pemasukan\n".
                    "• Semua pengeluaran\n\n".
                    "📝 Rekening/dompet dan pengingat akan tetap ada.\n\n".
                    "🚨 *Tindakan ini TIDAK DAPAT dibatalkan!*\n\n".
                    "Untuk melanjutkan, ketik:\n".
                    "*KONFIRMASI HAPUS TRANSAKSI*\n\n".
                    'Untuk membatalkan, abaikan pesan ini.'
                );

                return;
            }

            // User confirmed - proceed with deletion
            $tenantId = $this->message->tenant_id;

            // Count transactions before deletion
            $transactionCount = Transaction::where('tenant_id', $tenantId)->count();
            $incomeCount = Transaction::where('tenant_id', $tenantId)->where('type', 'income')->count();
            $expenseCount = Transaction::where('tenant_id', $tenantId)->where('type', 'expense')->count();

            // Delete all transactions only
            Transaction::where('tenant_id', $tenantId)->delete();

            // RESET ALL WALLET BALANCES TO 0
            // Crucial to prevent stranded balance (GHOST MONEY)
            Balance::where('tenant_id', $tenantId)->update(['balance' => 0]);

            Log::warning('All transactions deleted via WhatsApp', [
                'message_id' => $this->message->id,
                'tenant_id' => $tenantId,
                'transactions_deleted' => $transactionCount,
                'income_deleted' => $incomeCount,
                'expense_deleted' => $expenseCount,
            ]);

            $this->sendReply(
                "🗑️ *Akun Berhasil Di-Reset!*\n\n".
                "Data yang dihapus:\n".
                "• {$incomeCount} pemasukan\n".
                "• {$expenseCount} pengeluaran\n".
                "• Total: {$transactionCount} transaksi\n\n".
                "✅ Semua transaksi sudah dihapus!\n".
                "✅ Semua saldo dompet di-reset ke 0!\n\n".
                "📝 Rekening dan pengingat Anda masih tersimpan.\n\n".
                "💡 Mulai catat transaksi baru:\n".
                "• _gaji 5jt_\n".
                '• _makan siang 25rb_'
            );

        } catch (\Exception $e) {
            Log::error('Error deleting all transactions', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal menghapus transaksi*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }
}
