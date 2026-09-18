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

        // "Rodi bayar hutang 600rb" → pihak lawan "Rodi" (Rodi yang bayar ke kita).
        $payer = self::extractDebtPayer($t);
        if ($payer !== null) {
            return $payer;
        }

        $lookaheadAmount = '(?=\s+(?:\d|rp|k|rb|jt|juta|ribu|milyar|miliar|,|\.|$))';

        // Urutan: pola panjang / spesifik dulu (hindari salah potong)
        $patterns = [
            '/\bterima\s+pelunasan\s+([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            '/\bpelunasan\s+piutang\s+([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            // "terima piutang budi 150rb" — jangan match jika langsung "dari" (biar pola dari yang ambil)
            '/\bterima\s+piutang\s+(?!dari\b)([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            // Verb + nama tanpa preposisi: "pinjamkan budi", "kasih pinjam budi", "piutang budi"
            '/\bkasih\s+pinjam(?:an)?\s+([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            '/\b(?:pinjamkan|pinjmkan|pinjamin|pijemin|minjemin|pinjmin|pijmin)\s+([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            // "pinjam uang nono 500rb" (kasih pinjam tanpa preposisi) → "nono"
            '/\bpinjam(?:in)?\s+uang\s+(?!dari\b|ke\b)([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            '/\bpiutang\s+(?!ke\b|dari\b)([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
            '/\b(?:hutang|utang)\s+(?!ke\b|dari\b|saya\b|aku\b|ku\b)([^\d\n\r]{2,60}?)'.$lookaheadAmount.'/iu',
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
     * Deteksi pola "X bayar hutang" (X = pihak yang membayar hutangnya ke kita).
     * Contoh: "Rodi bayar hutang 600rb" → "Rodi". Return null bila subjeknya
     * pembicara ("saya/aku") atau bukan nama (waktu, dsb).
     *
     * @return non-empty-string|null
     */
    public static function extractDebtPayer(string $text): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (! preg_match('/\b([A-Za-z][a-z]+(?:\s+[A-Za-z][a-z]+)?)\s+(?:bayar(?:in)?|lunasi|lunasin|nglunasin|balikin|ngembaliin)\s+(?:hutang|utang)\b/u', $t, $m)) {
            return null;
        }

        $name = trim($m[1]);
        $stop = ['saya', 'aku', 'gue', 'gw', 'ku', 'kita', 'kami', 'dia', 'ia', 'mereka', 'kamu', 'anda', 'besok', 'lusa', 'nanti', 'kemarin', 'hari', 'ini', 'minggu', 'bulan', 'tahun', 'sudah', 'mau', 'akan', 'baru', 'lagi', 'udah', 'orang', 'teman', 'sih', 'yang'];

        if (in_array(mb_strtolower($name), $stop, true)) {
            return null;
        }

        // Tolak bila kata pertama adalah subjek pembicara (mis. "aku mau bayar hutang").
        $firstWord = mb_strtolower(strtok($name, ' ') ?: $name);
        if (in_array($firstWord, ['saya', 'aku', 'gue', 'gw', 'ku', 'kita', 'kami', 'dia', 'ia', 'mereka', 'kamu', 'anda'], true)) {
            return null;
        }

        return self::sanitizeName($name);
    }

    /**
     * @return non-empty-string|null
     */
    private static function sanitizeName(string $raw): ?string
    {
        $name = trim($raw);
        // Buang kata sambung / noise di akhir (termasuk "uang"/"duit" filler)
        $name = preg_replace('/\s+(buat|karena|untuk|dari|ke|uang|duit)\s*$/iu', '', $name) ?? $name;
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
