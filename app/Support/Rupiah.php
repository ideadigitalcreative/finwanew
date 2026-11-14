<?php

namespace App\Support;

/**
 * Normalisasi nominal Rupiah dari keluaran AI / teks bebas ke integer.
 *
 * (int) "Rp 25.000" = 0 dan (int) "25.000" = 25 — itu yang dulu membuat struk
 * tercatat Rp 0 / Rp 25. Helper ini memahami format Indonesia & internasional.
 */
final class Rupiah
{
    /**
     * @return int|null null bila tidak ada angka yang bisa dibaca
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (! is_string($value)) {
            return null;
        }

        $s = mb_strtolower(trim($value));
        if ($s === '') {
            return null;
        }

        $negative = str_starts_with(ltrim($s), '-');
        $s = preg_replace('/\b(?:rp|idr)\.?/u', '', $s);
        $s = str_replace([' ', "\u{00A0}", '-'], '', $s);

        // Satuan singkat: 25rb, 50k, 1,5jt, 2juta
        if (preg_match('/^(\d+(?:[.,]\d+)?)(rb|ribu|rebu|k|jt|juta)$/u', $s, $m)) {
            $num = (float) str_replace(',', '.', $m[1]);
            $mult = in_array($m[2], ['jt', 'juta'], true) ? 1_000_000 : 1_000;

            return self::sign((int) round($num * $mult), $negative);
        }

        // Pemisah ribuan: 25.000 | 1.250.000 | 25,000 | 1,250,000 (opsional desimal 2 digit)
        if (preg_match('/^\d{1,3}(?:([.,])\d{3})+(?:([.,])(\d{1,2}))?$/', $s, $m)) {
            $thousandSep = $m[1];
            $decimalSep = $m[2] ?? '';
            if ($decimalSep !== '' && $decimalSep === $thousandSep) {
                // "1.250.00" ambigu → anggap semua pemisah ribuan
                return self::sign((int) preg_replace('/\D/', '', $s), $negative);
            }
            $intPart = $decimalSep !== '' ? substr($s, 0, strrpos($s, $decimalSep)) : $s;

            return self::sign((int) preg_replace('/\D/', '', $intPart), $negative);
        }

        // Angka polos / desimal: 25000 | 25000.50 | 25000,5
        if (preg_match('/^\d+(?:[.,]\d+)?$/', $s)) {
            return self::sign((int) round((float) str_replace(',', '.', $s)), $negative);
        }

        // Teks campuran ("Total: Rp25.000,-") → ambil kelompok angka pertama
        if (preg_match('/\d[\d.,]*/', $s, $m) && $m[0] !== $s) {
            return self::parse(rtrim($m[0], '.,'));
        }

        return null;
    }

    private static function sign(int $v, bool $negative): int
    {
        return $negative ? -$v : $v;
    }
}
