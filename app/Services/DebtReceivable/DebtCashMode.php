<?php

namespace App\Services\DebtReceivable;

use App\Models\Balance;
use App\Models\Transaction;
use App\Services\Ledger\TransactionLedger;

/**
 * Apakah pencatatan hutang/piutang BARU ikut menggerakkan uang di dompet?
 *
 *  - cash        : uang benar-benar masuk/keluar ("pinjam dari budi 2jt") → saldo berubah
 *  - record_only : hutang/piutang yang sudah ada, atau fasilitas kredit (paylater,
 *                  kartu kredit) — hanya dicatat, saldo dompet TIDAK berubah
 *  - ambiguous   : tidak bisa ditebak dengan aman ("hutang budi 2jt") → tanya user
 *
 * Baris "hanya dicatat" disimpan tanpa dompet (balance_id = null) dengan jenis
 * kredit_internal (hutang) / debit_internal (piutang) + metadata.kind, sehingga
 * tidak mengubah saldo dan tidak terhitung di laporan pemasukan/pengeluaran,
 * tetapi tetap masuk daftar hutang/piutang (yang dihitung dari tipe kategori).
 */
final class DebtCashMode
{
    public const CASH = 'cash';

    public const RECORD_ONLY = 'record_only';

    public const AMBIGUOUS = 'ambiguous';

    /** Kategori pencatatan hutang/piutang baru (bukan pelunasan). */
    public const NEW_DEBT_CATEGORY_TYPES = ['pendapatan_hutang', 'pengeluaran_piutang'];

    private const DEBT_WORD = '(?:hutang|utang|piutang)';

    /** Fasilitas kredit: hutangnya timbul tanpa uang masuk ke dompet. */
    private const CREDIT_FACILITY = '(?:s\s?-?\s?pay\s?later|shopee\s?pay\s?later|go\s?pay\s?later|pay\s?later|kartu\s+kredit|credit\s+card|\bcc\b|kredivo|akulaku|indodana|atome|home\s?credit|\bkpr\b|\bkta\b|leasing|cicilan|kredit\s+(?:motor|mobil|rumah|hp|barang))';

    /**
     * Keputusan TEGAS — dipakai untuk memutuskan perlu bertanya atau tidak.
     *
     * Begitu kalimat menyebut "hutang/utang/piutang", hanya pernyataan yang benar-benar
     * eksplisit yang dipercaya ("hutang lama…", "jangan masuk saldo", "pinjam dari…",
     * "dapat pinjaman…"). Selain itu → AMBIGUOUS, supaya user memilih sendiri 1/2.
     * "punya hutang SpayPinjam 3jt" atau "hutang Spay Pinjam 3jt" tidak ditebak:
     * "pinjam" di situ bisa nama produk, dan "punya hutang" bisa hutang baru.
     */
    public static function detect(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $debt = self::DEBT_WORD;

        if (! preg_match('/\b'.$debt.'\b/u', $t)) {
            return self::guess($text);
        }

        $recordOnly = [
            '/\b'.$debt.'\s+(?:lama|lalu|dulu|sebelumnya|(?:yang|yg)\s+(?:sudah|udah|telah)\s+ada)\b/u',
            '/\b(?:hanya|cuma|cuman)\s+(?:di\s?catat|catat|nyatat)\b/u',
            '/\b(?:di\s?catat|catat)\s+(?:saja|aja|doang)\b/u',
            '/\b(?:tanpa|jangan|tidak|tdk|ga|gak|nggak|enggak|bukan)\s+(?:usah\s+)?(?:ubah|rubah|mengubah|masuk|tambah|nambah|menambah|potong|memotong|kurangi|mengurangi|keluar)\w*\s+(?:ke\s+|dari\s+)?(?:saldo|dompet|rekening)\b/u',
            '/\bsaldo\s+(?:tetap|tidak\s+berubah|ga\s+berubah|gak\s+berubah)\b/u',
        ];
        foreach ($recordOnly as $pattern) {
            if (preg_match($pattern, $t)) {
                return self::RECORD_ONLY;
            }
        }

        $cash = [
            '/\b(?:pinjam|minjem|pinjem|meminjam)\s+(?:uang\s+|duit\s+)?(?:dari|ke)\b/u',
            '/\b(?:dapat|dapet|terima|nerima)\s+(?:pinjaman|utangan|hutangan)\b/u',
            '/\bpinjaman\s+dari\b/u',
            '/\b(?:dipinjemin|dipinjami|dipinjamkan|dikasih\s+pinjam\w*)\b/u',
            '/\b(?:kasih|ngasih|beri|memberi)\s+pinjam\w*\b/u',
            '/\b(?:pinjamkan|pinjemin|minjemin|pijemin|meminjamkan)\b/u',
            '/\b(?:cair|pencairan|dicairkan)\b/u',
            '/\b(?:uang|duit|dana)\s+(?:masuk|keluar)\b/u',
            '/\b(?:masuk|keluar)\s+(?:ke\s+|dari\s+)?(?:saldo|dompet|rekening)\b/u',
        ];
        foreach ($cash as $pattern) {
            if (preg_match($pattern, $t)) {
                return self::CASH;
            }
        }

        return self::AMBIGUOUS;
    }

