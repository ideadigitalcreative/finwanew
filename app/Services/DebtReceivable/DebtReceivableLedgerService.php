<?php

namespace App\Services\DebtReceivable;

use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Ringkasan outstanding hutang & piutang dari transaksi + metadata counterparty.
 *
 * Konvensi metadata (transactions.metadata):
 * - counterparty: string tampilan
 * - counterparty_normalized: string kunci agregasi (lowercase, spasi tunggal)
 */
class DebtReceivableLedgerService
{
    /** @var list<string> */
    public const HUTANG_CATEGORY_TYPES = ['pendapatan_hutang', 'pengeluaran_bayar_hutang'];

    /** @var list<string> */
    public const PIUTANG_CATEGORY_TYPES = ['pengeluaran_piutang', 'pendapatan_terima_piutang'];

    /**
     * @return array{
     *   hutang: list<array{counterparty: string, counterparty_normalized: string, outstanding: float}>,
     *   piutang: list<array{counterparty: string, counterparty_normalized: string, outstanding: float}>,
     *   recent: list<array<string, mixed>>
     * }
     */
    public function summarize(int $tenantId, int $recentLimit = 30): array
    {
        $types = array_merge(self::HUTANG_CATEGORY_TYPES, self::PIUTANG_CATEGORY_TYPES);

        /** @var Collection<int, Transaction> $rows */
        $rows = Transaction::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->whereHas('category', static fn ($q) => $q->whereIn('type', $types))
            ->with(['category:id,type,name'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $hutang = [];
        $piutang = [];

        foreach ($rows as $tx) {
            $catType = $tx->category?->type;
            if (! is_string($catType)) {
                continue;
            }

            ['label' => $label, 'key' => $key] = $this->counterpartyLabelAndKey($tx);

            $amount = (float) $tx->amount;

            if (in_array($catType, self::HUTANG_CATEGORY_TYPES, true)) {
                if (! isset($hutang[$key])) {
                    $hutang[$key] = ['counterparty' => $label, 'counterparty_normalized' => $key, 'outstanding' => 0.0];
                }
                // Hutang: terima pinjaman (+), bayar hutang (−)
                if ($catType === 'pendapatan_hutang') {
                    $hutang[$key]['outstanding'] += $amount;
                } else {
                    $hutang[$key]['outstanding'] -= $amount;
                }
            }

            if (in_array($catType, self::PIUTANG_CATEGORY_TYPES, true)) {
                if (! isset($piutang[$key])) {
                    $piutang[$key] = ['counterparty' => $label, 'counterparty_normalized' => $key, 'outstanding' => 0.0];
                }
                // Piutang: keluar pinjaman (+), terima pelunasan (−)
                if ($catType === 'pengeluaran_piutang') {
                    $piutang[$key]['outstanding'] += $amount;
                } else {
                    $piutang[$key]['outstanding'] -= $amount;
                }
            }
        }

        $hutangList = array_values($hutang);
        $piutangList = array_values($piutang);

        usort($hutangList, static fn ($a, $b) => abs($b['outstanding']) <=> abs($a['outstanding']));
        usort($piutangList, static fn ($a, $b) => abs($b['outstanding']) <=> abs($a['outstanding']));

        $recent = $rows->take($recentLimit)->map(function (Transaction $tx) {
            $resolved = $this->counterpartyLabelAndKey($tx);

            return [
                'id' => $tx->id,
                'transaction_date' => $tx->transaction_date?->format('Y-m-d'),
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'description' => $tx->description,
                'category_type' => $tx->category?->type,
                'category_name' => $tx->category?->name,
                'counterparty' => $resolved['label'] === 'Tanpa nama' ? null : $resolved['label'],
            ];
        })->values()->all();

        return [
            'hutang' => $hutangList,
            'piutang' => $piutangList,
            'recent' => $recent,
        ];
    }

    /**
     * @return array{label: string, key: string}
     */
    private function counterpartyLabelAndKey(Transaction $tx): array
    {
        $meta = is_array($tx->metadata) ? $tx->metadata : [];
        if (isset($meta['counterparty']) && is_string($meta['counterparty']) && trim($meta['counterparty']) !== '') {
            $label = trim($meta['counterparty']);
            $key = isset($meta['counterparty_normalized']) && is_string($meta['counterparty_normalized']) && trim($meta['counterparty_normalized']) !== ''
                ? mb_strtolower(trim($meta['counterparty_normalized']))
                : mb_strtolower(preg_replace('/\s+/u', ' ', $label) ?? $label);

            return ['label' => $label, 'key' => $key];
        }

        $extracted = CounterpartyExtractor::extract($tx->description ?? '');
        if ($extracted !== null && $extracted !== '') {
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $extracted) ?? $extracted);

            return ['label' => $extracted, 'key' => $key];
        }

        return ['label' => 'Tanpa nama', 'key' => 'tanpa nama'];
    }
}
