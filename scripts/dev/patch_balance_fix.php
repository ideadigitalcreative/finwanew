<?php

/**
 * Patch script to fix the "Saldo [nominal]" issue correctly.
 * This version uses relative paths and robust string matching to work on both local and VPS.
 */
function patchProcessIncomingMessage()
{
    $filePath = 'app/Jobs/ProcessIncomingMessage.php';
    if (! file_exists($filePath)) {
        echo "File not found: $filePath\n";

        return;
    }

    $content = file_get_contents($filePath);

    // Check if already patched
    if (str_contains($content, 'Saldo 400 ribu')) {
        echo "ProcessIncomingMessage.php already patched.\n";

        return;
    }

    // Add the pattern to the beginning of the list
    $oldLine = '$setBalancePatterns = [';
    $newLine = '$setBalancePatterns = ['."\n".'            \'/^saldo\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)$/i\', // Direct format: "Saldo 400 ribu"';

    $newContent = str_replace($oldLine, $newLine, $content);

    if ($newContent !== $content) {
        file_put_contents($filePath, $newContent);
        echo "ProcessIncomingMessage.php patched successfully!\n";
    } else {
        echo "Could not find pattern in ProcessIncomingMessage.php\n";
    }
}

function patchWalletCommandService()
{
    $filePath = 'app/Services/Wallet/WalletCommandService.php';
    if (! file_exists($filePath)) {
        echo "File not found: $filePath\n";

        return;
    }

    $content = file_get_contents($filePath);

    // We will replace the WHOLE handleSetWalletBalance method to ensure it's clean and has the new logic.
    // We use a regex that matches from the public function line until the next method or end of class
    $methodRegex = '/public function handleSetWalletBalance\(string \$messageText\): void\s+\{.*?\s+public function/s';

    $newMethod = "public function handleSetWalletBalance(string \$messageText): void
    {
        try {
            // SPECIAL CASE 1: \"Update saldo jadi 5juta\" - no wallet name, just amount
            // Use last created wallet or default wallet
            if (preg_match('/^(?:update|set|ubah|ganti)\s+saldo\s+(?:jadi|menjadi|ke|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)$/i', trim(\$messageText), \$shortMatch)) {
                \$amountStr = trim(\$shortMatch[1]);
                \$newAmount = \$this->extractAmountFromText(\$amountStr);
                
                if (\$newAmount !== null) {
                    // Find last created or default wallet
                    \$balance = \\App\\Models\\Balance::where('tenant_id', \$this->message->tenant_id)
                        ->where('is_active', true)
                        ->orderBy('created_at', 'desc')
                        ->first() ?? \\App\\Models\\Balance::where('tenant_id', \$this->message->tenant_id)
                        ->where('is_default', true)
                        ->first();
                    
                    if (\$balance) {
                        // Update the balance
                        \$oldAmount = \$balance->balance ?? 0;
                        \$difference = \$newAmount - \$oldAmount;
                        \$balance->balance = \$newAmount;
                        \$balance->save();
                        
                        \$oldFormatted = number_format(\$oldAmount, 0, ',', '.');
                        \$newFormatted = number_format(\$newAmount, 0, ',', '.');
                        \$diffFormatted = number_format(abs(\$difference), 0, ',', '.');
                        \$diffSign = \$difference >= 0 ? '+' : '-';
                        
                        \$this->sendReply(
                            \"✏️ *Saldo Berhasil Diubah!* ✅\\n\\n\" .
                            \"👛 Dompet: *{\$balance->account_name}*\\n\\n\" .
                            \"📊 Perubahan Saldo:\\n\" .
                            \"   Sebelum: Rp {\$oldFormatted}\\n\" .
                            \"   Sesudah: *Rp {\$newFormatted}*\\n\" .
                            \"   Selisih: {\$diffSign}Rp {\$diffFormatted}\\n\\n\" .
                            \"📅 Diubah: \" . now()->translatedFormat('d F Y H:i')
                        );
                        return;
                    }
                }
            }

            // SPECIAL CASE 2: \"Saldo 400 ribu\" (direct format)
            if (preg_match('/^saldo\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?)$/i', trim(\$messageText), \$simpleMatch)) {
                \$amountStr = trim(\$simpleMatch[1]);
                \$newAmount = \$this->extractAmountFromText(\$amountStr);
                
                if (\$newAmount !== null) {
                    \$balance = \\App\\Models\\Balance::where('tenant_id', \$this->message->tenant_id)
                        ->where('is_default', true)
                        ->first() ?? \\App\\Models\\Balance::where('tenant_id', \$this->message->tenant_id)
                        ->where('is_active', true)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    
                    if (\$balance) {
                        \$oldAmount = \$balance->balance ?? 0;
                        \$difference = \$newAmount - \$oldAmount;
                        \$balance->balance = \$newAmount;
                        \$balance->save();
                        
                        \$oldFormatted = number_format(\$oldAmount, 0, ',', '.');
                        \$newFormatted = number_format(\$newAmount, 0, ',', '.');
                        \$diffFormatted = number_format(abs(\$difference), 0, ',', '.');
                        \$diffSign = \$difference >= 0 ? '+' : '-';
                        
                        \$this->sendReply(
                            \"✏️ *Saldo Berhasil Diubah!* ✅\\n\\n\" .
                            \"👛 Dompet: *{\$balance->account_name}*\\n\\n\" .
                            \"📊 Perubahan Saldo:\\n\" .
                            \"   Sebelum: Rp {\$oldFormatted}\\n\" .
                            \"   Sesudah: *Rp {\$newFormatted}*\\n\" .
                            \"   Selisih: {\$diffSign}Rp {\$diffFormatted}\\n\\n\" .
                            \"📅 Diubah: \" . now()->translatedFormat('d F Y H:i')
                        );
                        return;
                    }
                }
            }
            
            // Patterns to extract wallet name and amount
            \$patterns = [
                '/(?:set|atur|ubah|edit|ganti)\s+saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke|=)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s*=\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/(?:update|koreksi)\s+saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+(?:menjadi|jadi|ke)?\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+(?:sekarang|jadi|menjadi)\s*([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)/i',
                '/(?:update|set|atur|ubah|edit|ganti)\s+saldo\s+(?:dompet\s+|akun\s+|bank\s+|rekening\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta)?)$/i',
                '/^saldo\s+(?:dompet\s+)?([a-zA-Z0-9\s]+?)\s+([\d\.,]+\s*(?:rb|ribu|k|jt|juta|m|million)?)/i',
            ];
            
            \$walletName = null;
            \$amountStr = null;
            
            foreach (\$patterns as \$pattern) {
                if (preg_match(\$pattern, \$messageText, \$matches)) {
                    \$walletName = trim(\$matches[1]);
                    \$amountStr = trim(\$matches[2]);
                    break;
                }
            }
            
            if (!\$walletName) {
                \$this->sendReply(
                    \"⚠️ *Format tidak dikenali*\\n\\n\" .
                    \"Contoh format yang benar:\\n\" .
                    \"• _set saldo BCA menjadi 100rb_\\n\" .
                    \"• _ubah saldo Gopay jadi 50.000_\\n\" .
                    \"• _saldo Dana = 1jt_\\n\" .
                    \"• _ganti saldo O ke 49.000_\\n\" .
                    \"• _koreksi saldo Cash 25rb_\"
                );
                return;
            }
            
            // Extract amount
            \$newAmount = \$this->extractAmountFromText(\$amountStr ?? '');
            
            if (\$newAmount === null) {
                \$this->sendReply(
                    \"⚠️ *Nominal tidak terdeteksi*\\n\\n\" .
                    \"Pastikan format nominal benar:\\n\" .
                    \"• 100rb, 100.000, 100k\\n\" .
                    \"• 1jt, 1.000.000\\n\\n\" .
                    \"Contoh: _set saldo BCA menjadi 100rb_\"
                );
                return;
            }
            
            // Find the wallet
            \$balance = \\App\\Models\\Balance::where('tenant_id', \$this->message->tenant_id)
                ->where('is_active', true)
                ->whereRaw('LOWER(account_name) = ?', [strtolower(\$walletName)])
                ->first();
            
            if (!\$balance) {
                \$balance = \\App\\Models\\Balance::where('tenant_id', \$this->message->tenant_id)
                    ->where('is_active', true)
                    ->whereRaw('LOWER(account_name) LIKE ?', ['%' . strtolower(\$walletName) . '%'])
                    ->orderByRaw('LENGTH(account_name) ASC')
                    ->first();
            }
            
            if (!\$balance) {
                \$availableWallets = \\App\\Models\\Balance::where('tenant_id', \$this->message->tenant_id)
                    ->pluck('account_name')
                    ->toArray();
                
                \$walletList = empty(\$availableWallets) 
                    ? 'Belum ada dompet. Buat dengan: _tambah dompet BCA_'
                    : implode(', ', \$availableWallets);
                
                \$this->sendReply(
                    \"⚠️ *Dompet '{\$walletName}' tidak ditemukan*\\n\\n\" .
                    \"Dompet tersedia:\\n{\$walletList}\\n\\n\" .
                    \"Atau buat dompet baru: _tambah dompet {\$walletName}_\"
                );
                return;
            }
            
            \$oldAmount = \$balance->balance ?? 0;
            \$difference = \$newAmount - \$oldAmount;
            \$balance->balance = \$newAmount;
            \$balance->save();
            
            \$oldFormatted = number_format(\$oldAmount, 0, ',', '.');
            \$newFormatted = number_format(\$newAmount, 0, ',', '.');
            \$diffFormatted = number_format(abs(\$difference), 0, ',', '.');
            \$diffSign = \$difference >= 0 ? '+' : '-';
            
            \$this->sendReply(
                \"✏️ *Saldo Berhasil Diubah!* ✅\\n\\n\" .
                \"👛 Dompet: *{\$balance->account_name}*\\n\\n\" .
                \"📊 Perubahan Saldo:\\n\" .
                \"   Sebelum: Rp {\$oldFormatted}\\n\" .
                \"   Sesudah: *Rp {\$newFormatted}*\\n\" .
                \"   Selisih: {\$diffSign}Rp {\$diffFormatted}\\n\\n\" .
                \"📅 Diubah: \" . now()->translatedFormat('d F Y H:i')
            );
            
        } catch (\\Exception \$e) {
            \\Log::error('Error setting wallet balance', [
                'message_id' => \$this->message->id,
                'error' => \$e->getMessage()
            ]);
            
            \$this->sendReply(
                \"⚠️ *Gagal mengubah saldo*\\n\\n\" .
                \"Terjadi kesalahan. Silakan coba lagi nanti.\"
            );
        }
    }

    public function"; // The regex will replace until the next public function

    $newContent = preg_replace($methodRegex, $newMethod, $content);

    if ($newContent !== null && $newContent !== $content) {
        file_put_contents($filePath, $newContent);
        echo "WalletCommandService.php patched successfully!\n";
    } else {
        echo "Could not patch WalletCommandService.php (Pattern not found or already patched)\n";
    }
}

patchProcessIncomingMessage();
patchWalletCommandService();
