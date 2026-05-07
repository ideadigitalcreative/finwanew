<?php

namespace App\Helpers;

class KeywordNormalizer
{
    /**
     * Normalize user input to standard keywords
     *
     * @param  string  $input  User's message
     * @return string Normalized message
     */
    public static function normalize(string $input): string
    {
        $inputLower = strtolower(trim($input));

        // Keyword mappings: variations => standard command
        $mappings = [
            // Cashflow queries
            'arus kas' => 'cek cashflow',
            'cash flow' => 'cek cashflow',
            'ringkasan keuangan' => 'cek cashflow',

            // Wallet/Dompet queries
            'lihat dompet' => 'cek dompet',
            'dompet saya' => 'cek dompet',
            'list dompet' => 'cek dompet',
            'daftar dompet' => 'cek dompet',

            // Transaction queries
            'lihat transaksi' => 'cek transaksi',
            'daftar transaksi' => 'cek transaksi',
            'riwayat transaksi' => 'cek transaksi',
            'history transaksi' => 'cek transaksi',

            // Delete commands
            'hapus transaksi' => 'hapus transaksi terakhir',
            'delete transaksi' => 'hapus transaksi terakhir',
            'batalkan transaksi' => 'hapus transaksi terakhir',
            'undo transaksi' => 'hapus transaksi terakhir',

            // Help commands
            'bantuan' => 'help',
            'tolong' => 'help',
            'gimana' => 'help',
            'cara pakai' => 'help',
            'cara menggunakan' => 'help',
            'panduan' => 'help',

            // Registration
            'register' => 'daftar',
            'sign up' => 'daftar',
            'buat akun' => 'daftar',

            // Statistics
            'statistik' => 'cek statistik',
            'laporan' => 'cek statistik',
            'report' => 'cek statistik',
            // Account reset
            'reset' => 'reset akun',
        ];
        // Check exact matches first
        if (isset($mappings[$inputLower])) {
            return $mappings[$inputLower];
        }

        // Check partial matches (contains)
        foreach ($mappings as $variation => $standard) {
            if (stripos($inputLower, $variation) !== false) {
                // Replace the variation with standard in the original input
                return str_ireplace($variation, $standard, $input);
            }
        }

        // No match found, return original
        return $input;
    }

    /**
     * Normalize wallet/account names
     *
     * @param  string  $walletName  User's wallet name input
     * @return string Normalized wallet name
     */
    public static function normalizeWalletName(string $walletName): string
    {
        $walletLower = strtolower(trim($walletName));

        // Wallet name mappings
        $walletMappings = [
            // Common typos
            'goPay' => 'Gopay',
            'go pay' => 'Gopay',
            'go-pay' => 'Gopay',

            'OVO' => 'Ovo',
            'o v o' => 'Ovo',

            'DANA' => 'Dana',
            'd a n a' => 'Dana',

            'LinkAja' => 'Linkaja',
            'link aja' => 'Linkaja',
            'link-aja' => 'Linkaja',

            // Bank names
            'bank bca' => 'BCA',
            'bank mandiri' => 'Mandiri',
            'bank bni' => 'BNI',
            'bank bri' => 'BRI',

            // Cash variations
            'tunai' => 'Cash',
            'uang tunai' => 'Cash',
            'kas' => 'Cash',

            // Default wallet
            'dompet utama' => 'Dompet Utama',
            'kantong utama' => 'Dompet Utama',
            'wallet utama' => 'Dompet Utama',
        ];

        // Check exact matches
        if (isset($walletMappings[$walletLower])) {
            return $walletMappings[$walletLower];
        }

        // Return capitalized version
        return ucfirst($walletLower);
    }

    /**
     * Suggest corrections for unknown commands
     *
     * @param  string  $input  User's input
     * @return array|null Suggestion if found
     */
    public static function suggestCorrection(string $input): ?array
    {
        $inputLower = strtolower(trim($input));

        $knownCommands = [
            'cek cashflow',
            'cek dompet',
            'cek transaksi',
            'cek statistik',
            'hapus transaksi terakhir',
            'hapus semua transaksi',
            'help',
            'daftar',
        ];

        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($knownCommands as $command) {
            similar_text($inputLower, $command, $similarity);

            if ($similarity > $bestSimilarity && $similarity > 60) {
                $bestSimilarity = $similarity;
                $bestMatch = $command;
            }
        }

        if ($bestMatch) {
            return [
                'suggestion' => $bestMatch,
                'similarity' => $bestSimilarity,
            ];
        }

        return null;
    }
}