    /**
     * Tebakan TERBAIK — dipakai saat tidak bisa bertanya (grup, jalur tanpa
     * pertanyaan): "punya hutang…", paylater, kartu kredit → hanya dicatat.
     */
    public static function guess(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $debt = self::DEBT_WORD;

        // 1) User bilang terang-terangan: ini hutang yang sudah ada / jangan sentuh saldo.
        $recordOnly = [
            '/\b(?:punya|ada|masih|sisa|saldo|total|tunggakan|catat|catatkan|nyatat|mencatat)\s+'.$debt.'\b/u',
            '/\b'.$debt.'\s+(?:lama|lalu|dulu|sebelumnya|berjalan|awal|tercatat|(?:yang|yg)\s+(?:sudah|udah|telah)\s+ada)\b/u',
            '/\b(?:tagihan|tunggakan)\b/u',
            '/\b(?:belum|blm)\s+(?:lunas|di\s?bayar|bayar)\b/u',
            '/\b(?:hanya|cuma|cuman)\s+(?:di\s?catat|catat|nyatat)\b/u',
            '/\b(?:di\s?catat|catat)\s+(?:saja|aja|doang)\b/u',
            '/\b(?:tanpa|jangan|tidak|tdk|ga|gak|nggak|enggak|bukan)\s+(?:usah\s+)?(?:ubah|rubah|mengubah|masuk|tambah|nambah|menambah|potong|memotong|kurangi|mengurangi|keluar)\w*\s+(?:ke\s+|dari\s+)?(?:saldo|dompet|rekening)\b/u',
            '/\bsaldo\s+(?:tetap|tidak\s+berubah|ga\s+berubah|gak\s+berubah)\b/u',
        ];
        foreach ($recordOnly as $pattern) {
            if (preg_match($pattern, $t)) {
                return self::RECORD_ONLY;
            }
        }

        // 2) Ada perpindahan uang yang jelas.
        $cash = [
            '/\b(?:pinjam|minjem|pinjem|meminjam|ngutang|kasbon|ngebon)\b/u',
            '/\b(?:dapat|dapet|terima|nerima)\s+(?:pinjaman|utangan|hutangan)\b/u',
            '/\bpinjaman\s+dari\b/u',
            '/\b(?:dipinjemin|dipinjami|dipinjamkan|dikasih\s+pinjam\w*)\b/u',
            '/\b(?:kasih|ngasih|beri|memberi)\s+pinjam\w*\b/u',
            '/\b(?:pinjamkan|pinjemin|minjemin|pijemin|meminjamkan)\b/u',
            '/\b(?:cair|pencairan|dicairkan)\b/u',
            '/\b(?:uang|duit|dana)\s+(?:masuk|keluar)\b/u',
            '/\b(?:masuk|keluar)\s+(?:ke\s+|dari\s+)?(?:saldo|dompet|rekening)\b/u',
        ];
        foreach ($cash as $pattern) {
            if (preg_match($pattern, $t)) {
                return self::CASH;
            }
        }

        // 3) Hutang ke fasilitas kredit: tidak ada uang masuk ke dompet.
        if (preg_match('/'.self::CREDIT_FACILITY.'/u', $t)) {
            return self::RECORD_ONLY;
        }

        return self::AMBIGUOUS;
    }

    public static function isNewDebtCategory(?string $categoryType): bool
    {
        return is_string($categoryType) && in_array($categoryType, self::NEW_DEBT_CATEGORY_TYPES, true);
    }

    /** Jenis transaksi untuk baris "hanya dicatat" — tidak dihitung laporan pemasukan/pengeluaran. */
    public static function recordOnlyType(string $categoryType): string
    {
        return $categoryType === 'pendapatan_hutang' ? 'kredit_internal' : 'debit_internal';
    }

    /** Jenis transaksi bila uangnya benar-benar bergerak. */
    public static function cashType(string $categoryType): string
    {
        return $categoryType === 'pendapatan_hutang' ? 'income' : 'expense';
    }

    /**
     * Ubah hutang/piutang yang sudah tercatat: ikut menggerakkan saldo dompet atau
     * hanya dicatat. Saldo dikoreksi otomatis oleh TransactionLedger.
     *
     * @return bool false bila transaksi bukan pencatatan hutang/piutang baru atau sudah dalam mode itu
     */
    public static function convert(Transaction $transaction, bool $affectsBalance, ?Balance $balance = null, array $audit = []): bool
    {
        $categoryType = $transaction->category?->type;
        if (! self::isNewDebtCategory($categoryType)) {
            return false;
        }
        if ($affectsBalance === ! $transaction->isDebtRecordOnly()) {
            return false;
        }
        if ($affectsBalance && ! $balance) {
            return false;
        }

        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        if ($affectsBalance) {
            unset($metadata['kind']);
            $changes = [
                'type' => self::cashType($categoryType),
                'balance_id' => $balance->id,
            ];
        } else {
            $metadata['kind'] = Transaction::KIND_DEBT_RECORD_ONLY;
            $changes = [
                'type' => self::recordOnlyType($categoryType),
                'balance_id' => null,
            ];
        }
        $changes['metadata'] = $metadata;

        app(TransactionLedger::class)->update($transaction, $changes, $audit);

        return true;
    }
}
