<?php

namespace App\Services\Wallet;

use App\Support\AmountParser;

/**
 * Kenali pesan "pindah uang antar dompet SENDIRI" — bukan pemasukan/pengeluaran:
 *
 *   tarik tunai 500rb [di/dari BCA]      → withdraw  (bank/e-wallet → tunai)
 *   setor tunai 1jt [ke BCA]             → deposit   (tunai → bank)
 *   topup gopay 100rb [pakai/dari BCA]   → topup     (dompet lain → e-wallet)
 *   tf ke BCA 1jt                        → to_wallet (dompet lain → dompet yang disebut)
 *
 * Hanya parsing murni; pemilihan dompet & pertanyaan balik ada di pemanggil.
 *
 * @phpstan-type Move array{kind: string, amount: int, from: ?string, to: ?string}
 */
final class InternalMoveDetector
{
    public const EWALLETS = 'gopay|go\s*pay|ovo|dana|shopee\s*pay|shopeepay|spay|linkaja|link\s*aja|flazz|e-?money|emoney|brizzi|tapcash|i\.?saku|isaku|sakuku|doku|astrapay|jenius\s*pay';

    /** @return array{kind: string, amount: int, from: ?string, to: ?string}|null */
    public static function detect(string $text): ?array
    {
        $t = mb_strtolower(trim($text));
        $amount = AmountParser::extract($text);
        if (! $amount || $amount <= 0) {
            return null;
        }

        $wallet = '([a-z][a-z0-9 ]{1,30}?)';
        $end = '(?=\s+(?:\d|rp)|\s*$|\s+(?:sebesar|senilai|tadi|kemarin|buat|untuk)\b)';

        // tarik tunai / ambil uang DI ATM/BANK. Bukan pindah dompet: "ambil uang arisan",
        // "narik uang dari klien", "tarik saldo shopee" (pencairan), "narik grab".
        $banks = 'bca|bri|brimo|bni|mandiri|bsi|btn|cimb|permata|danamon|jago|seabank|blu|jenius|ocbc|bank|rekening|rek';
        $isCashOut = preg_match('/\b(?:tarik|narik)\s+(?:tunai|cash)\b/u', $t)
            || (preg_match('/\b(?:tarik|narik|ambil|ngambil)\s+(?:uang|duit)\b/u', $t)
                && preg_match('/\batm\b|\b(?:di|dari|dr)\s+(?:'.$banks.')\b/u', $t));
        if ($isCashOut && ! preg_match('/\b(?:shopee|tokped|tokopedia|tiktok|marketplace|grab|gojek|maxim|indrive|arisan|klien|client|pelanggan|customer)\b/u', $t)) {
            preg_match('/\b(?:di|dari|dr|pakai|pake|via|lewat)\s+(?:atm\s+)?'.$wallet.$end.'/u', $t, $m);
            $from = self::clean($m[1] ?? null);

            return ['kind' => 'withdraw', 'amount' => $amount, 'from' => $from === 'atm' ? null : $from, 'to' => null];
        }

        // setor tunai — "setoran tunai DARI pelanggan" = pemasukan, bukan setor ke bank
        if (preg_match('/\bsetor(?:an)?\s+(?:tunai|cash|uang\s+tunai)\b/u', $t) && ! preg_match('/\b(?:dari|dr)\b/u', $t)) {
            preg_match('/\b(?:ke|di)\s+'.$wallet.$end.'/u', $t, $m);

            return ['kind' => 'deposit', 'amount' => $amount, 'from' => null, 'to' => self::clean($m[1] ?? null)];
        }

        // top up / isi e-wallet ("isi saldo X" tetap ditangani alur tambah saldo yang lama).
        // Sesudah nama e-wallet harus nominal / "pakai X" / akhir kalimat —
        // "isi dana darurat 1jt" (dana darurat) bukan top up DANA.
        if (preg_match('/\b(?:top\s*-?\s*up|topup|isi|ngisi)\s+(?!saldo\b|uang\b)('.self::EWALLETS.')\b(?=\s*(?:rp\.?\s*)?\d|\s+(?:pakai|pake|pkai|dari|dr|via|lewat|sebesar|senilai)\b|\s*$)/u', $t, $m)) {
            preg_match('/\b(?:pakai|pake|dari|dr|via|lewat|pkai)\s+'.$wallet.$end.'/u', $t, $src);

            return ['kind' => 'topup', 'amount' => $amount, 'from' => self::clean($src[1] ?? null), 'to' => self::clean($m[1])];
        }

        // "tf ke BCA 1jt" / "transfer 1jt ke BCA" — tujuan harus dompet milik user (dicek pemanggil)
        if (! preg_match('/\b(?:dari|dr)\b/u', $t)
            && (preg_match('/^(?:tf|transfer|trf|pindah(?:kan)?|kirim)\s+(?:saldo\s+|uang\s+)?ke\s+'.$wallet.$end.'/u', $t, $m)
                || preg_match('/^(?:tf|transfer|trf|pindah(?:kan)?|kirim)\s+(?:saldo\s+|uang\s+)?[\d.,]+\s*(?:rb|ribu|k|jt|juta)?\s+ke\s+'.$wallet.'\s*$/u', $t, $m))) {
            return ['kind' => 'to_wallet', 'amount' => $amount, 'from' => null, 'to' => self::clean($m[1])];
        }

        return null;
    }

    private static function clean(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+(?:atm|saya|aku|ku|sendiri)$/u', '', trim($name)));

        return $name !== '' ? $name : null;
    }
}
