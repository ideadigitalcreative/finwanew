<?php

namespace App\Services\Wallet;

use App\Models\Balance;

/**
 * Mencari dompet MILIK USER yang disebut di sebuah teks.
 *
 * Kebalikan dari tebakan regex ("kata setelah pakai/via = dompet"): di sini nama
 * dompet diambil dari daftar dompet tenant, lalu dicari di teks. Jadi
 * "lewat rek mandiri" menemukan dompet "Bank Mandiri", sedangkan "pakai sendok"
 * tidak menemukan apa-apa. Tidak pernah membuat dompet baru.
 */
class WalletResolver
{
    /** Kata yang menandai cara bayar / dompet tujuan, tepat sebelum nama dompet. */
    private const CUE = '(?:pakai|pake|pakek|pk|menggunakan|gunakan|via|lewat|melalui|dari|dr|ke|masuk|tf|trf|transfer|debit|qris|saldo|dompet|rekening|rek|bank|akun|wallet|e-?wallet|kartu|atm)';

    /** Kata pengisi yang boleh berada di antara penanda dan nama dompet ("lewat rek mandiri"). */
    private const FILLER = '(?:(?:bank|rek|rekening|dompet|saldo|akun|wallet|e-?wallet|qris|debit|kartu|atm|ke|di|dari)\s+){0,2}';

    private const AMOUNT = '(?:rp\.?\s*)?\d[\d.,]*\s*(?:rb|ribu|rebu|k|jt|juta)?';

    /** Nama terlalu umum untuk dicocokkan sebagai kata lepas. */
    private const GENERIC = ['utama', 'pribadi', 'saya', 'aku', 'baru', 'lama', 'uang', 'kas'];

    /**
     * @param  bool  $anywhere  true: nama dompet cukup muncul sebagai kata utuh di mana pun
     *                          (untuk teks pendek yang memang berisi cara bayar, mis. keterangan
     *                          foto atau kandidat "masuk bca"). false: harus didahului penanda
     *                          cara bayar atau berada tepat setelah nominal di akhir pesan.
     */
    public function match(int $tenantId, string $text, bool $anywhere = false): ?Balance
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        if ($text === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;
        $wallets = Balance::where('tenant_id', $tenantId)->where('is_active', true)->get();

        foreach ($wallets as $wallet) {
            foreach ($this->needles((string) $wallet->account_name) as $needle) {
                if (mb_strlen($needle) <= $bestLen) {
                    continue;
                }
                $n = preg_quote($needle, '/');
                $word = '(?<![\p{L}\p{N}])'.$n.'(?![\p{L}\p{N}])';

                $hit = $anywhere
                    ? preg_match('/'.$word.'/u', $text)
                    : (preg_match('/(?<![\p{L}\p{N}])'.self::CUE.'\s+'.self::FILLER.$n.'(?![\p{L}\p{N}])/u', $text)
                        || preg_match('/'.self::AMOUNT.'\s+'.$n.'\s*[.!]*$/u', $text));

                if ($hit) {
                    $best = $wallet;
                    $bestLen = mb_strlen($needle);
                }
            }
        }

        return $best;
    }

    /**
     * Bentuk nama dompet yang dicari: nama lengkap dan nama tanpa awalan umum
     * ("Bank Mandiri" → "bank mandiri", "mandiri").
     *
     * @return list<string>
     */
    private function needles(string $accountName): array
    {
        $full = mb_strtolower(trim($accountName));
        $short = trim((string) preg_replace('/^(?:bank|dompet|rekening|rek|e-?wallet|wallet|akun|saldo)\s+/u', '', $full));

        $needles = [];
        foreach (array_unique([$full, $short]) as $needle) {
            if (mb_strlen($needle) >= 3 && ! in_array($needle, self::GENERIC, true)) {
                $needles[] = $needle;
            }
        }

        return $needles;
    }

    /**
     * Kandidat nama yang tidak cocok dengan dompet mana pun: apakah memang nama
     * rekening/e-wallet (layak diperingatkan ke user), atau hanya kata biasa yang
     * salah ditebak ("makan siang", "bulan lalu") sehingga cukup diabaikan?
     */
    public function looksLikeWalletName(string $candidate, string $messageText = ''): bool
    {
        $c = mb_strtolower(trim($candidate));
        if ($c === '') {
            return false;
        }

        $known = 'bca|bri|brimo|bni|mandiri|livin|bsi|btn|cimb|permata|danamon|jago|seabank|blu|jenius|neo|ocbc|maybank|panin|mega|bjb|'
            .'gopay|ovo|dana|shopeepay|spay|linkaja|flazz|e-?money|brizzi|tapcash|cash|tunai|bank|tabungan|rekening|dompet|e-?wallet|wallet';
        if (preg_match('/(?<![\p{L}\p{N}])(?:'.$known.')(?![\p{L}\p{N}])/u', $c)) {
            return true;
        }

        // Disebut eksplisit sebagai dompet: "pakai dompet liburan"
        return $messageText !== '' && (bool) preg_match(
            '/(?<![\p{L}\p{N}])(?:dompet|rekening|rek|akun|wallet|e-?wallet|tabungan)\s+'.preg_quote($c, '/').'(?![\p{L}\p{N}])/u',
            mb_strtolower($messageText)
        );
    }
}
