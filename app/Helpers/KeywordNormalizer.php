<?php

namespace App\Helpers;

class KeywordNormalizer
{
    /**
     * Buang nominal dari akhir deskripsi untuk ditampilkan di pesan balasan.
     *
     * Nominal sudah tampil di baris terpisah ("💵 Rp 22.000"), jadi baris
     * keterangan cukup "ganti oli motor", bukan "ganti oli motor 22k".
     * Hanya untuk tampilan — deskripsi di database tidak diubah.
     *
     * Contoh:
     *   "makan siang 10 rb"   → "makan siang"
     *   "ganti oli motor 22k" → "ganti oli motor"
     *   "beli beras 5 kg"     → "beli beras 5 kg" (satuan, bukan nominal)
     */
    public static function stripAmountFromDescription(?string $description): string
    {
        $desc = trim((string) $description);
        if ($desc === '') {
            return $desc;
        }

        $clean = (string) preg_replace(
            '/\s*\b(?:rp\.?\s*)?\d+(?:[.,]\d+)?\s*(?:rb|ribu|rebu|k|jt|juta)?\s*$/iu',
            '',
            $desc
        );
        $clean = rtrim($clean, " \t-–—:,.");

        return $clean !== '' ? $clean : $desc;
    }

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
        // 1) Cocok PERSIS dengan seluruh pesan (abaikan tanda baca di ujung):
        //    "bantuan" → "help", "reset" → "reset akun", "hapus transaksi" → "hapus transaksi terakhir".
        $inputBare = rtrim($inputLower, " 	?!.,");
        if (isset($mappings[$inputBare])) {
            return $mappings[$inputBare];
        }

        // 2) Hanya sinonim "lihat" yang aman diganti bila ada di AWAL pesan dan
        //    diikuti kata lain ("lihat transaksi september" → "cek transaksi september").
        //    Mapping lain TIDAK lagi diganti di tengah kalimat — dulu substring replace
        //    merusak pesan, mis.:
        //      "hapus transaksi makan siang" → "hapus transaksi terakhir makan siang"
        //      "gimana kondisi keuangan"    → "help kondisi keuangan"
        //      "download laporan"           → "download cek statistik"
        //      "preset"                     → "preset akun"
        $prefixSafe = [
            'lihat transaksi', 'daftar transaksi', 'riwayat transaksi', 'history transaksi',
            'lihat dompet', 'list dompet', 'daftar dompet', 'dompet saya',
        ];
        foreach ($prefixSafe as $variation) {
            if (preg_match('/^'.preg_quote($variation, '/').'\b/iu', $inputLower)) {
                return $mappings[$variation].mb_substr(trim($input), mb_strlen($variation));
            }
        }

        // 3) Variasi kata kerja "tambah dompet" di AWAL pesan → bentuk baku, nama dompet utuh:
        //    "Tambahkan dompet seabank", "tambahin rekening BCA", "bikin e-wallet Gopay",
        //    "mau tambah dompet Jago" → "tambah dompet …". Dulu hanya "tambah dompet" persis
        //    yang dikenali; "Tambahkan dompet seabank" dibalas sapaan.
        $addWallet = '/^(?:(?:tolong|mohon|coba|minta|mau|ingin|pengen|saya\s+mau|aku\s+mau)\s+)?'
            .'(?:tambahkan|tambahin|tambahi|nambahin|nambah|menambahkan|menambah|tambah|buatkan|buatin|bikinin|bikinkan|bikin|membuat|buat|daftarkan|daftarin)\s+'
            .'(?:(?:1|satu|sebuah)\s+)?(dompet|rekening|e-?wallet|wallet|akun\s+bank|bank)\b/iu';
        if (preg_match($addWallet, trim($input), $m)) {
            $noun = match (true) {
                (bool) preg_match('/wallet/i', $m[1]) => 'dompet',
                (bool) preg_match('/^akun\s+bank$/i', $m[1]) => 'bank',
                default => mb_strtolower($m[1]),
            };

            return 'tambah '.$noun.mb_substr(trim($input), mb_strlen($m[0]));
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
