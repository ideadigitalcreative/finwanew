<?php

namespace App\Services\Ledger;

use App\Models\Balance;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SATU-SATUNYA tempat yang mengubah transaksi beserta saldo dompetnya.
 *
 * Setiap operasi berjalan dalam satu DB::transaction: catatan & saldo selalu
 * berubah bersama atau tidak sama sekali. Jalur WhatsApp, dashboard, dan API
 * memanggil kelas ini — bukan Transaction::create + adjustBalance sendiri-sendiri.
 *
 * Aturan efek saldo (lihat effect()):
 *   - hanya transaksi berstatus "confirmed" yang punya balance_id
 *   - income / kredit_internal menambah, expense / debit_internal mengurangi
 *
 * Transfer antar dompet = pasangan debit_internal + kredit_internal yang saling
 * menunjuk lewat linked_transaction_id; ubah/hapus/pulihkan selalu kena dua sisi.
 */
class TransactionLedger
{
    /** Efek bertanda transaksi ini terhadap saldo dompetnya. */
    public static function effect(Transaction $transaction): float
    {
        if (! $transaction->balance_id || $transaction->status !== 'confirmed' || $transaction->trashed()) {
            return 0.0;
        }

        $amount = (float) $transaction->amount;

        return in_array($transaction->type, Transaction::CREDIT_TYPES, true) ? $amount : -$amount;
    }

    /** Catat transaksi baru + terapkan ke saldo. */
    public function record(array $attributes, array $audit = []): Transaction
    {
        return AuditContext::run($audit, fn () => DB::transaction(function () use ($attributes) {
            $attributes['status'] ??= 'confirmed';
            $transaction = Transaction::create($attributes);
            $this->applyEffect($transaction->balance_id, self::effect($transaction));

            return $transaction;
        }));
    }

    /**
     * Ubah transaksi. Saldo dikoreksi otomatis bila nominal, jenis, status, atau
     * dompet berubah. Sisi pasangan transfer ikut diubah (nominal & tanggal).
     */
    public function update(Transaction $transaction, array $changes, array $audit = []): Transaction
    {
        $this->guardBaseline($transaction);

        return AuditContext::run($audit, fn () => DB::transaction(function () use ($transaction, $changes) {
            $this->updateOne($transaction, $changes);

            $mirror = array_intersect_key($changes, array_flip(['amount', 'transaction_date']));
            if ($mirror !== [] && ($partner = $this->partner($transaction))) {
                $this->updateOne($partner, $mirror);
            }

            return $transaction;
        }));
    }

    /**
     * Simpan perubahan atribut yang sudah di-set pada model (pola lama
     * "$tx->amount = X; $tx->save();") lewat ledger agar saldo ikut terkoreksi.
     */
    public function save(Transaction $transaction, array $audit = []): Transaction
    {
        $dirty = $transaction->getDirty();

        return $dirty === [] ? $transaction : $this->update($transaction, $dirty, $audit);
    }

    /** Hapus (soft delete) + kembalikan saldo. Pasangan transfer ikut terhapus. */
    public function delete(Transaction $transaction, array $audit = []): void
    {
        $this->guardBaseline($transaction);

        AuditContext::run($audit, fn () => DB::transaction(function () use ($transaction) {
            $partner = $this->partner($transaction);
            $this->deleteOne($transaction);
            if ($partner) {
                $this->deleteOne($partner);
            }
        }));
    }

    /** Pulihkan transaksi yang dihapus + terapkan lagi ke saldo. */
    public function restore(Transaction $transaction, array $audit = []): void
    {
        AuditContext::run($audit, fn () => DB::transaction(function () use ($transaction) {
            $partner = $transaction->linked_transaction_id
                ? Transaction::onlyTrashed()->find($transaction->linked_transaction_id)
                : null;
            $this->restoreOne($transaction);
            if ($partner) {
                $this->restoreOne($partner);
            }
        }));
    }

    /**
     * Pindah saldo antar dompet: dua baris yang terkunci sebagai pasangan.
     *
     * @return array{0: Transaction, 1: Transaction} [debit (sumber), kredit (tujuan)]
     */
    public function transfer(Balance $from, Balance $to, float $amount, array $common = [], array $audit = []): array
    {
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Dompet sumber dan tujuan sama.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal transfer harus lebih dari 0.');
        }

