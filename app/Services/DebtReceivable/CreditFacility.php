<?php

namespace App\Services\DebtReceivable;

use App\Models\Balance;
use App\Models\Transaction;

/**
 * Fasilitas kredit konsumen (paylater, kartu kredit, Kredivo, …).
 *
 *  - Belanja pakai fasilitas ("beli sepatu pakai spaylater 500rb"):
 *      pengeluaran tercatat (masuk laporan & budget), hutang ke fasilitas naik,
 *      saldo dompet TIDAK berubah. Ditandai metadata.credit_purchase = true.
 *  - Bayar tagihan ("bayar spaylater 500rb"):
 *      dompet berkurang, hutang turun. Selama masih ada hutang hasil belanja
 *      yang sudah tercatat sebagai pengeluaran, pembayaran TIDAK dihitung
 *      pengeluaran lagi (metadata.kind = credit_repayment) agar tidak dobel.
 *
 * Nama fasilitas diseragamkan ("shopee paylater", "spaylater" → "SPayLater")
 * supaya semua baris teragregasi ke satu pihak di daftar hutang.
 */
final class CreditFacility
{
    private const BANKS = 'bca|bni|bri|mandiri|cimb|permata|danamon|mega|ocbc|uob|hsbc|dbs|btn|bsi|jenius|maybank|panin|citi';

    /** @var array<string, string> regex → nama baku (urutan: spesifik dulu) */
    private const NAMES = [
        '(?:shopee\s?pay\s?later|\bs\s?-?\s?pay\s?later)' => 'SPayLater',
        'go\s?pay\s?later' => 'GoPayLater',
        'traveloka\s?pay\s?later' => 'Traveloka PayLater',
        '\bkredivo\b' => 'Kredivo',
        '\bakulaku\b' => 'Akulaku',
        '\bindodana\b' => 'Indodana',
        '\batome\b' => 'Atome',
        '\bhome\s?credit\b' => 'Home Credit',
    ];

    private const USE_WORDS = '(?:pakai|pake|pakek|pk|via|lewat|dengan|dgn|menggunakan|gunakan|gesek)';

    private const PAY_WORDS = '(?:bayar|byr|bayarin|membayar|lunasi|lunasin|melunasi|pelunasan|cicil|nyicil|cicilan|angsur|angsuran|tagihan)';

    /** Nama baku fasilitas kredit yang disebut di teks; null bila tidak ada. */
    public static function canonical(?string $text): ?string
    {
        return self::match((string) $text)['name'] ?? null;
    }

    public static function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Cara fasilitas dipakai di kalimat pengeluaran.
     *
     * @return array{mode: 'purchase'|'repayment', name: string}|null
     */
    public static function detectUse(string $text): ?array
    {
        $hit = self::match($text);
        if ($hit === null) {
            return null;
        }

        $t = mb_strtolower($text);
        $facility = $hit['pattern'];

        // "bayar listrik pakai spaylater" → belanja, walau ada kata "bayar"
        if (preg_match('/\b'.self::USE_WORDS.'\s+(?:kartu\s+)?'.$facility.'/u', $t)) {
            return ['mode' => 'purchase', 'name' => $hit['name']];
        }

        // "bayar spaylater", "cicilan kartu kredit", "bayar tagihan kredivo"
        if (preg_match('/\b'.self::PAY_WORDS.'\s+(?:(?:hutang|utang|tagihan|cicilan|angsuran)\s+)?'.$facility.'/u', $t)) {
            return ['mode' => 'repayment', 'name' => $hit['name']];
        }

        return ['mode' => 'purchase', 'name' => $hit['name']];
    }

    /** User punya dompet bernama fasilitas ini → perlakukan sebagai dompet biasa, bukan hutang. */
    public static function hasWallet(int $tenantId, string $name): bool
    {
        return Balance::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('account_name')
            ->contains(fn ($accountName) => self::canonical((string) $accountName) === $name);
    }

    /**
     * Hutang hasil BELANJA (sudah tercatat sebagai pengeluaran) yang belum dibayar.
     * Pembayaran sebesar ini tidak boleh dihitung pengeluaran lagi.
     */
    public static function purchaseOutstanding(int $tenantId, string $counterpartyKey): float
    {
        $base = Transaction::where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->where('metadata->counterparty_normalized', $counterpartyKey);

        $purchased = (float) (clone $base)->where('metadata->credit_purchase', true)->sum('amount');
        $repaid = (float) (clone $base)->where('metadata->kind', Transaction::KIND_CREDIT_REPAYMENT)->sum('amount');

        return max(0.0, round($purchased - $repaid, 2));
    }

    /**
     * @return array{name: string, pattern: string}|null
     */
    private static function match(string $text): ?array
    {
        $t = mb_strtolower($text);
        if ($t === '') {
            return null;
        }

        foreach (self::NAMES as $pattern => $name) {
            if (preg_match('/'.$pattern.'/u', $t)) {
                return ['name' => $name, 'pattern' => $pattern];
            }
        }

        // Kartu kredit (+ nama bank bila disebut): "kartu kredit bca", "cc mandiri"
        $card = '(?:kartu\s+kredit|credit\s+card|\bcc\b)';
        if (preg_match('/'.$card.'(?:\s+('.self::BANKS.')\b)?/u', $t, $m)) {
            $bank = isset($m[1]) && $m[1] !== '' ? ' '.strtoupper($m[1]) : '';

            return ['name' => 'Kartu Kredit'.$bank, 'pattern' => $card.'(?:\s+(?:'.self::BANKS.')\b)?'];
        }

        if (preg_match('/pay\s?later/u', $t)) {
            return ['name' => 'Paylater', 'pattern' => 'pay\s?later'];
        }

        return null;
    }
}
