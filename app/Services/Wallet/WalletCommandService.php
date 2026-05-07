<?php

namespace App\Services\Wallet;

use App\Models\Balance;
use App\Models\Category;
use App\Models\Message;
use App\Models\Transaction;
use App\Services\BalanceService;
use App\Services\TenantProvisioningService;
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
     * @param  Message  $message  The message being processed
     * @param  callable  $sendReplyCallback  Callback to send reply via WhatsApp
     * @param  callable  $extractAmountCallback  Callback to extract amount from text
     * @param  callable  $createTransactionCallback  Callback to create transaction
     * @param  callable  $determineCategoryCallback  Callback to determine category from description
     */
    public function __construct(
        Message $message,
        callable $sendReplyCallback,
        ?callable $extractAmountCallback = null,
        ?callable $createTransactionCallback = null,
        ?callable $determineCategoryCallback = null
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
     * Get primary wallet for tenant.
     * Priority: default active wallet -> latest active wallet -> auto-create Dompet Utama.
     */
    protected function getOrCreatePrimaryWallet(): ?Balance
    {
        $tenantId = $this->message->tenant_id;

        $balance = Balance::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        if ($balance) {
            return $balance;
        }

        $balance = Balance::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($balance) {
            // Self-heal: ensure there is a primary wallet marker.
            $balance->is_default = true;
            $balance->save();

            return $balance;
        }

        // No wallet at all: create default primary wallet.
        return Balance::create([
            'tenant_id' => $tenantId,
            'account_name' => 'Dompet Utama',
            'account_number' => null,
            'account_type' => 'cash',
            'currency' => 'IDR',
            'balance' => 0,
            'balance_date' => now(),
            'is_active' => true,
            'is_default' => true,
            'metadata' => [
                'created_via' => 'auto_primary_wallet_fallback',
                'created_at' => now()->toIso8601String(),
            ],
        ]);
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
            // Normalize forwarded/chat-export style prefix:
            // "[7/4, 21.26] Daeng Digital: Saldo 400 ribu" -> "Saldo 400 ribu"
            $messageText = trim(preg_replace('/^\s*(?:\[[^\]]+\]\s*)?(?:[^:\r\n]{1,80}:\s*)/u', '', $messageText) ?? $messageText);

            // Bare "saldo <nominal>" (tanpa nama dompet / tanpa set/ubah) = tambah ke dompet utama,
            // sama seperti "tambah saldo <nominal>". Bukan set absolut.
            // Set absolut: "set saldo ... jadi", "ubah saldo ...", "saldo BCA = 300rb", dll.
            if (preg_match('/^saldo\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?)\s*$/iu', trim($messageText), $bareSaldoMatch)) {
                $amtStr = trim($bareSaldoMatch[1]);
                $amt = $this->extractAmountFromText($amtStr);
                if ($amt !== null && $amt > 0) {
                    $this->handleAdjustWalletBalance('tambah saldo '.$amtStr, 'increase');

                    return;
                }
            }

            // SPECIAL CASE 1: "Update saldo jadi 5juta" - no wallet name, just amount
            // Use last created wallet or default wallet
            if (preg_match('/^(?:update|set|ubah|ganti)\s+saldo\s+(?:jadi|menjadi|ke|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)$/i', trim($messageText), $shortMatch)) {
                $amountStr = trim($shortMatch[1]);
                $newAmount = $this->extractAmountFromText($amountStr);

                if ($newAmount !== null) {
                    // Find last created or default wallet
                    $balance = $this->getOrCreatePrimaryWallet();

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
                            "✏️ *Saldo Berhasil Diubah!* ✅\n\n".
                            "👛 Dompet: *{$balance->account_name}*\n\n".
                            "📊 Perubahan Saldo:\n".
                            "   Sebelum: Rp {$oldFormatted}\n".
                            "   Sesudah: *Rp {$newFormatted}*\n".
                            "   Selisih: {$diffSign}Rp {$diffFormatted}\n\n".
                            '📅 Diubah: '.now()->translatedFormat('d F Y H:i')
                        );

                        return;
                    }
                }
            }

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

                    if (! $balance) {
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
                            "✏️ *Saldo Berhasil Diubah!* ✅\n\n".
                            "👛 Dompet: *{$balance->account_name}*\n\n".
                            "📊 Perubahan Saldo:\n".
                            "   Sebelum: Rp {$oldFormatted}\n".
                            "   Sesudah: *Rp {$newFormatted}*\n".
                            "   Selisih: {$diffSign}Rp {$diffFormatted}\n\n".
                            '📅 Diubah: '.now()->translatedFormat('d F Y H:i')
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
                    "⚠️ *Dompet mana yang ingin diupdate?*\n\n".
                    "Dompet tersedia:\n{$walletList}\n\n".
                    'Contoh: _update saldo BCA jadi 5jt_'
                );

                return;
            }

            // Patterns to extract wallet name and amount
            $patterns = [
                '/(?:set|atur|ubah|edit|ganti)\s+saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s*=\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/(?:update|koreksi)\s+saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+(?:sekarang|jadi|menjadi)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                // Simple format: "update saldo BCA 500rb" or "set saldo Dana 1jt"
                '/(?:update|set|atur|ubah|edit|ganti)\s+saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)$/i',
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

            if (! $walletName) {
                $this->sendReply(
                    "⚠️ *Format tidak dikenali*\n\n".
                    "Contoh format yang benar:\n".
                    "• _set saldo BCA menjadi 100rb_\n".
                    "• _ubah saldo Gopay jadi 50.000_\n".
                    "• _saldo Dana = 1jt_\n".
                    "• _ganti saldo O ke 49.000_\n".
                    '• _koreksi saldo Cash 25rb_'
                );

                return;
            }

            // Extract amount
            $newAmount = $this->extractAmountFromText($amountStr ?? '');

            if ($newAmount === null) {
                $this->sendReply(
                    "⚠️ *Nominal tidak terdeteksi*\n\n".
                    "Pastikan format nominal benar:\n".
                    "• 100rb, 100.000, 100k\n".
                    "• 1jt, 1.000.000\n\n".
                    'Contoh: _set saldo BCA menjadi 100rb_'
                );

                return;
            }

            // Find the wallet - PRIORITY: Exact match first, then partial match (prefer shortest name)
            $balance = Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->whereRaw('LOWER(account_name) = ?', [strtolower($walletName)])
                ->first();

            // If no exact match, try partial match but prefer shortest name
            if (! $balance) {
                $balance = Balance::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->whereRaw('LOWER(account_name) LIKE ?', ['%'.strtolower($walletName).'%'])
                    ->orderByRaw('LENGTH(account_name) ASC') // Prefer shorter names (more likely exact match)
                    ->first();
            }

            if (! $balance) {
                // List available wallets
                $availableWallets = Balance::where('tenant_id', $this->message->tenant_id)
                    ->pluck('account_name')
                    ->toArray();

                $walletList = empty($availableWallets)
                    ? 'Belum ada dompet. Buat dengan: _tambah dompet BCA_'
                    : implode(', ', $availableWallets);

                $this->sendReply(
                    "⚠️ *Dompet '{$walletName}' tidak ditemukan*\n\n".
                    "Dompet tersedia:\n{$walletList}\n\n".
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
                'difference' => $difference,
            ]);

            // Send confirmation
            $this->sendReply(
                "✏️ *Saldo Berhasil Diubah!* ✅\n\n".
                "👛 Dompet: *{$balance->account_name}*\n\n".
                "📊 Perubahan Saldo:\n".
                "   Sebelum: Rp {$oldFormatted}\n".
                "   Sesudah: *Rp {$newFormatted}*\n".
                "   Selisih: {$diffSign}Rp {$diffFormatted}\n\n".
                '📅 Diubah: '.now()->translatedFormat('d F Y H:i')
            );

        } catch (\Exception $e) {
            Log::error('Error setting wallet balance', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mengubah saldo*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle multiple wallet balance updates from a single multi-line message
     * e.g., "Update saldo BRI 1.598.059\nUpdate saldo BJB 5.165.856\nUpdate saldo BSI 31.163.373"
     *
     * @param  string  $messageText  The full message text containing multiple update commands
     */
    public function handleMultipleSetWalletBalance(string $messageText): void
    {
        try {
            // Split message by newlines
            $lines = preg_split('/[\r\n]+/', $messageText);
            $lines = array_filter($lines, fn ($line) => ! empty(trim($line)));

            // Patterns to extract wallet name and amount
            $patterns = [
                '/(?:set|atur|ubah|edit|ganti)\s+saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s*=\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/(?:update|koreksi)\s+saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+(?:sekarang|jadi|menjadi)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                // Simple format: "update saldo BCA 500rb" or "set saldo Dana 1jt"
                '/(?:update|set|atur|ubah|edit|ganti)\s+saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)$/i',
                // NEW: Direct format: "saldo dompet utama 1 juta", "saldo BCA 500rb"
                '/^saldo\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?)/i',
            ];

            $updates = [];
            $errors = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $walletName = null;
                $amountStr = null;

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        $walletName = trim($matches[1]);
                        $amountStr = trim($matches[2]);
                        break;
                    }
                }

                if (! $walletName || ! $amountStr) {
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
                if (! $balance) {
                    $balance = Balance::where('tenant_id', $this->message->tenant_id)
                        ->where('is_active', true)
                        ->whereRaw('LOWER(account_name) LIKE ?', ['%'.strtolower($walletName).'%'])
                        ->orderByRaw('LENGTH(account_name) ASC')
                        ->first();
                }

                if (! $balance) {
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
                    'difference' => $difference,
                ]);
            }

            // Build response
            if (empty($updates) && empty($errors)) {
                $this->sendReply(
                    "⚠️ *Format tidak dikenali*\n\n".
                    "Contoh format yang benar:\n".
                    "• _update saldo BCA 100rb_\n".
                    "• _set saldo Gopay menjadi 50.000_\n\n".
                    "Untuk update banyak dompet sekaligus:\n".
                    "• _update saldo BCA 100rb_\n".
                    "• _update saldo Dana 50rb_\n".
                    '• _update saldo Gopay 200rb_'
                );

                return;
            }

            $reply = "✏️ *Saldo Berhasil Diubah!* ✅\n\n";

            foreach ($updates as $update) {
                $reply .= "👛 *{$update['wallet_name']}*\n";
                $reply .= "   📊 Rp {$update['old_formatted']} → *Rp {$update['new_formatted']}*\n";
                $reply .= "   Selisih: {$update['diff_sign']}Rp {$update['diff_formatted']}\n\n";
            }

            if (! empty($errors)) {
                $reply .= "━━━━━━━━━━━━━━━\n";
                $reply .= "*Gagal diproses:*\n";
                foreach ($errors as $error) {
                    $reply .= "{$error}\n";
                }
                $reply .= "\n";
            }

            $reply .= '📅 Diubah: '.now()->translatedFormat('d F Y H:i');

            $this->sendReply($reply);

        } catch (\Exception $e) {
            Log::error('Error setting multiple wallet balances', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mengubah saldo*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle adjust (reduce/increase) wallet balance
     * e.g., "kurangi saldo BRI 1.000.000", "tambah saldo BCA 500rb"
     *
     * @param  string  $messageText  The message text
     * @param  string  $operation  'reduce' or 'increase'
     */
    public function handleAdjustWalletBalance(string $messageText, string $operation = 'reduce'): void
    {
        try {
            // Normalize forwarded/chat-export style prefix
            $messageText = trim(preg_replace('/^\s*(?:\[[^\]]+\]\s*)?(?:[^:\r\n]{1,80}:\s*)/u', '', $messageText) ?? $messageText);
            $messageText = trim($messageText);

            // Ultimate fallback ONLY for no-wallet format:
            // "tambah saldo 400 rb", "kurangi saldo 50rb"
            // Do NOT capture commands with wallet name, e.g. "tambah saldo BRI 500rb".
            if (preg_match('/^(?:tambah|tambahkan|increase|plus|kurangi|kurang|reduce|minus|potong)\s+saldo\s+(\d[\d\.,]*\s*(?:rb|ribu|k|jt|juta|m|million)?)\s*$/iu', $messageText, $rawAmountMatch)) {
                $adjustAmount = $this->extractAmountFromText(trim($rawAmountMatch[1]));
                if ($adjustAmount !== null && $adjustAmount > 0) {
                    $balance = $this->getOrCreatePrimaryWallet();

                    if ($balance) {
                        $oldAmount = $balance->balance ?? 0;
                        $isReduceCommand = preg_match('/\b(kurangi|kurang|reduce|minus|potong)\b/i', $messageText) === 1;
                        $operation = $isReduceCommand ? 'reduce' : 'increase';
                        $newAmount = $operation === 'reduce'
                            ? $oldAmount - $adjustAmount
                            : $oldAmount + $adjustAmount;

                        $balance->balance = $newAmount;
                        $balance->save();

                        $oldFormatted = number_format($oldAmount, 0, ',', '.');
                        $newFormatted = number_format($newAmount, 0, ',', '.');
                        $adjustFormatted = number_format($adjustAmount, 0, ',', '.');
                        $operationEmoji = $operation === 'reduce' ? '➖' : '➕';
                        $operationText = $operation === 'reduce' ? 'Dikurangi' : 'Ditambah';

                        $this->sendReply(
                            "{$operationEmoji} *Saldo Berhasil {$operationText}!* ✅\n\n".
                            "👛 Dompet: *{$balance->account_name}*\n\n".
                            "📊 Perubahan Saldo:\n".
                            "   Sebelum: Rp {$oldFormatted}\n".
                            "   {$operationText}: Rp {$adjustFormatted}\n".
                            "   Sesudah: *Rp {$newFormatted}*\n\n".
                            '📅 Diubah: '.now()->translatedFormat('d F Y H:i')
                        );

                        return;
                    }
                }
            }

            // Special case: no wallet name provided, fallback to default/last active wallet
            // e.g., "tambah saldo 400 ribu", "kurangi saldo 50rb"
            if (preg_match('/(?:kurangi|kurang|reduce|minus|tambah|tambahkan|increase|plus)\s+saldo\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?)/i', $messageText, $simpleMatch)) {
                $amountFromSimplePattern = $this->extractAmountFromText(trim($simpleMatch[1]));
                if ($amountFromSimplePattern !== null && $amountFromSimplePattern > 0) {
                    $walletForSimplePattern = $this->getOrCreatePrimaryWallet();

                    if ($walletForSimplePattern) {
                        $oldAmount = $walletForSimplePattern->balance ?? 0;
                        $isReduceCommand = preg_match('/kurangi|kurang|reduce|minus/i', $messageText) === 1;
                        $operation = $isReduceCommand ? 'reduce' : 'increase';
                        $newAmount = $operation === 'reduce'
                            ? $oldAmount - $amountFromSimplePattern
                            : $oldAmount + $amountFromSimplePattern;

                        $walletForSimplePattern->balance = $newAmount;
                        $walletForSimplePattern->save();

                        $oldFormatted = number_format($oldAmount, 0, ',', '.');
                        $newFormatted = number_format($newAmount, 0, ',', '.');
                        $adjustFormatted = number_format($amountFromSimplePattern, 0, ',', '.');
                        $operationEmoji = $operation === 'reduce' ? '➖' : '➕';
                        $operationText = $operation === 'reduce' ? 'Dikurangi' : 'Ditambah';

                        $this->sendReply(
                            "{$operationEmoji} *Saldo Berhasil {$operationText}!* ✅\n\n".
                            "👛 Dompet: *{$walletForSimplePattern->account_name}* _(dompet utama)_\n\n".
                            "📊 Perubahan Saldo:\n".
                            "   Sebelum: Rp {$oldFormatted}\n".
                            "   {$operationText}: Rp {$adjustFormatted}\n".
                            "   Sesudah: *Rp {$newFormatted}*\n\n".
                            '📅 Diubah: '.now()->translatedFormat('d F Y H:i')
                        );

                        return;
                    }
                }
            }

            // Patterns to extract wallet name and amount
            $patterns = [
                '/(?:kurangi|kurang|reduce|minus)\\s+saldo\\s+(?:dompet\\s+|akun\\s+|bank\\s+|rekening\\s+)?([a-zA-Z0-9\\s]+?)\\s+([\\d\\.,]+\\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/(?:tambah|tambahkan|increase|plus)\\s+saldo\\s+(?:dompet\\s+|akun\\s+|bank\\s+|rekening\\s+)?([a-zA-Z0-9\\s]+?)\\s+([\\d\\.,]+\\s*(?:rb|ribu|k|jt|juta)?)/i',
            ];

            $walletName = null;
            $amountStr = null;

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $messageText, $matches)) {
                    $walletName = trim($matches[1]);
                    $amountStr = trim($matches[2]);

                    // Determine operation from pattern
                    if (preg_match('/kurangi|kurang|reduce|minus/i', $messageText)) {
                        $operation = 'reduce';
                    } else {
                        $operation = 'increase';
                    }
                    break;
                }
            }

            if (! $walletName) {
                $this->sendReply(
                    "⚠️ *Format tidak dikenali*\n\n".
                    "Contoh format yang benar:\n".
                    "• _kurangi saldo BRI 1.000.000_\n".
                    "• _tambah saldo BCA 500rb_\n".
                    "• _tambah saldo 400 ribu_ (otomatis dompet utama)\n".
                    "• _kurang saldo Dana 100k_\n".
                    '• _tambahkan saldo Gopay 50.000_'
                );

                return;
            }

            // Extract amount
            $adjustAmount = $this->extractAmountFromText($amountStr ?? '');

            if ($adjustAmount === null || $adjustAmount <= 0) {
                $this->sendReply(
                    "⚠️ *Nominal tidak terdeteksi*\n\n".
                    "Pastikan format nominal benar:\n".
                    "• 100rb, 100.000, 100k\n".
                    "• 1jt, 1.000.000\n\n".
                    'Contoh: _kurangi saldo BRI 1.000.000_'
                );

                return;
            }

            // Find the wallet - PRIORITY: Exact match first, then partial match
            $balance = Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->whereRaw('LOWER(account_name) = ?', [strtolower($walletName)])
                ->first();

            // If no exact match, try partial match but prefer shortest name
            if (! $balance) {
                $balance = Balance::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->whereRaw('LOWER(account_name) LIKE ?', ['%'.strtolower($walletName).'%'])
                    ->orderByRaw('LENGTH(account_name) ASC')
                    ->first();
            }

            if (! $balance) {
                // List available wallets
                $availableWallets = Balance::where('tenant_id', $this->message->tenant_id)
                    ->pluck('account_name')
                    ->toArray();

                $walletList = empty($availableWallets)
                    ? 'Belum ada dompet. Buat dengan: _tambah dompet BCA_'
                    : implode(', ', $availableWallets);

                $this->sendReply(
                    "⚠️ *Dompet '{$walletName}' tidak ditemukan*\n\n".
                    "Dompet tersedia:\n{$walletList}\n\n".
                    "Atau buat dompet baru: _tambah dompet {$walletName}_"
                );

                return;
            }

            // Store old balance for comparison
            $oldAmount = $balance->balance ?? 0;

            // Calculate new balance
            if ($operation === 'reduce') {
                $newAmount = $oldAmount - $adjustAmount;
            } else {
                $newAmount = $oldAmount + $adjustAmount;
            }

            $difference = $newAmount - $oldAmount;

            // Update the balance
            $balance->balance = $newAmount;
            $balance->save();

            // Format for display
            $oldFormatted = number_format($oldAmount, 0, ',', '.');
            $newFormatted = number_format($newAmount, 0, ',', '.');
            $adjustFormatted = number_format($adjustAmount, 0, ',', '.');
            $diffSign = $difference >= 0 ? '+' : '-';

            Log::info('Wallet balance adjusted', [
                'message_id' => $this->message->id,
                'balance_id' => $balance->id,
                'wallet_name' => $balance->account_name,
                'operation' => $operation,
                'old_amount' => $oldAmount,
                'adjust_amount' => $adjustAmount,
                'new_amount' => $newAmount,
            ]);

            // Send confirmation
            $operationEmoji = $operation === 'reduce' ? '➖' : '➕';
            $operationText = $operation === 'reduce' ? 'Dikurangi' : 'Ditambah';

            $this->sendReply(
                "{$operationEmoji} *Saldo Berhasil {$operationText}!* ✅\n\n".
                "👛 Dompet: *{$balance->account_name}*\n\n".
                "📊 Perubahan Saldo:\n".
                "   Sebelum: Rp {$oldFormatted}\n".
                "   {$operationText}: Rp {$adjustFormatted}\n".
                "   Sesudah: *Rp {$newFormatted}*\n\n".
                '📅 Diubah: '.now()->translatedFormat('d F Y H:i')
            );

        } catch (\Exception $e) {
            Log::error('Error adjusting wallet balance', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mengubah saldo*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
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

            if (! $amount || $amount <= 0) {
                $this->sendReply(
                    "⚠️ *Nominal tidak terdeteksi*\n\n".
                    "Contoh format yang benar:\n".
                    "• _dapet tf 25rb ke BCA_\n".
                    "• _terima transfer 100.000 ke Gopay_\n".
                    '• _dapat kiriman 50k ke Dana_'
                );

                return;
            }

            $messageTrimmed = trim($messageText);
            $messageWithoutTrailingAmount = preg_replace(
                '/\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?\s*$/iu',
                '',
                $messageTrimmed
            ) ?? $messageTrimmed;

            // Extract wallet name
            $walletName = null;
            // Pattern 1: Top Up (saldo [Wallet] [Amount])
            if (preg_match('/(?:isi|tambah|top\s*up)\s+saldo\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+?)\s+[\d\.,]/i', $messageText, $matches)) {
                $walletName = trim($matches[1]);
            }
            // Pattern 2: "uang masuk BCA 300rb" atau "masuk ke/di Dana 100rb" — bukan "Masuk gaji 5jt" (pemasukan biasa)
            elseif (preg_match('/(?:uang\s+masuk|masuk\s+(?:ke|di))\s+([a-z0-9\s]+?)\s+[\d\.,]/i', $messageText, $matches)) {
                $walletName = trim($matches[1]);
            }
            // Pattern 3: "tambah uang ke Jago Hadi 600rb" / "isi uang Dana 50rb"
            elseif (preg_match('/(?:isi|tambah|top\s*up)\s+uang\s+(?:ke\s+|di\s+)?([a-zA-Z0-9\s]+?)\s+[\d\.,]/i', $messageText, $matches)) {
                $walletName = trim($matches[1]);
            }
            // Pattern 4: "tambah uang 600rb ke Jago Hadi"
            elseif (preg_match('/(?:isi|tambah|top\s*up)\s+uang\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?\s+(?:ke|di)\s+([a-zA-Z0-9\s]+)$/i', $messageText, $matches)) {
                $walletName = trim($matches[1]);
            }
            // Pattern 5: Standard transfer (di/ke [Wallet] at end) — safe guard: strip trailing amount first
            elseif (preg_match('/(?:ke|di)\s+([a-zA-Z0-9\s]+)$/i', $messageWithoutTrailingAmount, $matches)) {
                $walletName = trim($matches[1]);
            }

            if (is_string($walletName) && $walletName !== '') {
                $walletName = trim(preg_replace('/\s+/', ' ', $walletName) ?? $walletName);
                $walletName = trim(preg_replace('/\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)\s*$/iu', '', $walletName) ?? $walletName);
            }

            if (! $walletName) {
                $this->sendReply(
                    "⚠️ *Nama dompet tidak terdeteksi*\n\n".
                    "Contoh format yang benar:\n".
                    "• _dapet tf 25rb ke BCA_\n".
                    "• _tambah saldo Dana 50.000_\n".
                    '• _isi saldo Gopay 100rb_'
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

            if (! $balance) {
                $this->sendReply(
                    "⚠️ *Gagal menemukan/membuat dompet*\n\n".
                    "Tidak dapat membuat dompet '{$walletName}'. Silakan coba lagi."
                );

                return;
            }

            // Determine description
            $description = 'Transfer masuk';
            if (preg_match('/(?:dari|from)\s+([a-zA-Z\s]+)/i', $messageText, $fromMatch)) {
                $description = 'Transfer dari '.trim($fromMatch[1]);
            } elseif (str_contains($textLower, 'tambah saldo') || str_contains($textLower, 'isi saldo') || str_contains($textLower, 'top up') || str_contains($textLower, 'tambah uang') || str_contains($textLower, 'isi uang')) {
                $description = 'Top Up Saldo';
            }

            // Create the income transaction
            $txData = [
                'type' => 'kredit_internal',
                'amount' => $amount,
                'category_type' => 'kredit_internal',
                'description' => $description,
                'transaction_date' => now()->toDateString(),
                'account_name' => $walletName,
                'confidence_score' => 0.95,
            ];

            $transaction = $this->createTransaction($txData);

            if (! $transaction) {
                $this->sendReply(
                    "⚠️ *Gagal mencatat transfer*\n\n".
                    'Terjadi kesalahan. Silakan coba lagi.'
                );

                return;
            }

            // Get updated balance
            $balance->refresh();
            $currentBalance = number_format($balance->balance ?? 0, 0, ',', '.');
            $amountFormatted = number_format($amount, 0, ',', '.');

            // Send success confirmation
            $this->sendReply(
                "💵 *Transfer Masuk Tercatat!* ✅\n\n".
                "💰 Nominal: *Rp {$amountFormatted}*\n".
                "👛 Dompet: *{$balance->account_name}*\n".
                "📁 Tipe: `Internal Transfer`\n".
                "📝 Keterangan: {$description}\n".
                '📅 Tanggal: '.now()->translatedFormat('d F Y')."\n\n".
                "━━━━━━━━━━━━━━━\n".
                "💳 Saldo {$balance->account_name}: *Rp {$currentBalance}*"
            );

            Log::info('Transfer to wallet recorded successfully', [
                'message_id' => $this->message->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'wallet' => $walletName,
                'balance_id' => $balance->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling transfer to wallet', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mencatat transfer*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
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

            if (! $walletName || ! isset($amountStr)) {
                $this->sendReply(
                    "⚠️ *Format tidak dikenali*\n\n".
                    "Contoh format yang benar:\n".
                    "• _Pengeluaran dompet kas kepri 98k beli mata kunci_\n".
                    "• _keluar dari BCA 50rb beli kopi_\n".
                    "• _bayar dari gopay 25rb grab_\n".
                    '• _dompet dana 100rb belanja_'
                );

                return;
            }

            // Extract amount
            $amount = $this->extractAmountFromText($amountStr);

            if (! $amount || $amount <= 0) {
                $this->sendReply(
                    "⚠️ *Nominal tidak terdeteksi*\n\n".
                    "Pastikan format nominal benar:\n".
                    "• 98k, 98rb, 98.000\n".
                    "• 1jt, 1.000.000\n\n".
                    'Contoh: _Pengeluaran dompet kas kepri 98rb beli mata kunci_'
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

            if (! $balance) {
                $this->sendReply(
                    "⚠️ *Gagal menemukan/membuat dompet*\n\n".
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
                'confidence_score' => 0.95,
            ];

            $transaction = $this->createTransaction($txData);

            if (! $transaction) {
                $this->sendReply(
                    "⚠️ *Gagal mencatat pengeluaran*\n\n".
                    'Terjadi kesalahan. Silakan coba lagi.'
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
                "💸 *Pengeluaran Tercatat!* ✅\n\n".
                "💰 Nominal: *Rp {$amountFormatted}*\n".
                "👛 Dompet: *{$balance->account_name}*\n".
                "📁 Kategori: {$categoryName}\n".
                "📝 Keterangan: {$description}\n".
                '📅 Tanggal: '.now()->translatedFormat('d F Y')."\n\n".
                "━━━━━━━━━━━━━━━\n".
                "💳 Sisa saldo {$balance->account_name}: *Rp {$currentBalance}*"
            );

            Log::info('Expense from wallet recorded successfully', [
                'message_id' => $this->message->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'wallet' => $walletName,
                'balance_id' => $balance->id,
                'category' => $categoryType,
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling expense from wallet', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mencatat pengeluaran*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
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

            if (! $walletName) {
                $this->sendReply(
                    "💳 *Tambah Dompet/Rekening*\n\n".
                    "Untuk menambah dompet, ketik:\n\n".
                    "• _tambah dompet BCA_\n".
                    "• _tambah rekening Mandiri_\n".
                    "• _tambah dompet Gopay saldo 100rb_\n".
                    "• _buat dompet Cash_\n\n".
                    "*Jenis dompet yang didukung:*\n".
                    "🏦 Bank: BCA, Mandiri, BRI, BNI, CIMB, dll\n".
                    "📱 E-Wallet: Gopay, OVO, Dana, ShopeePay, dll\n".
                    '💵 Tunai: Cash, Dompet'
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
                    "⚠️ *Dompet Sudah Ada*\n\n".
                    "Dompet *{$existingWallet->account_name}* sudah terdaftar.\n\n".
                    '💰 Saldo saat ini: Rp '.number_format($existingWallet->balance, 0, ',', '.')."\n\n".
                    'Gunakan _lihat dompet_ untuk melihat semua dompet Anda.'
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

            $typeEmoji = match ($accountType) {
                'bank' => '🏦',
                'wallet' => '📱',
                'cash' => '💵',
                'investment' => '📈',
                default => '💳',
            };

            $typeLabel = match ($accountType) {
                'bank' => 'Bank',
                'wallet' => 'E-Wallet',
                'cash' => 'Tunai',
                'investment' => 'Investasi',
                default => 'Lainnya',
            };

            $reply = "✅ *Dompet Berhasil Ditambahkan!*\n\n";
            $reply .= "{$typeEmoji} *{$walletName}*\n";
            $reply .= "📁 Jenis: {$typeLabel}\n";
            $reply .= '💰 Saldo: Rp '.number_format($initialBalance, 0, ',', '.')."\n\n";

            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= "💡 *Tips:*\n";
            $reply .= "• Catat transaksi: _makan 25rb dari {$walletName}_\n";
            $reply .= "• Update saldo: _update saldo {$walletName} 500rb_\n";
            $reply .= '• Lihat semua: _lihat dompet_';

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
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal menambah dompet*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
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
                    "💳 *Daftar Dompet*\n\n".
                    "Belum ada dompet yang terdaftar.\n\n".
                    "*Tambah dompet pertama Anda:*\n".
                    "• _tambah dompet BCA_\n".
                    "• _tambah dompet Gopay_\n".
                    '• _tambah dompet Cash_'
                );

                return;
            }

            $reply = "💳 *Daftar Dompet Anda*\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";

            $totalBalance = 0;
            foreach ($wallets as $wallet) {
                $typeEmoji = match ($wallet->account_type) {
                    'bank' => '🏦',
                    'wallet' => '📱',
                    'cash' => '💵',
                    'investment' => '📈',
                    default => '💳',
                };

                $balance = $wallet->balance ?? 0;
                $totalBalance += $balance;
                $balanceFormatted = 'Rp '.number_format($balance, 0, ',', '.');

                $defaultBadge = $wallet->is_default ? ' ⭐' : '';

                $reply .= "{$typeEmoji} *{$wallet->account_name}*{$defaultBadge}\n";
                $reply .= "   💰 {$balanceFormatted}\n\n";
            }

            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= '📊 *Total Saldo*: Rp '.number_format($totalBalance, 0, ',', '.')."\n\n";

            $reply .= "💡 *Kelola dompet:*\n";
            $reply .= "• _tambah dompet [nama]_\n";
            $reply .= '• _hapus dompet [nama]_';

            $this->sendReply($reply);

        } catch (\Exception $e) {
            Log::error('Error listing wallets', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal memuat daftar dompet*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
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

            if (! $walletName) {
                // Show list of wallets to delete
                $wallets = Balance::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->get();

                if ($wallets->isEmpty()) {
                    $this->sendReply(
                        "💳 *Hapus Dompet*\n\n".
                        'Tidak ada dompet untuk dihapus.'
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

            if (! $wallet) {
                $this->sendReply(
                    "⚠️ *Dompet Tidak Ditemukan*\n\n".
                    "Dompet *{$walletName}* tidak ditemukan.\n\n".
                    'Ketik _lihat dompet_ untuk melihat daftar dompet Anda.'
                );

                return;
            }

            // Check if wallet has transactions
            $transactionCount = $wallet->transactions()->count();
            $balance = $wallet->balance ?? 0;
            $walletName = $wallet->account_name; // Store name before deletion

            app(TenantProvisioningService::class)->permanentlyDeleteBalance($wallet);

            $reply = "✅ *Dompet Berhasil Dihapus*\n\n";
            $reply .= "🗑️ *{$walletName}*\n";
            $reply .= '💰 Saldo terakhir: Rp '.number_format($balance, 0, ',', '.')."\n";

            if ($transactionCount > 0) {
                $reply .= "📝 {$transactionCount} transaksi terkait ikut dihapus dari catatan\n";
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
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal menghapus dompet*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
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
            if (! empty($cleanText)) {
                return ucwords($cleanText);
            }
        }

        // Remove command keywords including "baru"
        // IMPROVEMENT: Only consume the "type" keyword (dompet/rekening/etc) if it's followed by the actual name.
        // If "hapus rekening" is the whole command, then "rekening" IS the name.
        $cleanText = preg_replace('/^(tambah|buat|add|hapus|delete|lihat|cek|daftar|saldo|cashflow|arus\s+kas)\s+(dompet|rekening|metode pembayaran|bank|akun|wallet|saldo|cashflow|arus\s+kas)\s+(?!baru\b|$)/i', '', trim($firstLine));

        // If the regex above didn't match (e.g. "hapus BCA" or "hapus rekening"),
        // try to just remove the command word at the beginning.
        if ($cleanText === trim($firstLine)) {
            $cleanText = preg_replace('/^(tambah|buat|add|hapus|delete|lihat|cek|daftar|saldo|cashflow|arus\s+kas)\s+/i', '', $cleanText);
        }

        // Also handle "cek saldo [nama]", "saldo [nama]", "cek cashflow [nama]"
        $cleanText = preg_replace('/^(cek\s+)?(saldo|cashflow|arus\s+kas)\s+/i', '', $cleanText);

        // Remove "dengan nama" if still present at start
        $cleanText = preg_replace('/^dengan\s+nama\s*/i', '', $cleanText);

        // Remove amount suffix if present - handles multiple formats:
        // - "saldo 100rb", "saldo 5jt", "100000", "5.165.856", "5,000,000"
        // First, try to match Indonesian format with dots as thousand separators (e.g., 5.165.856)
        $cleanText = preg_replace('/\s*(saldo\s*)?\d{1,3}(?:[\.,]\d{3})+\s*(rb|ribu|k|jt|juta)?\s*$/i', '', $cleanText);
        // Then match simple numbers with optional suffix (e.g., 100rb, 5jt, 50000)
        $cleanText = preg_replace('/\s*(saldo\s*)?\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta)?\s*$/i', '', $cleanText);

        // CLEANUP: If name starts with type keyword, but name ISN'T JUST that keyword, remove the keyword
        // e.g. "dompet BCA" -> "BCA" but "rekening" -> "rekening"
        $cleanText = preg_replace('/^(dompet|rekening|bank|akun|wallet)\s+(.+)/i', '$2', trim($cleanText));

        // Remove "baru" suffix/prefix
        $cleanText = preg_replace('/\s+baru\s*$/i', '', $cleanText);
        $cleanText = preg_replace('/^baru\s+/i', '', $cleanText);

        $cleanText = trim($cleanText);

        // If it's just "dompet" or "wallet", treat as null to trigger help/list
        // Juga abaikan kata kunci ringkasan/cashflow agar "Cek cashflow" tidak dianggap nama dompet
        $nonWalletKeywords = [
            'dompet', 'wallet',
            'cashflow', 'cash flow', 'arus kas', 'ringkasan', 'summary', 'statistik', 'kondisi', 'status',
        ];
        if (empty($cleanText) || in_array(strtolower($cleanText), $nonWalletKeywords)) {
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
    /**
     * Handle check balance request (cek saldo)
     *
     * MOVED FROM: ProcessIncomingMessage::handleCheckBalance()
     * LINES: 7724-7848
     * MODIFICATION: Added wallet filtering support
     */
    public function handleCheckBalance(?string $messageText = null): void
    {
        try {
            // Check if specific wallet is mentioned
            $specificWallet = null;
            if ($messageText && strtolower(trim($messageText)) !== 'saldo') {
                $specificWallet = $this->extractWalletName($messageText);

                // If extracted name is just "saldo", ignore it
                if ($specificWallet && strtolower($specificWallet) === 'saldo') {
                    $specificWallet = null;
                }
            }

            // Get balances (account balances)
            $balanceQuery = Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true);

            // 1. FOR SPECIFIC WALLET QUERY
            if ($specificWallet) {
                // Try exact match first
                $foundBalance = (clone $balanceQuery)
                    ->whereRaw('LOWER(account_name) = ?', [strtolower($specificWallet)])
                    ->first();

                // Then try partial match
                if (! $foundBalance) {
                    $foundBalance = (clone $balanceQuery)
                        ->whereRaw('LOWER(account_name) LIKE ?', ['%'.strtolower($specificWallet).'%'])
                        ->first();
                }

                if ($foundBalance) {
                    $amount = $foundBalance->balance ?? 0;
                    $amountFormatted = number_format($amount, 0, ',', '.');
                    $accountName = $foundBalance->account_name ?: ($foundBalance->name ?? 'Akun');

                    $this->sendReply(
                        "🏦 *Saldo Rekening*\n\n".
                        "👛 Dompet: *{$accountName}*\n".
                        "💰 Saldo: *Rp {$amountFormatted}*\n\n".
                        '📅 Per: '.now()->translatedFormat('d F Y H:i')
                    );

                    return;
                } else {
                    // Wallet mentioned but not found, keep going but maybe add a note later
                }
            }

            // 2. GET DATA FOR LIST/SUMMARY
            $balances = $balanceQuery->get();
            $totalBalance = 0;
            $balanceLines = '';
            foreach ($balances as $balance) {
                $amount = $balance->balance ?? 0;
                $totalBalance += $amount;
                $emoji = $amount >= 0 ? '💵' : '⚠️';
                $accountName = $balance->account_name ?: ($balance->name ?? 'Akun');
                $balanceLines .= "{$emoji} {$accountName}: Rp ".number_format($amount, 0, ',', '.')."\n";
            }

            // 3. DECIDE: BALANCE LIST OR FULL SUMMARY
            // If user mentioned specific wallet that wasn't found, OR user asked for "saldo"
            // Default to balance list if "saldo" is present, or if no specific cashflow keywords are present
            $isGeneralBalanceQuery = $messageText && preg_match('/\bsaldo\b/i', $messageText);
            $hasCashflowKeywords = $messageText && preg_match('/\b(cashflow|cash\s*flow|ringkasan|summary|statistik|kondisi|status|arus\s+kas)\b/i', $messageText);

            $wantsOnlyBalances = ($specificWallet && ! $foundBalance) ||
                                ($isGeneralBalanceQuery && ! $hasCashflowKeywords) ||
                                (empty($messageText)); // Default to list if called without text

            if ($wantsOnlyBalances) {
                $note = ($specificWallet && ! $foundBalance) ? "⚠️ Dompet *'{$specificWallet}'* tidak ditemukan.\n\n" : '';

                $this->sendReply(
                    "🏦 *Saldo Rekening Anda*\n".
                    '📅 Per: '.now()->translatedFormat('d F Y H:i')."\n".
                    "━━━━━━━━━━━━━━━\n\n".
                    $note.
                    $balanceLines.
                    "\n━━━━━━━━━━━━━━━\n".
                    '💰 *Total Saldo: Rp '.number_format($totalBalance, 0, ',', '.').'*'
                );

                return;
            }

            // 4. FULL SUMMARY MODE (default for "cashflow", "ringkasan", or general query)
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

            $reply = "💰 *Ringkasan Keuangan*\n";
            $reply .= '📅 '.now()->translatedFormat('F Y')."\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";

            // Cashflow section
            $reply .= "📊 *Cashflow Bulan Ini*\n";
            $reply .= '💵 Pendapatan: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
            $reply .= '💸 Pengeluaran: Rp '.number_format($totalExpense, 0, ',', '.')."\n";

            $netEmoji = $netCashflow >= 0 ? '📈' : '📉';
            $netLabel = $netCashflow >= 0 ? 'Surplus' : 'Defisit';
            $reply .= "{$netEmoji} {$netLabel}: Rp ".number_format(abs($netCashflow), 0, ',', '.')."\n\n";

            // Top Categories (Income/Expense combined or separate as before)
            // ... (keeping same logic but slightly more compact)

            // Top Expense Categories
            $expenseTransactions = $transactions->where('type', 'expense');
            if ($expenseTransactions->isNotEmpty()) {
                $topExpense = $expenseTransactions
                    ->groupBy('category_id')
                    ->map(fn ($group) => ['name' => $group->first()->category->name ?? 'Lainnya', 'total' => $group->sum('amount')])
                    ->sortByDesc('total')->take(3);

                $reply .= "💸 *Top Pengeluaran:*\n";
                foreach ($topExpense as $item) {
                    $reply .= "  • {$item['name']}: Rp ".number_format($item['total'], 0, ',', '.')."\n";
                }
                $reply .= "\n";
            }

            // Account balances section
            if ($balances->isNotEmpty()) {
                $reply .= "🏦 *Saldo Rekening*\n";
                $reply .= $balanceLines;
                $reply .= "\n━━━━━━━━━━━━━━━\n";
                $reply .= '💰 *Total Saldo: Rp '.number_format($totalBalance, 0, ',', '.')."*\n";
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal memuat saldo*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle transfer between wallets
     * e.g., "transfer 100rb dari BCA ke Mandiri", "pindah saldo 50rb dari Cash ke Gopay"
     */
    public function handleTransferBetweenWallets(): void
    {
        $messageText = $this->message->content;

        // Regex patterns for transfer:
        // 1. "transfer [nominal] dari [sumber] ke [tujuan]"
        // 2. "pindah [nominal] dari [sumber] ke [tujuan]"
        // 3. "kirim [nominal] ke [tujuan] dari [sumber]"

        $patterns = [
            // 0: transfer 100rb dari BCA ke Mandiri
            '/(?:trans[pf]er|tf|trf|pindah(?:kan)?|kirim)\s+(?:dana|saldo|uang\s+)?([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(?:dari\s+)?([a-zA-Z0-9\s]+?)\s+(?:ke\s+)([a-zA-Z0-9\s]+)/i',
            // 1: transfer dari BCA ke Mandiri 100rb
            '/(?:trans[pf]er|tf|trf|pindah(?:kan)?|kirim)\s+(?:dana|saldo|uang\s+)?(?:dari\s+)?([a-zA-Z0-9\s]+?)\s+(?:ke\s+)([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
            // 2: Pindahkan saldo bank BRI ke bank jago 300 rb (sumber + nominal + ke tujuan)
            '/(?:trans[pf]er|tf|trf|pindah(?:kan)?|kirim)\s+(?:dana|saldo|uang\s+)?(?:dari\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)\s+(?:ke\s+)([a-zA-Z0-9\s]+)/i',
        ];

        $amount = null;
        $fromWalletName = null;
        $toWalletName = null;

        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                if ($index === 0) {
                    $amount = $this->extractAmountFromText($matches[1]);
                    $fromWalletName = trim($matches[2]);
                    $toWalletName = trim($matches[3]);
                } elseif ($index === 1) {
                    $fromWalletName = trim($matches[1]);
                    $toWalletName = trim($matches[2]);
                    $amount = $this->extractAmountFromText($matches[3]);
                } elseif ($index === 2) {
                    $fromWalletName = trim($matches[1]);
                    $amount = $this->extractAmountFromText($matches[2]);
                    $toWalletName = trim($matches[3]);
                }
                break;
            }
        }

        if (! $amount || $amount <= 0 || ! $fromWalletName || ! $toWalletName) {
            $this->sendReply(
                "⚠️ *Format Transfer Tidak Dikenali*\n\n".
                "Contoh format yang benar:\n".
                "• _transfer 100rb dari BCA ke Mandiri_\n".
                '• _pindah saldo 50rb dari Cash ke Gopay_'
            );

            return;
        }

        try {
            $balanceService = app(BalanceService::class);

            // 1. Find Source Wallet
            $fromWallet = $balanceService->findOrCreateBalance($this->message->tenant_id, $fromWalletName, null);
            if (! $fromWallet) {
                $this->sendReply("⚠️ Dompet sumber *'{$fromWalletName}'* tidak ditemukan.");

                return;
            }

            // 2. Find Destination Wallet
            $toWallet = $balanceService->findOrCreateBalance($this->message->tenant_id, $toWalletName, null);
            if (! $toWallet) {
                $this->sendReply("⚠️ Dompet tujuan *'{$toWalletName}'* tidak ditemukan.");

                return;
            }

            if ($fromWallet->id === $toWallet->id) {
                $this->sendReply('⚠️ Dompet sumber dan tujuan tidak boleh sama.');

                return;
            }

            $fromWallet->refresh();
            $available = (float) $fromWallet->balance;
            $availableCents = (int) round($available * 100);
            $amountCents = (int) round($amount * 100);
            if ($amountCents > $availableCents) {
                $shortfall = max(0, $amount - $available);
                $this->sendReply(
                    "⚠️ *Saldo tidak cukup*\n\n".
                    "Saldo Anda di *{$fromWallet->account_name}* saat ini ".
                    '*Rp '.number_format($available, 0, ',', '.')."*.\n\n".
                    'Nominal yang ingin dipindahkan: *Rp '.number_format($amount, 0, ',', '.')."*\n".
                    'Kurang: *Rp '.number_format($shortfall, 0, ',', '.')."*\n\n".
                    'Kurangi nominal transfer atau tambah saldo di dompet sumber terlebih dahulu.'
                );

                return;
            }

            // Generate a unique reference for this transfer to link the two transactions
            $transferRef = 'TRF-'.strtoupper(substr(md5(uniqid()), 0, 8));

            // 3. Create Expense Transaction (from Source)
            $expenseTxData = [
                'type' => 'debit_internal', // Changed from expense
                'amount' => $amount,
                'category' => 'Debit Antar Dompet',
                'category_type' => 'debit_internal',
                'description' => "Transfer ke {$toWallet->account_name} ({$transferRef})",
                'account_name' => $fromWallet->account_name,
                'transaction_date' => now()->toDateString(),
                'confidence_score' => 1.0,
                'source' => 'wallet_command',
                'metadata' => [
                    'transfer_ref' => $transferRef,
                    'destination_wallet_id' => $toWallet->id,
                    'is_transfer' => true,
                ],
            ];

            $expenseTx = $this->createTransaction($expenseTxData);

            // 4. Create Income Transaction (to Destination)
            $incomeTxData = [
                'type' => 'kredit_internal', // Changed from income
                'amount' => $amount,
                'category' => 'Kredit Antar Dompet',
                'category_type' => 'kredit_internal',
                'description' => "Transfer dari {$fromWallet->account_name} ({$transferRef})",
                'account_name' => $toWallet->account_name,
                'transaction_date' => now()->toDateString(),
                'confidence_score' => 1.0,
                'source' => 'wallet_command',
                'metadata' => [
                    'transfer_ref' => $transferRef,
                    'source_wallet_id' => $fromWallet->id,
                    'is_transfer' => true,
                ],
            ];

            $incomeTx = $this->createTransaction($incomeTxData);

            if ($expenseTx && $incomeTx) {
                $this->sendReply(
                    "✅ *Transfer Berhasil!*\n\n".
                    '💸 *Nominal:* Rp '.number_format($amount, 0, ',', '.')."\n".
                    "📤 *Dari:* {$fromWallet->account_name}\n".
                    "📥 *Ke:* {$toWallet->account_name}\n\n".
                    "📌 *Ref:* `{$transferRef}`\n".
                    "━━━━━━━━━━━━━━━\n".
                    "💰 *Saldo Sekarang:*\n".
                    "• {$fromWallet->account_name}: Rp ".number_format($fromWallet->fresh()->balance, 0, ',', '.')."\n".
                    "• {$toWallet->account_name}: Rp ".number_format($toWallet->fresh()->balance, 0, ',', '.')
                );
            } else {
                $this->sendReply('⚠️ Terjadi kesalahan saat memproses transfer.');
            }

        } catch (\Exception $e) {
            Log::error('Error processing transfer', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
            $this->sendReply('⚠️ Gagal memproses transfer: '.$e->getMessage());
        }
    }

    /**
     * Handle setting a wallet as default/primary
     * e.g., "jadikan BRI dompet utama", "set Jago jadi dompet utama"
     */
    public function handleSetDefaultWallet(string $messageText): void
    {
        try {
            $textLower = strtolower(trim($messageText));

            // Patterns:
            // 1. jadikan [nama] dompet utama
            // 2. set [nama] jadi dompet utama
            // 3. ganti dompet utama ke [nama]
            $walletName = null;
            if (preg_match('/(?:jadikan|set|pilih|ubah|ganti)\s+(.*?)\s+(?:jadi|sebagai|menjadi|ke)?\s*dompet\s+utama/i', $messageText, $matches)) {
                $walletName = trim($matches[1]);
            } elseif (preg_match('/dompet\s+utama\s+(?:ke|jadi|adalah|set)\s+(.*)/i', $messageText, $matches)) {
                $walletName = trim($matches[1]);
            }

            if (! $walletName) {
                $this->sendReply(
                    "⚠️ *Format tidak dikenali*\n\n".
                    'Contoh: _jadikan BRI dompet utama_'
                );

                return;
            }

            // Find the wallet
            $balance = Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->whereRaw('LOWER(account_name) = ?', [strtolower($walletName)])
                ->first();

            if (! $balance) {
                $balance = Balance::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->whereRaw('LOWER(account_name) LIKE ?', ['%'.strtolower($walletName).'%'])
                    ->orderByRaw('LENGTH(account_name) ASC')
                    ->first();
            }

            if (! $balance) {
                $this->sendReply("⚠️ Dompet *'{$walletName}'* tidak ditemukan.");

                return;
            }

            // Perform the update: Set all others to false, this one to true
            Balance::where('tenant_id', $this->message->tenant_id)
                ->update(['is_default' => false]);

            $balance->is_default = true;
            $balance->save();

            Log::info('Primary wallet updated', [
                'tenant_id' => $this->message->tenant_id,
                'wallet_id' => $balance->id,
                'wallet_name' => $balance->account_name,
            ]);

            $this->sendReply(
                "⭐ *Dompet Utama Berhasil Diubah!* ✅\n\n".
                "Sekarang *{$balance->account_name}* adalah dompet utama Anda.\n\n".
                'Setiap transaksi tanpa menyebutkan nama dompet akan otomatis dicatat ke sini.'
            );

        } catch (\Exception $e) {
            Log::error('Error setting default wallet', ['error' => $e->getMessage()]);
            $this->sendReply('⚠️ Gagal mengubah dompet utama. Silakan coba lagi.');
        }
    }

    /**
     * View specific wallet balance by name
     */
    public function handleViewSpecificWallet(string $walletName): void
    {
        try {
            $wallet = Balance::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->whereRaw('LOWER(account_name) LIKE ?', ['%'.strtolower($walletName).'%'])
                ->first();

            if (! $wallet) {
                $this->sendReply(
                    "⚠️ *Dompet tidak ditemukan*\n\n".
                    "Tidak ada dompet dengan nama \"*{$walletName}*\".\n\n".
                    '💡 Ketik _lihat dompet_ untuk melihat daftar dompet Anda.'
                );

                return;
            }

            $balance = number_format($wallet->balance, 0, ',', '.');
            $icon = $wallet->icon ?? '💰';

            $this->sendReply(
                "{$icon} *Saldo {$wallet->account_name}*\n".
                "━━━━━━━━━━━━━━━\n\n".
                "💵 *Rp {$balance}*"
            );

        } catch (\Exception $e) {
            Log::error('Error viewing specific wallet', [
                'wallet_name' => $walletName,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mengambil saldo*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
            );
        }
    }
}
