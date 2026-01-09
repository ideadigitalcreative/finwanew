<?php

namespace App\Services\Wallet;

use App\Models\Balance;
use App\Models\Category;
use App\Models\Message;
use App\Models\Transaction;
use App\Services\BalanceService;
use Illuminate\Support\Facades\Log;

/**
 * WalletCommandService - Handles wallet/balance related commands
 * 
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 * 
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 * 
 * NOTE: Some methods call other methods that are in ProcessIncomingMessage,
 * these need to be injected as callbacks or the orchestrator needs to provide them.
 */
class WalletCommandService
{
    protected Message $message;
    protected $sendReplyCallback;
    protected $extractAmountCallback;
    protected $createTransactionCallback;
    protected $determineCategoryCallback;

    /**
     * Constructor
     * 
     * @param Message $message The message being processed
     * @param callable $sendReplyCallback Callback to send reply via WhatsApp
     * @param callable $extractAmountCallback Callback to extract amount from text
     * @param callable $createTransactionCallback Callback to create transaction
     * @param callable $determineCategoryCallback Callback to determine category from description
     */
    public function __construct(
        Message $message,
        callable $sendReplyCallback,
        callable $extractAmountCallback = null,
        callable $createTransactionCallback = null,
        callable $determineCategoryCallback = null
    ) {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
        $this->extractAmountCallback = $extractAmountCallback;
        $this->createTransactionCallback = $createTransactionCallback;
        $this->determineCategoryCallback = $determineCategoryCallback;
    }

    /**
     * Send reply via callback
     */
    protected function sendReply(string $message): void
    {
        call_user_func($this->sendReplyCallback, $message);
    }

    /**
     * Extract amount from text via callback
     */
    protected function extractAmountFromText(string $text): ?float
    {
        if ($this->extractAmountCallback) {
            return call_user_func($this->extractAmountCallback, $text);
        }
        return null;
    }

    /**
     * Create transaction via callback
     */
    protected function createTransaction(array $txData): ?Transaction
    {
        if ($this->createTransactionCallback) {
            return call_user_func($this->createTransactionCallback, $txData);
        }
        return null;
    }

    /**
     * Determine category from description via callback
     */
    protected function determineCategoryFromDescription(string $description): string
    {
        if ($this->determineCategoryCallback) {
            return call_user_func($this->determineCategoryCallback, $description);
        }
        return 'pengeluaran_lainnya';
    }

