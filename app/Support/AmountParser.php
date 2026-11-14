<?php

namespace App\Support;

use App\Helpers\BatchTransactionHelper;

/**
 * Satu pintu pembaca nominal dari teks bebas.
 *
 * Semua jalur (pencatatan, batch, edit/tanya-balik, reply-edit) memakai kelas ini
 * supaya pesan yang sama selalu menghasilkan nominal yang sama:
 *   1. normalize()  — angka dalam kata ("seratus ribu" → 100000), buang nomor HP
 *   2. composite()  — satu transaksi dengan 2 angka ("100rb kembalian 20rb" → 80rb)
 *   3. extract()    — nominal tunggal (aturan BatchTransactionHelper)
 *   4. parseAnswer()— jawaban singkat ("150000", "50" → 50rb)
 */
final class AmountParser
{
    private const UNITS = [
        'nol' => 0, 'satu' => 1, 'dua' => 2, 'tiga' => 3, 'empat' => 4, 'lima' => 5,
        'enam' => 6, 'tujuh' => 7, 'delapan' => 8, 'sembilan' => 9,
        'sepuluh' => 10, 'sebelas' => 11,
    ];

    private const SCALES = ['ribu' => 1_000, 'juta' => 1_000_000, 'miliar' => 1_000_000_000, 'milyar' => 1_000_000_000];

    private const MONEY = '(\d+(?:[.,]\d+)?\s*(?:rb|ribu|rebu|k|jt|juta)\b|\d{1,3}(?:\.\d{3})+(?![\d.])|\d{4,}(?![\d.]))';

    /** Teks siap-baca: angka dalam kata → digit, nomor HP dibuang. */
    public static function normalize(string $text): string
    {
        // Tanggal angka ("05-11-2026", "5/11/2026", "2026-11-10") bukan nominal — dulu
        // "makan 15000 05-11-2026" terbaca Rp 2.026.
        $text = (string) preg_replace('/(?<![\d.,\/-])(?:\d{1,2}[\/-]\d{1,2}[\/-](?:19|20)?\d{2}|20\d{2}-\d{1,2}-\d{1,2})(?![\d.,\/-])/', ' ', $text);

        return BatchTransactionHelper::stripPhoneNumbers(self::wordsToDigits($text));
    }

    /** Nominal tunggal dari kalimat transaksi. */
    public static function extract(string $text): ?int
    {
        return BatchTransactionHelper::extractAmount($text);
    }

    /**
     * Jawaban singkat user: "50rb" | "1,5jt" | "150.000" | "150000" | "50" | "seratus ribu".
     * Angka polos < 1000 tanpa satuan dianggap ribuan; ≥ 1000 dipakai apa adanya.
     */
    public static function parseAnswer(string $text): ?int
    {
        $t = mb_strtolower(self::normalize(trim($text)));
        if (! preg_match('/(\d+(?:[.,]\d+)*)\s*(rb|ribu|rebu|rbu|k|jt|juta)?\b/u', $t, $m)) {
            return null;
        }

        $suffix = ($m[2] ?? '') === 'rbu' ? 'rb' : ($m[2] ?? '');
        $amount = Rupiah::parse($m[1].$suffix);
        if ($amount === null || $amount <= 0) {
            return null;
        }

        return ($suffix === '' && $amount < 1000) ? $amount * 1000 : $amount;
    }

    /**
     * Satu transaksi yang menyebut 2 angka — JANGAN dipecah jadi 2 transaksi.
     *   "bayar 100rb kembalian 20rb"        → 80.000
     *   "beli sepatu 300rb harusnya 400rb"  → 400.000
     *   "gaji 5jt potong bpjs 150rb"        → 4.850.000
     *   "gaji 3jt 400rb" / "1 juta 250 ribu"→ 3.400.000 / 1.250.000
     *
     * @return int|null null bila bukan pola gabungan
     */
    public static function composite(string $text): ?int
    {
        $t = mb_strtolower(self::normalize($text));
        $money = self::MONEY;

        // Koreksi di tempat: angka kedua yang benar
        if ($pair = self::correctionPair($text)) {
            return $pair[1];
        }

        // Harga satuan × jumlah: "beli 3 buku @20rb", "2 kaos masing-masing 75rb",
        // "4 nasi bungkus 15rb per bungkus", "3 tiket 50rb/orang"
        $qtyItem = '\b(\d{1,3})\s+(?:[a-z]+\s+){0,2}?[a-z]+';
        if (preg_match('/'.$qtyItem.'\s*(?:@|masing[\s-]?masing|masing2|@\s*harga|harga\s+satuan|satuannya|per\s*(?:biji|pcs|buah|item|porsi|orang|bungkus|gelas|botol|lembar|unit))\s*'.$money.'/u', $t, $m)
            || preg_match('/'.$qtyItem.'\s+'.$money.'\s*(?:\/|per|@)\s*(?:biji|pcs|buah|item|porsi|orang|bungkus|gelas|botol|lembar|unit|kg|liter|[a-z]+)\b/u', $t, $m)) {
            $unit = self::money($m[2]);
            $qty = (int) $m[1];
            if ($unit && $qty >= 1) {
                return $unit * $qty;
            }
        }

        // Pengurang: kembalian / potongan / diskon
        if (preg_match('/'.$money.'[^\d]{0,25}?\b(?:kembalian|kembali|kembaliannya|potong|dipotong|potongan|diskon|disc|dikurangi|minus|refund)\b[^\d]{0,25}?'.$money.'/u', $t, $m)) {
            $a = self::money($m[1]);
            $b = self::money($m[2]);
            if ($a && $b && $b < $a) {
                return $a - $b;
            }
        }

        // Satuan bertingkat berdampingan: "3jt 400rb", "1 juta 250 ribu", "2jt 500"
        if (preg_match('/\b(\d+)\s*(?:jt|juta)\s+(\d{1,3})\s*(rb|ribu|rebu|k)?(?![\d.,]|\s*(?:jt|juta))/u', $t, $m)) {
            // Tanpa satuan ("2jt 500") hanya bila tak ada kata lain sesudahnya
            if (! empty($m[3]) || preg_match('/\b\d+\s*(?:jt|juta)\s+\d{1,3}\s*$/u', $t)) {
                return (int) $m[1] * 1_000_000 + (int) $m[2] * 1_000;
            }
        }

        return null;
    }

