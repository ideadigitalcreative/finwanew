<?php

namespace App\Services\DebtReceivable;

/**
 * Ekstraksi nama lawan transaksi (counterparty) dari teks bahasa Indonesia.
 * Dipakai untuk transaksi hutang/piutang; hasil disimpan di transactions.metadata.
 */
class CounterpartyExtractor
{
    /**
     * @return non-empty-string|null
     */
    public static function extract(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        $lookaheadAmount = '(?=\s+(?:\d|rp|k|rb|jt|juta|ribu|milyar|miliar|,|\.|$))';

        // Urutan: pola panjang / spesifik dulu (hindari salah potong)
        $patterns = [
            '/\bterima\s+pelunasan\s+([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            '/\bpelunasan\s+piutang\s+([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            // "terima piutang budi 150rb" — jangan match jika langsung "dari" (biar pola dari yang ambil)
            '/\bterima\s+piutang\s+(?!dari\b)([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            '/\b(?:dari|dr)\s+([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            '/\bke\s+([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            '/\bsama\s+([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
        ];

        foreach ($patterns as $re) {
            if (preg_match($re, $t, $m)) {
                $name = self::sanitizeName($m[1] ?? '');
                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function sanitizeName(string $raw): ?string
    {
        $name = trim($raw);
        // Buang kata sambung / noise di akhir
        $name = preg_replace('/\s+(buat|karena|untuk|dari|ke)\s*$/iu', '', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B.,;:-");

        if ($name === '' || mb_strlen($name) < 2) {
            return null;
        }

        if (mb_strlen($name) > 80) {
            $name = mb_substr($name, 0, 80);
        }

        // Hindari "yang" saja / angka murni
        if (preg_match('/^\d+$/', $name)) {
            return null;
        }

        return $name;
    }
}