    /**
     * Handle set/edit wallet balance
     * e.g., "set saldo O menjadi 49.000", "ubah saldo BCA jadi 100rb"
     * 
     * MOVED FROM: ProcessIncomingMessage::handleSetWalletBalance()
     * LINES: 3201-3340
     * MODIFICATION: None (structural move only)
     */
    public function handleSetWalletBalance(string $messageText): void
    {
        try {
            // SPECIAL CASE: "Update saldo jadi 5juta" - no wallet name, just amount
            // Use last created wallet or default wallet
            if (preg_match('/^(?:update|set|ubah|ganti)\s+saldo\s+(?:jadi|menjadi|ke|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)$/i', trim($messageText), $shortMatch)) {
                $amountStr = trim($shortMatch[1]);
                $newAmount = $this->extractAmountFromText($amountStr);
                
                if ($newAmount !== null) {
                    // Find last created or default wallet
                    $balance = Balance::where('tenant_id', $this->message->tenant_id)
                        ->where('is_active', true)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    
                    if (!$balance) {
                        $balance = Balance::where('tenant_id', $this->message->tenant_id)
                            ->where('is_default', true)
                            ->first();
                    }
                    
                    if ($balance) {
                        // Update the balance
                        $oldAmount = $balance->balance ?? 0;
                        $difference = $newAmount - $oldAmount;
                        $balance->balance = $newAmount;
                        $balance->save();
                        
                        $oldFormatted = number_format($oldAmount, 0, ',', '.');
                        $newFormatted = number_format($newAmount, 0, ',', '.');
                        $diffFormatted = number_format(abs($difference), 0, ',', '.');
                        $diffSign = $difference >= 0 ? '+' : '-';
                        
                        $this->sendReply(
                            "✏️ *Saldo Berhasil Diubah!* ✅\n\n" .
                            "👛 Dompet: *{$balance->account_name}*\n\n" .
                            "📊 Perubahan Saldo:\n" .
                            "   Sebelum: Rp {$oldFormatted}\n" .
                            "   Sesudah: *Rp {$newFormatted}*\n" .
                            "   Selisih: {$diffSign}Rp {$diffFormatted}\n\n" .
                            "📅 Diubah: " . now()->translatedFormat('d F Y H:i')
                        );
                        return;
                    }
                }
                
                // No wallet found, show available wallets
                $availableWallets = Balance::where('tenant_id', $this->message->tenant_id)
                    ->pluck('account_name')
                    ->toArray();
                
                $walletList = empty($availableWallets) 
                    ? 'Belum ada dompet. Buat dengan: _tambah dompet BCA_'
                    : implode(', ', $availableWallets);
                
                $this->sendReply(
                    "⚠️ *Dompet mana yang ingin diupdate?*\n\n" .
                    "Dompet tersedia:\n{$walletList}\n\n" .
                    "Contoh: _update saldo BCA jadi 5jt_"
                );
                return;
            }
            
            // Patterns to extract wallet name and amount
            $patterns = [
                '/(?:set|atur|ubah|edit|ganti)\s+saldo\s+([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+([a-zA-Z0-9\s]+?)\s*=\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/(?:update|koreksi)\s+saldo\s+([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+([a-zA-Z0-9\s]+?)\s+(?:sekarang|jadi|menjadi)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                // Simple format: "update saldo BCA 500rb" or "set saldo Dana 1jt"
                '/(?:update|set|atur|ubah|edit|ganti)\s+saldo\s+([a-zA-Z0-9]+)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                // NEW: Direct format: "saldo dompet utama 1 juta", "saldo BCA 500rb"
                // Pattern: saldo [nama dompet] [nominal] - matches when there's a wallet name followed by amount
                '/^saldo\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?)/i',
            ];
            
            $walletName = null;
            $amountStr = null;
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $messageText, $matches)) {
                    $walletName = trim($matches[1]);
                    $amountStr = trim($matches[2]);
                    break;
                }
            }
            
            if (!$walletName) {
                $this->sendReply(
                    "⚠️ *Format tidak dikenali*\n\n" .
                    "Contoh format yang benar:\n" .
                    "• _set saldo BCA menjadi 100rb_\n" .
                    "• _ubah saldo Gopay jadi 50.000_\n" .
                    "• _saldo Dana = 1jt_\n" .
                    "• _ganti saldo O ke 49.000_\n" .
                    "• _koreksi saldo Cash 25rb_"
                );
                return;
            }
            
            // Extract amount
            $newAmount = $this->extractAmountFromText($amountStr ?? '');
            
            if ($newAmount === null) {
                $this->sendReply(
                    "⚠️ *Nominal tidak terdeteksi*\n\n" .
                    "Pastikan format nominal benar:\n" .
                    "• 100rb, 100.000, 100k\n" .
                    "• 1jt, 1.000.000\n\n" .
                    "Contoh: _set saldo BCA menjadi 100rb_"
                );
                return;
            }
            
            // Find the wallet - PRIORITY: Exact match first, then partial match (prefer shortest name)
            $balance = Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->whereRaw('LOWER(account_name) = ?', [strtolower($walletName)])
                ->first();
            
            // If no exact match, try partial match but prefer shortest name
            if (!$balance) {
                $balance = Balance::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->whereRaw('LOWER(account_name) LIKE ?', ['%' . strtolower($walletName) . '%'])
                    ->orderByRaw('LENGTH(account_name) ASC') // Prefer shorter names (more likely exact match)
                    ->first();
            }
            
            if (!$balance) {
                // List available wallets
                $availableWallets = Balance::where('tenant_id', $this->message->tenant_id)
                    ->pluck('account_name')
                    ->toArray();
                
                $walletList = empty($availableWallets) 
                    ? 'Belum ada dompet. Buat dengan: _tambah dompet BCA_'
                    : implode(', ', $availableWallets);
                
                $this->sendReply(
                    "⚠️ *Dompet '{$walletName}' tidak ditemukan*\n\n" .
                    "Dompet tersedia:\n{$walletList}\n\n" .
                    "Atau buat dompet baru: _tambah dompet {$walletName}_"
                );
                return;
            }
            
            // Store old balance for comparison
            $oldAmount = $balance->balance ?? 0;
            $difference = $newAmount - $oldAmount;
            
            // Update the balance directly (no transaction created)
            // This is a manual balance correction, not a transaction
            $balance->balance = $newAmount;
            $balance->save();
            
            // Format for display
            $oldFormatted = number_format($oldAmount, 0, ',', '.');
            $newFormatted = number_format($newAmount, 0, ',', '.');
            $diffFormatted = number_format(abs($difference), 0, ',', '.');
            $diffSign = $difference >= 0 ? '+' : '-';
            
            // NOTE: We do NOT create an adjustment transaction here
            // because TransactionService.createTransaction would update the balance again,
            // causing a double-update bug. Setting saldo manually is a direct correction.
            
            Log::info('Wallet balance updated', [
                'message_id' => $this->message->id,
                'balance_id' => $balance->id,
                'wallet_name' => $balance->account_name,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'difference' => $difference
            ]);
            
            // Send confirmation
            $this->sendReply(
                "✏️ *Saldo Berhasil Diubah!* ✅\n\n" .
                "👛 Dompet: *{$balance->account_name}*\n\n" .
                "📊 Perubahan Saldo:\n" .
                "   Sebelum: Rp {$oldFormatted}\n" .
                "   Sesudah: *Rp {$newFormatted}*\n" .
                "   Selisih: {$diffSign}Rp {$diffFormatted}\n\n" .
                "📅 Diubah: " . now()->translatedFormat('d F Y H:i')
            );
            
        } catch (\Exception $e) {
            Log::error('Error setting wallet balance', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal mengubah saldo*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
    
    /**
     * Handle multiple wallet balance updates from a single multi-line message
     * e.g., "Update saldo BRI 1.598.059\nUpdate saldo BJB 5.165.856\nUpdate saldo BSI 31.163.373"
     * 
     * @param string $messageText The full message text containing multiple update commands
     * @return void
     */
    public function handleMultipleSetWalletBalance(string $messageText): void
    {
        try {
            // Split message by newlines
            $lines = preg_split('/[\r\n]+/', $messageText);
            $lines = array_filter($lines, fn($line) => !empty(trim($line)));
            
            // Patterns to extract wallet name and amount
            $patterns = [
                '/(?:set|atur|ubah|edit|ganti)\s+saldo\s+([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+([a-zA-Z0-9\s]+?)\s*=\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/(?:update|koreksi)\s+saldo\s+([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+([a-zA-Z0-9\s]+?)\s+(?:sekarang|jadi|menjadi)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                // Simple format: "update saldo BCA 500rb" or "set saldo Dana 1jt"
                '/(?:update|set|atur|ubah|edit|ganti)\s+saldo\s+([a-zA-Z0-9]+)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                // NEW: Direct format: "saldo dompet utama 1 juta", "saldo BCA 500rb"
                '/^saldo\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?)/i',
            ];
            
            $updates = [];
            $errors = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $walletName = null;
                $amountStr = null;
                
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        $walletName = trim($matches[1]);
                        $amountStr = trim($matches[2]);
                        break;
                    }
                }
                
                if (!$walletName || !$amountStr) {
                    continue; // Skip lines that don't match the pattern
                }
                
                // Extract amount
                $newAmount = $this->extractAmountFromText($amountStr);
                
                if ($newAmount === null) {
                    $errors[] = "⚠️ {$walletName}: Nominal tidak terdeteksi";
                    continue;
                }
                
                // Find the wallet - PRIORITY: Exact match first, then partial match
                $balance = Balance::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->whereRaw('LOWER(account_name) = ?', [strtolower($walletName)])
                    ->first();
                
                // If no exact match, try partial match but prefer shortest name
                if (!$balance) {
                    $balance = Balance::where('tenant_id', $this->message->tenant_id)
                        ->where('is_active', true)
                        ->whereRaw('LOWER(account_name) LIKE ?', ['%' . strtolower($walletName) . '%'])
                        ->orderByRaw('LENGTH(account_name) ASC')
                        ->first();
                }
                
                if (!$balance) {
                    $errors[] = "⚠️ {$walletName}: Dompet tidak ditemukan";
                    continue;
                }
                
                // Store old balance for comparison
                $oldAmount = $balance->balance ?? 0;
                $difference = $newAmount - $oldAmount;
                
                // Update the balance
                $balance->balance = $newAmount;
                $balance->save();
                
                // Format for display
                $oldFormatted = number_format($oldAmount, 0, ',', '.');
                $newFormatted = number_format($newAmount, 0, ',', '.');
                $diffFormatted = number_format(abs($difference), 0, ',', '.');
                $diffSign = $difference >= 0 ? '+' : '-';
                
                $updates[] = [
                    'wallet_name' => $balance->account_name,
                    'old_amount' => $oldAmount,
                    'new_amount' => $newAmount,
                    'difference' => $difference,
                    'old_formatted' => $oldFormatted,
                    'new_formatted' => $newFormatted,
                    'diff_formatted' => $diffFormatted,
                    'diff_sign' => $diffSign,
                ];
                
                Log::info('Wallet balance updated (batch)', [
                    'message_id' => $this->message->id,
                    'balance_id' => $balance->id,
                    'wallet_name' => $balance->account_name,
                    'old_amount' => $oldAmount,
                    'new_amount' => $newAmount,
                    'difference' => $difference
                ]);
            }
            
            // Build response
            if (empty($updates) && empty($errors)) {
                $this->sendReply(
                    "⚠️ *Format tidak dikenali*\n\n" .
                    "Contoh format yang benar:\n" .
                    "• _update saldo BCA 100rb_\n" .
                    "• _set saldo Gopay menjadi 50.000_\n\n" .
                    "Untuk update banyak dompet sekaligus:\n" .
                    "• _update saldo BCA 100rb_\n" .
                    "• _update saldo Dana 50rb_\n" .
                    "• _update saldo Gopay 200rb_"
                );
                return;
            }
            
            $reply = "✏️ *Saldo Berhasil Diubah!* ✅\n\n";
            
            foreach ($updates as $update) {
                $reply .= "👛 *{$update['wallet_name']}*\n";
                $reply .= "   📊 Rp {$update['old_formatted']} → *Rp {$update['new_formatted']}*\n";
                $reply .= "   Selisih: {$update['diff_sign']}Rp {$update['diff_formatted']}\n\n";
            }
            
            if (!empty($errors)) {
                $reply .= "━━━━━━━━━━━━━━━\n";
                $reply .= "*Gagal diproses:*\n";
                foreach ($errors as $error) {
                    $reply .= "{$error}\n";
                }
                $reply .= "\n";
            }
            
            $reply .= "📅 Diubah: " . now()->translatedFormat('d F Y H:i');
            
            $this->sendReply($reply);
            
        } catch (\Exception $e) {
            Log::error('Error setting multiple wallet balances', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal mengubah saldo*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
    
    /**
     * Handle transfer to specific wallet
     * e.g., "dapet tf 25rb ke BCA", "terima transfer 100rb ke gopay"
     * 
     * MOVED FROM: ProcessIncomingMessage::handleTransferToWallet()
     * LINES: 2872-2991
     * MODIFICATION: None (structural move only)
     */
    public function handleTransferToWallet(string $messageText): void
    {
        try {
            $textLower = strtolower($messageText);
            
            // Extract amount
            $amount = $this->extractAmountFromText($messageText);
            
            if (!$amount || $amount <= 0) {
                $this->sendReply(
                    "⚠️ *Nominal tidak terdeteksi*\n\n" .
                    "Contoh format yang benar:\n" .
                    "• _dapet tf 25rb ke BCA_\n" .
                    "• _terima transfer 100.000 ke Gopay_\n" .
                    "• _dapat kiriman 50k ke Dana_"
                );
                return;
            }
            
            // Extract wallet name
            $walletName = null;
            // Pattern 1: Standard transfer (di/ke [Wallet] at end)
            if (preg_match('/(?:ke|di)\s+([a-zA-Z0-9\s]+)$/i', $messageText, $matches)) {
                $walletName = trim($matches[1]);
            }
            // Pattern 2: Top Up (saldo [Wallet] [Amount])
            elseif (preg_match('/(?:isi|tambah|top\s*up)\s+saldo\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+?)\s+[\d\.,]/i', $messageText, $matches)) {
                 $walletName = trim($matches[1]);
            }
            
            if (!$walletName) {
                $this->sendReply(
                    "⚠️ *Nama dompet tidak terdeteksi*\n\n" .
                    "Contoh format yang benar:\n" .
                    "• _dapet tf 25rb ke BCA_\n" .
                    "• _tambah saldo Dana 50.000_\n" .
                    "• _isi saldo Gopay 100rb_"
                );
                return;
            }
            
            // Find or create the wallet
            $balanceService = app(BalanceService::class);
            $accountType = $balanceService->determineAccountType($walletName);
            $balance = $balanceService->findOrCreateBalance(
                $this->message->tenant_id,
                $walletName,
                $accountType
            );
            
            if (!$balance) {
                $this->sendReply(
                    "⚠️ *Gagal menemukan/membuat dompet*\n\n" .
                    "Tidak dapat membuat dompet '{$walletName}'. Silakan coba lagi."
                );
                return;
            }
            
            // Determine description
            $description = "Transfer masuk";
            if (preg_match('/(?:dari|from)\s+([a-zA-Z\s]+)/i', $messageText, $fromMatch)) {
                $description = "Transfer dari " . trim($fromMatch[1]);
            } elseif (str_contains($textLower, 'tambah saldo') || str_contains($textLower, 'isi saldo') || str_contains($textLower, 'top up')) {
                $description = "Top Up Saldo";
            }
            
            // Create the income transaction
            $txData = [
                'type' => 'income',
                'amount' => $amount,
                'category_type' => 'pendapatan_transfer',
                'description' => $description,
                'transaction_date' => now()->toDateString(),
                'account_name' => $walletName,
                'confidence_score' => 0.95
            ];
            
            $transaction = $this->createTransaction($txData);
            
            if (!$transaction) {
                $this->sendReply(
                    "⚠️ *Gagal mencatat transfer*\n\n" .
                    "Terjadi kesalahan. Silakan coba lagi."
                );
                return;
            }
            
            // Get updated balance
            $balance->refresh();
            $currentBalance = number_format($balance->balance ?? 0, 0, ',', '.');
            $amountFormatted = number_format($amount, 0, ',', '.');
            
            // Send success confirmation
            $this->sendReply(
                "💵 *Transfer Masuk Tercatat!* ✅\n\n" .
                "💰 Nominal: *Rp {$amountFormatted}*\n" .
                "👛 Dompet: *{$balance->account_name}*\n" .
                "📝 Keterangan: {$description}\n" .
                "📅 Tanggal: " . now()->translatedFormat('d F Y') . "\n\n" .
                "━━━━━━━━━━━━━━━\n" .
                "💳 Saldo {$balance->account_name}: *Rp {$currentBalance}*"
            );
            
            Log::info('Transfer to wallet recorded successfully', [
                'message_id' => $this->message->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'wallet' => $walletName,
                'balance_id' => $balance->id
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error handling transfer to wallet', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal mencatat transfer*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
    
    /**
     * Handle expense from specific wallet
     * e.g., "Pengeluaran dompet kas kepri 98k beli mata kunci sok 1 set"
     *       "keluar dari dompet BCA 50rb beli kopi"
     *       "bayar dari gopay 25rb grab"
     * 
     * MOVED FROM: ProcessIncomingMessage::handleExpenseFromWallet()
     * LINES: 2993-3143
     * MODIFICATION: None (structural move only)
     */
    public function handleExpenseFromWallet(string $messageText): void
    {
        try {
            // Extract wallet name, amount, and description using patterns
            $walletName = null;
            $amount = null;
            $description = null;
            
            $patterns = [
                // Pattern 1: "Pengeluaran dompet [nama] [nominal] [deskripsi]"
                '/(?:pengeluaran|keluar(?:an)?)\s+(?:dari\s+)?dompet\s+([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(.+)/i',
                // Pattern 2: "keluar dari dompet [nama] [nominal] [deskripsi]"
                '/keluar\s+dari\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(.+)/i',
                // Pattern 3: "bayar dari [nama] [nominal] [deskripsi]"
                '/bayar\s+dari\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(.+)/i',
                // Pattern 4: "dompet [nama] [nominal] [deskripsi]"
                '/^dompet\s+([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(.+)/i',
                // Pattern 5: "dari [nama] [nominal] untuk [deskripsi]"
                '/dari\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(?:untuk|buat|beli)\s+(.+)/i',
                // Pattern 6: "Pengeluaran dompet [nama] . harga [nominal] . Keterangan [deskripsi]"
                '/(?:pengeluaran|keluar(?:an)?)\s+(?:dari\s+)?dompet\s+([a-zA-Z0-9\s]+?)\s*\.\s*(?:harga|nominal)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s*\.\s*(?:keterangan|ket|desc)?\s*(.+)/i',
                // Pattern 7: "Pengeluaran dompet [nama] . [nominal] . [deskripsi]" (without keywords)
                '/(?:pengeluaran|keluar(?:an)?)\s+(?:dari\s+)?dompet\s+([a-zA-Z0-9\s]+?)\s*\.\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s*\.\s*(.+)/i',
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $messageText, $matches)) {
                    $walletName = trim($matches[1]);
                    $amountStr = trim($matches[2]);
                    $description = trim($matches[3]);
                    break;
                }
            }
            
            if (!$walletName || !isset($amountStr)) {
                $this->sendReply(
                    "⚠️ *Format tidak dikenali*\n\n" .
                    "Contoh format yang benar:\n" .
                    "• _Pengeluaran dompet kas kepri 98k beli mata kunci_\n" .
                    "• _keluar dari BCA 50rb beli kopi_\n" .
                    "• _bayar dari gopay 25rb grab_\n" .
                    "• _dompet dana 100rb belanja_"
                );
                return;
            }
            
            // Extract amount
            $amount = $this->extractAmountFromText($amountStr);
            
            if (!$amount || $amount <= 0) {
                $this->sendReply(
                    "⚠️ *Nominal tidak terdeteksi*\n\n" .
                    "Pastikan format nominal benar:\n" .
                    "• 98k, 98rb, 98.000\n" .
                    "• 1jt, 1.000.000\n\n" .
                    "Contoh: _Pengeluaran dompet kas kepri 98rb beli mata kunci_"
                );
                return;
            }
            
            // Find or create the wallet
            $balanceService = app(BalanceService::class);
            $accountType = $balanceService->determineAccountType($walletName);
            $balance = $balanceService->findOrCreateBalance(
                $this->message->tenant_id,
                $walletName,
                $accountType
            );
            
            if (!$balance) {
                $this->sendReply(
                    "⚠️ *Gagal menemukan/membuat dompet*\n\n" .
                    "Tidak dapat membuat dompet '{$walletName}'. Silakan coba lagi."
                );
                return;
            }
            
            // Determine category from description
            $categoryType = $this->determineCategoryFromDescription($description);
            
            // Create the expense transaction
            $txData = [
                'type' => 'expense',
                'amount' => $amount,
                'category_type' => $categoryType,
                'description' => $description,
                'transaction_date' => now()->toDateString(),
                'account_name' => $walletName,
                'confidence_score' => 0.95
            ];
            
            $transaction = $this->createTransaction($txData);
            
            if (!$transaction) {
                $this->sendReply(
                    "⚠️ *Gagal mencatat pengeluaran*\n\n" .
                    "Terjadi kesalahan. Silakan coba lagi."
                );
                return;
            }
            
            // Get updated balance
            $balance->refresh();
            $currentBalance = number_format($balance->balance ?? 0, 0, ',', '.');
            $amountFormatted = number_format($amount, 0, ',', '.');
            
            // Get category name
            $category = Category::where('tenant_id', $this->message->tenant_id)
                ->where('type', $categoryType)
                ->first();
            $categoryName = $category ? $category->name : 'Lainnya';
            
            // Send success confirmation
            $this->sendReply(
                "💸 *Pengeluaran Tercatat!* ✅\n\n" .
                "💰 Nominal: *Rp {$amountFormatted}*\n" .
                "👛 Dompet: *{$balance->account_name}*\n" .
                "📁 Kategori: {$categoryName}\n" .
                "📝 Keterangan: {$description}\n" .
                "📅 Tanggal: " . now()->translatedFormat('d F Y') . "\n\n" .
                "━━━━━━━━━━━━━━━\n" .
                "💳 Sisa saldo {$balance->account_name}: *Rp {$currentBalance}*"
            );
            
            Log::info('Expense from wallet recorded successfully', [
                'message_id' => $this->message->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'wallet' => $walletName,
                'balance_id' => $balance->id,
                'category' => $categoryType
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error handling expense from wallet', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal mencatat pengeluaran*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
    
    /**
     * Handle add wallet/payment method request
     * Supports: "tambah dompet BCA", "tambah metode pembayaran Gopay saldo 100rb"
     * 
     * MOVED FROM: ProcessIncomingMessage::handleAddWallet()
     * LINES: 8501-8622
     * MODIFICATION: None (structural move only)
     */
    public function handleAddWallet(string $messageText): void
    {
        try {
            $textLower = strtolower($messageText);
            
            // Extract wallet name
            $walletName = $this->extractWalletName($messageText);
            
            if (!$walletName) {
                $this->sendReply(
                    "💳 *Tambah Dompet/Rekening*\n\n" .
                    "Untuk menambah dompet, ketik:\n\n" .
                    "• _tambah dompet BCA_\n" .
                    "• _tambah rekening Mandiri_\n" .
                    "• _tambah dompet Gopay saldo 100rb_\n" .
                    "• _buat dompet Cash_\n\n" .
                    "*Jenis dompet yang didukung:*\n" .
                    "🏦 Bank: BCA, Mandiri, BRI, BNI, CIMB, dll\n" .
                    "📱 E-Wallet: Gopay, OVO, Dana, ShopeePay, dll\n" .
                    "💵 Tunai: Cash, Dompet"
                );
                return;
            }
            
            // Determine account type
            $accountType = $this->determineAccountType($walletName);
            
            // Extract optional initial balance
            $initialBalance = $this->extractAmountFromText($textLower) ?? 0;
            
            // Check if wallet already exists
            $existingWallet = Balance::where('tenant_id', $this->message->tenant_id)
                ->whereRaw('LOWER(account_name) = ?', [strtolower($walletName)])
                ->first();
            
            if ($existingWallet) {
                // Wallet already exists - show error
                $this->sendReply(
                    "⚠️ *Dompet Sudah Ada*\n\n" .
                    "Dompet *{$existingWallet->account_name}* sudah terdaftar.\n\n" .
                    "💰 Saldo saat ini: Rp " . number_format($existingWallet->balance, 0, ',', '.') . "\n\n" .
                    "Gunakan _lihat dompet_ untuk melihat semua dompet Anda."
                );
                return;
            }
            
            // Create new wallet
            $wallet = Balance::create([
                'tenant_id' => $this->message->tenant_id,
                'account_name' => $walletName,
                'account_number' => null,
                'account_type' => $accountType,
                'currency' => 'IDR',
                'balance' => $initialBalance,
                'balance_date' => now(),
                'is_active' => true,
                'is_default' => false,
                'metadata' => [
                    'created_via' => 'whatsapp',
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
            
            // Set as default if this is the first wallet
            $walletCount = Balance::where('tenant_id', $this->message->tenant_id)->count();
            if ($walletCount === 1) {
                $wallet->is_default = true;
                $wallet->save();
            }
            
            $typeEmoji = match($accountType) {
                'bank' => '🏦',
                'wallet' => '📱',
                'cash' => '💵',
                'investment' => '📈',
                default => '💳',
            };
            
            $typeLabel = match($accountType) {
                'bank' => 'Bank',
                'wallet' => 'E-Wallet',
                'cash' => 'Tunai',
                'investment' => 'Investasi',
                default => 'Lainnya',
            };
            
            $reply = "✅ *Dompet Berhasil Ditambahkan!*\n\n";
            $reply .= "{$typeEmoji} *{$walletName}*\n";
            $reply .= "📁 Jenis: {$typeLabel}\n";
            $reply .= "💰 Saldo: Rp " . number_format($initialBalance, 0, ',', '.') . "\n\n";
            
            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= "💡 *Tips:*\n";
            $reply .= "• Catat transaksi: _makan 25rb dari {$walletName}_\n";
            $reply .= "• Update saldo: _update saldo {$walletName} 500rb_\n";
            $reply .= "• Lihat semua: _lihat dompet_";
            
            $this->sendReply($reply);
            
            Log::info('Wallet created via WhatsApp', [
                'message_id' => $this->message->id,
                'wallet_id' => $wallet->id,
                'wallet_name' => $walletName,
                'account_type' => $accountType,
                'initial_balance' => $initialBalance,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error adding wallet', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menambah dompet*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle list wallets request
     * 
     * MOVED FROM: ProcessIncomingMessage::handleListWallets()
     * LINES: 8624-8691
     * MODIFICATION: None (structural move only)
     */
    public function handleListWallets(): void
    {
        try {
            $wallets = Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->orderBy('is_default', 'desc')
                ->orderBy('account_name')
                ->get();
            
            if ($wallets->isEmpty()) {
                $this->sendReply(
                    "💳 *Daftar Dompet*\n\n" .
                    "Belum ada dompet yang terdaftar.\n\n" .
                    "*Tambah dompet pertama Anda:*\n" .
                    "• _tambah dompet BCA_\n" .
                    "• _tambah dompet Gopay_\n" .
                    "• _tambah dompet Cash_"
                );
                return;
            }
            
            $reply = "💳 *Daftar Dompet Anda*\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";
            
            $totalBalance = 0;
            foreach ($wallets as $wallet) {
                $typeEmoji = match($wallet->account_type) {
                    'bank' => '🏦',
                    'wallet' => '📱',
                    'cash' => '💵',
                    'investment' => '📈',
                    default => '💳',
                };
                
                $balance = $wallet->balance ?? 0;
                $totalBalance += $balance;
                $balanceFormatted = 'Rp ' . number_format($balance, 0, ',', '.');
                
                $defaultBadge = $wallet->is_default ? ' ⭐' : '';
                
                $reply .= "{$typeEmoji} *{$wallet->account_name}*{$defaultBadge}\n";
                $reply .= "   💰 {$balanceFormatted}\n\n";
            }
            
            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= "📊 *Total Saldo*: Rp " . number_format($totalBalance, 0, ',', '.') . "\n\n";
            
            $reply .= "💡 *Kelola dompet:*\n";
            $reply .= "• _tambah dompet [nama]_\n";
            $reply .= "• _hapus dompet [nama]_";
            
            $this->sendReply($reply);
            
        } catch (\Exception $e) {
            Log::error('Error listing wallets', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal memuat daftar dompet*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
    
    /**
     * Handle delete wallet request
     * 
     * MOVED FROM: ProcessIncomingMessage::handleDeleteWallet()
     * LINES: 8693-8777
     * MODIFICATION: None (structural move only)
     */
    public function handleDeleteWallet(string $messageText): void
    {
        try {
            $walletName = $this->extractWalletName($messageText);
            
            if (!$walletName) {
                // Show list of wallets to delete
                $wallets = Balance::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->get();
                
                if ($wallets->isEmpty()) {
                    $this->sendReply(
                        "💳 *Hapus Dompet*\n\n" .
                        "Tidak ada dompet untuk dihapus."
                    );
                    return;
                }
                
                $reply = "🗑️ *Hapus Dompet*\n\n";
                $reply .= "Pilih dompet yang ingin dihapus:\n\n";
                
                foreach ($wallets as $wallet) {
                    $reply .= "• _hapus dompet {$wallet->account_name}_\n";
                }
                
                $this->sendReply($reply);
                return;
            }
            
            // Find the wallet
            $wallet = Balance::where('tenant_id', $this->message->tenant_id)
                ->whereRaw('LOWER(account_name) = ?', [strtolower($walletName)])
                ->first();
            
            if (!$wallet) {
                $this->sendReply(
                    "⚠️ *Dompet Tidak Ditemukan*\n\n" .
                    "Dompet *{$walletName}* tidak ditemukan.\n\n" .
                    "Ketik _lihat dompet_ untuk melihat daftar dompet Anda."
                );
                return;
            }
            
            // Check if wallet has transactions
            $transactionCount = $wallet->transactions()->count();
            $balance = $wallet->balance ?? 0;
            $walletName = $wallet->account_name; // Store name before deletion
            
            // HARD DELETE - permanently remove from database
            // Note: Transactions linked to this wallet will remain (balance_id becomes orphaned but data is preserved)
            $wallet->delete();
            
            $reply = "✅ *Dompet Berhasil Dihapus*\n\n";
            $reply .= "🗑️ *{$walletName}*\n";
            $reply .= "💰 Saldo terakhir: Rp " . number_format($balance, 0, ',', '.') . "\n";
            
            if ($transactionCount > 0) {
                $reply .= "📝 {$transactionCount} transaksi terkait tetap tersimpan\n";
            }
            
            $reply .= "\n💡 Ketik _lihat dompet_ untuk melihat daftar dompet Anda.";
            
            $this->sendReply($reply);
            
            Log::info('Wallet deleted via WhatsApp', [
                'message_id' => $this->message->id,
                'wallet_name' => $walletName,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error deleting wallet', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menghapus dompet*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    /**
     * Extract wallet name from message text
     * 
     * MOVED FROM: ProcessIncomingMessage::extractWalletName()
     * LINES: 8779-8835
     * MODIFICATION: Added multi-line handling
     */
    public function extractWalletName(string $messageText): ?string
    {
        // FIX: Handle multi-line messages - only process the FIRST line
        // This prevents "tambah dompet BCA\ntambah dompet Gopay..." from creating a wallet with multi-line name
        $lines = preg_split('/[\r\n]+/', trim($messageText));
        $firstLine = $lines[0] ?? $messageText;
        
        $textLower = strtolower($firstLine);
        
        // SPECIAL CASE: Handle "dengan nama" pattern
        // e.g., "Buat dompet baru dengan nama Bank BCA" -> "Bank BCA"
        if (preg_match('/dengan\s+nama\s+(.+?)$/i', $firstLine, $namaMatch)) {
            $cleanText = trim($namaMatch[1]);
            // Remove trailing amount if any
            $cleanText = preg_replace('/\s*\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta)?\s*$/i', '', $cleanText);
            if (!empty($cleanText)) {
                return ucwords($cleanText);
            }
        }
        
        // Remove command keywords including "baru"
        $cleanText = preg_replace('/^(tambah|buat|add|hapus|delete|lihat|cek|daftar)\s+(dompet|rekening|metode pembayaran|bank|akun|wallet)\s*(baru\s*)?/i', '', trim($firstLine));
        
        // Remove "dengan nama" if still present at start
        $cleanText = preg_replace('/^dengan\s+nama\s*/i', '', $cleanText);
        
        // Remove amount suffix if present - handles multiple formats:
        // - "saldo 100rb", "saldo 5jt", "100000", "5.165.856", "5,000,000"
        // First, try to match Indonesian format with dots as thousand separators (e.g., 5.165.856)
        $cleanText = preg_replace('/\s*(saldo\s*)?\d{1,3}(?:[\.,]\d{3})+\s*(rb|ribu|k|jt|juta)?\s*$/i', '', $cleanText);
        // Then match simple numbers with optional suffix (e.g., 100rb, 5jt, 50000)
        $cleanText = preg_replace('/\s*(saldo\s*)?\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta)?\s*$/i', '', $cleanText);

        
        $cleanText = trim($cleanText);
        
        if (empty($cleanText)) {
            return null;
        }
        
        // Common wallet/bank names mapping (normalize names)
        $nameMapping = [
            'bca' => 'BCA',
            'mandiri' => 'Mandiri',
            'bri' => 'BRI',
            'bni' => 'BNI',
            'bjb' => 'BJB',
            'bank bjb' => 'BJB',
            'cimb' => 'CIMB Niaga',
            'cimb niaga' => 'CIMB Niaga',
            'permata' => 'Permata',
            'danamon' => 'Danamon',
            'btn' => 'BTN',
            'bsi' => 'BSI',

            'gopay' => 'GoPay',
            'go pay' => 'GoPay',
            'ovo' => 'OVO',
            'dana' => 'DANA',
            'shopeepay' => 'ShopeePay',
            'shopee pay' => 'ShopeePay',
            'linkaja' => 'LinkAja',
            'link aja' => 'LinkAja',
            'jenius' => 'Jenius',
            'blu' => 'Blu',
            'jago' => 'Jago',
            'cash' => 'Cash',
            'tunai' => 'Cash',
            'dompet' => 'Dompet',
        ];
        
        $cleanTextLower = strtolower($cleanText);
        
        if (isset($nameMapping[$cleanTextLower])) {
            return $nameMapping[$cleanTextLower];
        }
        
        // Return as-is with proper capitalization
        return ucwords($cleanText);
    }
    
    /**
     * Determine account type from wallet name
     * 
     * MOVED FROM: ProcessIncomingMessage::determineAccountType()
     * LINES: 8837-8886
     * MODIFICATION: None (structural move only)
     */
    public function determineAccountType(string $walletName): string
    {
        $nameLower = strtolower($walletName);
        
        // E-Wallets
        $wallets = ['gopay', 'ovo', 'dana', 'shopeepay', 'linkaja', 'sakuku', 'isaku', 'paypro'];
        foreach ($wallets as $wallet) {
            if (str_contains($nameLower, $wallet)) {
                return 'wallet';
            }
        }
        
        // Digital Banks (still categorized as bank)
        $digitalBanks = ['jenius', 'blu', 'jago', 'neo', 'line bank', 'seabank', 'tmrw'];
        foreach ($digitalBanks as $bank) {
            if (str_contains($nameLower, $bank)) {
                return 'bank';
            }
        }
        
        // Traditional Banks
        $banks = ['bca', 'mandiri', 'bri', 'bni', 'cimb', 'permata', 'danamon', 'btn', 'bsi', 'ocbc', 'hsbc', 'maybank', 'uob', 'bank'];
        foreach ($banks as $bank) {
            if (str_contains($nameLower, $bank)) {
                return 'bank';
            }
        }
        
        // Cash
        $cash = ['cash', 'tunai', 'dompet'];
        foreach ($cash as $c) {
            if (str_contains($nameLower, $c)) {
                return 'cash';
            }
        }
        
        // Investment
        $investment = ['saham', 'reksadana', 'investasi', 'crypto', 'bitcoin', 'bibit', 'bareksa', 'ajaib'];
        foreach ($investment as $inv) {
            if (str_contains($nameLower, $inv)) {
                return 'investment';
            }
        }
        
        // Default to other
        return 'other';
    }
    
    /**
     * Handle check balance request (cek saldo)
     * 
     * MOVED FROM: ProcessIncomingMessage::handleCheckBalance()
     * LINES: 7724-7848
     * MODIFICATION: None (structural move only)
     */
    public function handleCheckBalance(): void
    {
        try {
            // Get current month transactions for cashflow
            $startOfMonth = now()->startOfMonth();
            $endOfMonth = now()->endOfMonth();
            
            $transactions = Transaction::where('tenant_id', $this->message->tenant_id)
                ->where('status', 'confirmed')
                ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
                ->get();
            
            $totalIncome = $transactions->where('type', 'income')->sum('amount');
            $totalExpense = $transactions->where('type', 'expense')->sum('amount');
            $netCashflow = $totalIncome - $totalExpense;
            
            // Get balances (account balances)
            $balances = Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->get();
            
            $reply = "💰 *Ringkasan Keuangan*\n";
            $reply .= "📅 " . now()->translatedFormat('F Y') . "\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";
            
            // Cashflow section
            $reply .= "📊 *Cashflow Bulan Ini*\n";
            $reply .= "💵 Pendapatan: Rp " . number_format($totalIncome, 0, ',', '.') . "\n";
            $reply .= "💸 Pengeluaran: Rp " . number_format($totalExpense, 0, ',', '.') . "\n";
            
            $netEmoji = $netCashflow >= 0 ? '📈' : '📉';
            $netLabel = $netCashflow >= 0 ? 'Surplus' : 'Defisit';
            $reply .= "{$netEmoji} {$netLabel}: Rp " . number_format(abs($netCashflow), 0, ',', '.') . "\n";
            
            // Transaction count
            $transactionCount = $transactions->count();
            $reply .= "📝 Total Transaksi: {$transactionCount}\n\n";
            
            // Top Income Categories
            $incomeTransactions = $transactions->where('type', 'income');
            if ($incomeTransactions->isNotEmpty()) {
                $topIncome = $incomeTransactions
                    ->groupBy('category_id')
                    ->map(function($group) {
                        return [
                            'category' => $group->first()->category->name ?? 'Lainnya',
                            'total' => $group->sum('amount')
                        ];
                    })
                    ->sortByDesc('total')
                    ->take(3);
                
                $reply .= "💰 *Top Pemasukan:*\n";
                foreach ($topIncome as $item) {
                    $amount = number_format($item['total'], 0, ',', '.');
                    $reply .= "  • {$item['category']}: Rp {$amount}\n";
                }
                $reply .= "\n";
            }
            
            // Top Expense Categories
            $expenseTransactions = $transactions->where('type', 'expense');
            if ($expenseTransactions->isNotEmpty()) {
                $topExpense = $expenseTransactions
                    ->groupBy('category_id')
                    ->map(function($group) {
                        return [
                            'category' => $group->first()->category->name ?? 'Lainnya',
                            'total' => $group->sum('amount')
                        ];
                    })
                    ->sortByDesc('total')
                    ->take(3);
                
                $reply .= "💸 *Top Pengeluaran:*\n";
                foreach ($topExpense as $item) {
                    $amount = number_format($item['total'], 0, ',', '.');
                    $reply .= "  • {$item['category']}: Rp {$amount}\n";
                }
                $reply .= "\n";
            }
            
            // Account balances section (if any)
            if ($balances->isNotEmpty()) {
                $reply .= "🏦 *Saldo Rekening*\n";
                $totalBalance = 0;
                foreach ($balances as $balance) {
                    $amount = $balance->balance ?? 0;
                    $totalBalance += $amount;
                    $emoji = $amount >= 0 ? '💵' : '⚠️';
                    $accountName = $balance->account_name ?: ($balance->name ?? 'Akun');
                    $reply .= "{$emoji} {$accountName}: Rp " . number_format($amount, 0, ',', '.') . "\n";
                }
                $reply .= "\n━━━━━━━━━━━━━━━\n";
                $reply .= "💰 *Total Saldo: Rp " . number_format($totalBalance, 0, ',', '.') . "*\n";
            }
            
            $reply .= "━━━━━━━━━━━━━━━\n";
            
            // Financial advice
            if ($netCashflow > 0) {
                $reply .= "✅ Kondisi keuangan sehat!\n";
            } elseif ($netCashflow == 0) {
                $reply .= "⚖️ Pengeluaran sama dengan pendapatan.\n";
            } else {
                $reply .= "⚠️ Pengeluaran melebihi pendapatan!\n";
            }
            
            $this->sendReply($reply);
            
        } catch (\Exception $e) {
            Log::error('Error checking balance', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal memuat saldo*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
}