    /**
     * "beli sepatu 300rb harusnya 400rb" → [300000, 400000] (nominal lama, nominal benar).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function correctionPair(string $text): ?array
    {
        $t = mb_strtolower(self::normalize($text));
        $money = self::MONEY;
        if (preg_match('/'.$money.'[^\d]{0,20}?\b(?:harusnya|seharusnya|mestinya|semestinya|sebenarnya|maksudnya|eh\s+salah|salah\s+harusnya)\b[^\d]{0,15}?'.$money.'/u', $t, $m)) {
            $old = self::money($m[1]);
            $new = self::money($m[2]);

            return ($old && $new) ? [$old, $new] : null;
        }

        return null;
    }

    /** "seratus ribu" → "100000", "satu setengah juta" → "1500000". */
    public static function wordsToDigits(string $text): string
    {
        $word = '(?:nol|satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh|sebelas|se?belas|belas|puluh|ratus|seratus|ribu|seribu|juta|sejuta|miliar|milyar|setengah|stengah)';

        // "15ribuan" / "2 jutaan" → "15 ribu" / "2 juta" (kisaran dibaca sebagai nominalnya)
        $text = (string) preg_replace('/(\d)\s*(ribu|juta)an\b/iu', '$1 $2', $text);

        // "1,2 miliar" / "2 milyar" → digit (pembaca nominal hanya mengenal ribu & juta)
        $text = (string) preg_replace_callback(
            '/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*(?:miliar|milyar)\b/iu',
            fn ($m) => (string) (int) round((float) str_replace(',', '.', $m[1]) * 1_000_000_000),
            $text
        );

        return (string) preg_replace_callback(
            // Tidak didahului angka: "1 juta", "2 ratus ribu" sudah ditangani pembaca angka
            '/(?<![\d.,])(?<![\d.,]\s)\b'.$word.'(?:\s+'.$word.')*\b/iu',
            function ($m) {
                $phrase = mb_strtolower($m[0]);
                // Hanya ubah frasa yang jelas nominal: punya skala ribu/juta/miliar
                if (! preg_match('/\b(?:ribu|seribu|juta|sejuta|miliar|milyar)\b/u', $phrase)) {
                    return $m[0];
                }
                // Hanya kata skala ("juta", "ribu") tanpa bilangan → bukan angka-kata
                if (preg_match('/^(?:(?:ribu|juta|miliar|milyar)\s*)+$/u', $phrase)) {
                    return $m[0];
                }
                $value = self::wordsToNumber($phrase);

                return $value !== null && $value > 0 ? (string) $value : $m[0];
            },
            $text
        );
    }

    public static function wordsToNumber(string $phrase): ?int
    {
        $tokens = preg_split('/\s+/u', trim(mb_strtolower($phrase)));
        $total = 0.0;
        $small = 0;   // < 1000 dalam grup berjalan
        $pending = 0; // digit terakhir yang belum dikalikan
        $half = 0.0;

        foreach ($tokens as $tok) {
            switch (true) {
                case $tok === 'seratus': $small += 100; break;
                case $tok === 'seribu': $total += ($small + $pending + 1) * 1_000; $small = $pending = 0; break;
                case $tok === 'sejuta': $total += ($small + $pending + 1) * 1_000_000; $small = $pending = 0; break;
                case isset(self::UNITS[$tok]): $pending = self::UNITS[$tok]; break;
                case $tok === 'belas': $small += $pending + 10; $pending = 0; break;
                case $tok === 'puluh': $small += $pending * 10; $pending = 0; break;
                case $tok === 'ratus': $small += $pending * 100; $pending = 0; break;
                case $tok === 'setengah' || $tok === 'stengah': $half = 0.5; break;
                case isset(self::SCALES[$tok]):
                    $base = $small + $pending + $half;
                    $total += ($base > 0 ? $base : 1) * self::SCALES[$tok];
                    $small = $pending = 0;
                    $half = 0.0;
                    break;
                default:
                    return null;
            }
        }

        return (int) round($total + $small + $pending);
    }

    private static function money(string $token): ?int
    {
        return Rupiah::parse(preg_replace('/\s+/', '', $token));
    }
}
