<?php

namespace App\Services;

/**
 * Normalisasi slang/kata singkat dalam pesan pengguna.
 *
 * Mengubah kata singkat atau tidak baku menjadi bentuk standar
 * agar deteksi transaksi lebih akurat.
 */
class KeywordNormalizer
{
    /**
     * Pemetaan slang → bentuk standar (lowercase).
     */
    private static array $slangMap = [
        // Kata yang berarti "dapat/menerima"
        'dpt' => 'dapat',
        'dk' => 'dapat',

        // Kata yang berarti "transfer"
        'trf' => 'transfer',
        'tf' => 'transfer',

        // Kata yang berarti "makan" (expense)
        'mkn' => 'makan',
        'maem' => 'makan',
        'mamam' => 'makan',
        'brunch' => 'makan',

        // Kata pendukung
        'nya' => '',  // hapus "nya" untuk simplify
        'banget' => 'sangat',
        'ya' => 'ya',
        'gih' => 'bagus',
        'gila' => 'sangat',

        // Uang/keuangan
        'k' => 'ribu',
        'rb' => 'ribu',
        'lt' => 'lebih',
    ];

    /**
     * Normalisasi teks pesan dengan mengganti slang dengan bentuk standar.
     */
    public static function normalize(string $text): string
    {
        // Lowercase untuk konsistensi
        $text = mb_strtolower($text);

        // Ganti slang dengan bentuk standar (case-insensitive word boundary)
        foreach (self::$slangMap as $slang => $replacement) {
            $pattern = '/\b' . preg_quote($slang, '/') . '\b/u';
            $text = preg_replace($pattern, $replacement, $text);
        }

        // Normalisasi spasi berlebih
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Dapatkan semua slang yang didukung.
     */
    public static function getSlangMap(): array
    {
        return self::$slangMap;
    }

    /**
     * Cek apakah teks mengandung slang tertentu.
     */
    public static function containsSlang(string $text, string $slang): bool
    {
        $text = mb_strtolower($text);
        $pattern = '/\b' . preg_quote($slang, '/') . '\b/u';
        return preg_match($pattern, $text) === 1;
    }
}