        return AuditContext::run($audit, fn () => DB::transaction(function () use ($from, $to, $amount, $common) {
            $base = array_merge([
                'tenant_id' => $from->tenant_id,
                'amount' => $amount,
                'transaction_date' => now()->toDateString(),
                'status' => 'confirmed',
                'confidence_score' => 1.0,
            ], $common);
            $baseMeta = (array) ($common['metadata'] ?? []);

            $debit = Transaction::create(array_merge($base, [
                'type' => 'debit_internal',
                'balance_id' => $from->id,
                'category_id' => $common['debit_category_id'] ?? $this->categoryId($from->tenant_id, 'debit_internal'),
                'description' => $common['debit_description'] ?? "Transfer ke {$to->account_name}",
                'metadata' => array_merge($baseMeta, ['is_transfer' => true, 'destination_wallet_id' => $to->id]),
            ]));
            $credit = Transaction::create(array_merge($base, [
                'type' => 'kredit_internal',
                'balance_id' => $to->id,
                'category_id' => $common['credit_category_id'] ?? $this->categoryId($to->tenant_id, 'kredit_internal'),
                'description' => $common['credit_description'] ?? "Transfer dari {$from->account_name}",
                'linked_transaction_id' => $debit->id,
                'metadata' => array_merge($baseMeta, ['is_transfer' => true, 'source_wallet_id' => $from->id]),
            ]));
            $debit->linked_transaction_id = $credit->id;
            $debit->save();

            $this->applyEffect($from->id, self::effect($debit));
            $this->applyEffect($to->id, self::effect($credit));

            return [$debit, $credit];
        }));
    }

    /**
     * Setel saldo dompet ke nilai tertentu lewat baris penyesuaian (selisihnya),
     * sehingga riwayat tetap menjelaskan saldo. Null bila saldo sudah sama.
     * Jenis penyesuaian: debit/kredit_internal + metadata.kind → tidak masuk laporan
     * pemasukan/pengeluaran.
     */
    public function setBalanceTo(Balance $balance, float $target, string $kind = Transaction::KIND_BALANCE_ADJUSTMENT, array $attributes = [], array $audit = []): ?Transaction
    {
        return AuditContext::run($audit, fn () => DB::transaction(function () use ($balance, $target, $kind, $attributes) {
            $current = (float) Balance::whereKey($balance->id)->lockForUpdate()->value('balance');
            $delta = round($target - $current, 2);
            if ($delta == 0.0) {
                $balance->refresh();

                return null;
            }

            $type = $delta > 0 ? 'kredit_internal' : 'debit_internal';
            $label = $kind === Transaction::KIND_OPENING_BALANCE ? 'Saldo awal' : 'Penyesuaian saldo';
            $transaction = Transaction::create(array_merge([
                'tenant_id' => $balance->tenant_id,
                'balance_id' => $balance->id,
                'category_id' => $this->categoryId($balance->tenant_id, $type),
                'type' => $type,
                'amount' => abs($delta),
                'transaction_date' => now()->toDateString(),
                'description' => $label.' '.$balance->account_name.': Rp '.number_format($current, 0, ',', '.').' → Rp '.number_format($target, 0, ',', '.'),
                'status' => 'confirmed',
                'confidence_score' => 1.0,
                'source' => $kind,
            ], $attributes, [
                'metadata' => array_merge((array) ($attributes['metadata'] ?? []), [
                    'kind' => $kind,
                    'balance_before' => $current,
                    'balance_after' => $target,
                ]),
            ]));

            $this->applyEffect($balance->id, self::effect($transaction));
            $balance->refresh();

            return $transaction;
        }));
    }

    /**
     * Baris dasar rekonsiliasi: menjelaskan selisih saldo lama yang tidak punya
     * riwayat. TIDAK mengubah saldo (saldo dompet dianggap benar).
     */
    public function recordBaseline(Balance $balance, float $drift, array $audit = []): Transaction
    {
        return AuditContext::run(array_merge(['via' => 'reconcile'], $audit), fn () => DB::transaction(function () use ($balance, $drift) {
            $type = $drift > 0 ? 'kredit_internal' : 'debit_internal';

            // Diletakkan di AWAL riwayat dompet (bukan "transaksi terbaru"), supaya
            // tidak ikut kena "batal"/edit transaksi terakhir.
            $first = Transaction::where('balance_id', $balance->id)->orderBy('created_at')->orderBy('id')->first();
            $at = $first?->created_at?->copy()->subSecond() ?? $balance->created_at ?? now();
            $date = $first?->transaction_date ?? $at;

            $transaction = new Transaction([
                'tenant_id' => $balance->tenant_id,
                'balance_id' => $balance->id,
                'category_id' => $this->categoryId($balance->tenant_id, $type),
                'type' => $type,
                'amount' => abs($drift),
                'transaction_date' => \Illuminate\Support\Carbon::parse($date)->toDateString(),
                'description' => 'Saldo dasar (rekonsiliasi) '.$balance->account_name,
                'status' => 'confirmed',
                'confidence_score' => 1.0,
                'source' => Transaction::KIND_RECONCILE_BASELINE,
                'metadata' => ['kind' => Transaction::KIND_RECONCILE_BASELINE],
            ]);
            $transaction->created_at = $at;
            $transaction->updated_at = now();
            $transaction->save();

            return $transaction;
        }));
    }

    /**
     * Saldo dasar rekonsiliasi dicatat TANPA mengubah saldo; menghapus/mengubahnya
     * lewat ledger akan menggeser saldo user. Jadi baris ini dikunci.
     */
    private function guardBaseline(Transaction $transaction): void
    {
        if ($transaction->source === Transaction::KIND_RECONCILE_BASELINE
            || $transaction->kind() === Transaction::KIND_RECONCILE_BASELINE) {
            throw new \DomainException('Baris saldo dasar (rekonsiliasi) tidak bisa diubah atau dihapus.');
        }
    }

    /** Saldo menurut riwayat transaksi (untuk rekonsiliasi). */
    public static function computedBalance(Balance $balance): float
    {
        $rows = Transaction::where('balance_id', $balance->id)
            ->where('status', 'confirmed')
            ->selectRaw("SUM(CASE WHEN type IN ('income','kredit_internal') THEN amount ELSE -amount END) AS total")
            ->value('total');

        return round((float) $rows, 2);
    }

    // ── internal ────────────────────────────────────────────────────────────

    private function updateOne(Transaction $transaction, array $changes): void
    {
        $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->first() ?? $transaction;
        $oldBalanceId = $locked->balance_id;
        $oldEffect = self::effect($locked);

        $transaction->fill($changes);
        $transaction->save();

        $newEffect = self::effect($transaction);
        if ($oldBalanceId !== $transaction->balance_id) {
            $this->applyEffect($oldBalanceId, -$oldEffect);
            $this->applyEffect($transaction->balance_id, $newEffect);
        } else {
            $this->applyEffect($transaction->balance_id, $newEffect - $oldEffect);
        }
    }

    private function deleteOne(Transaction $transaction): void
    {
        if ($transaction->trashed()) {
            return;
        }
        $effect = self::effect($transaction);
        $transaction->delete();
        $this->applyEffect($transaction->balance_id, -$effect);
    }

    private function restoreOne(Transaction $transaction): void
    {
        if (! $transaction->trashed()) {
            return;
        }
        $transaction->restore();
        $this->applyEffect($transaction->balance_id, self::effect($transaction));
    }

    private function partner(Transaction $transaction): ?Transaction
    {
        if (! $transaction->linked_transaction_id) {
            return null;
        }
        $partner = Transaction::find($transaction->linked_transaction_id);

        // Hanya pasangan transfer sungguhan (saling menunjuk)
        return $partner && (int) $partner->linked_transaction_id === (int) $transaction->id ? $partner : null;
    }

    private function applyEffect(?int $balanceId, float $delta): void
    {
        if (! $balanceId || $delta == 0.0) {
            return;
        }
        Balance::whereKey($balanceId)->increment('balance', $delta);
    }

    private function categoryId(int $tenantId, string $type): ?int
    {
        $id = Category::where('tenant_id', $tenantId)->where('type', $type)->value('id');
        if ($id) {
            return $id;
        }

        // Kategori internal belum ada (tenant lama) → buat, agar category_id (NOT NULL) selalu terisi
        return Category::firstOrCreate(
            ['tenant_id' => $tenantId, 'type' => $type, 'slug' => str_replace('_', '-', $type)],
            ['name' => $type === 'kredit_internal' ? 'Kredit Antar Dompet' : 'Debit Antar Dompet', 'is_system' => true]
        )->id;
    }
}
