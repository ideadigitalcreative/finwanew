<?php

namespace App\Console\Commands;

use App\Models\Balance;
use App\Models\Transaction;
use App\Services\Ledger\TransactionLedger;
use Illuminate\Console\Command;

/**
 * Cocokkan saldo setiap dompet dengan riwayat transaksinya.
 *
 *   php artisan finwa:reconcile                    # semua tenant, laporan saja
 *   php artisan finwa:reconcile --tenant=10768     # satu tenant
 *   php artisan finwa:reconcile --fix              # catat "saldo dasar" untuk selisih lama
 *
 * Saldo dompet dianggap BENAR (yang dilihat user). --fix TIDAK mengubah saldo —
 * hanya menambah satu baris "Saldo dasar (rekonsiliasi)" sebesar selisihnya, agar
 * riwayat menjelaskan saldo. Selisih biasanya berasal dari data sebelum Tahap C
 * (saldo diset langsung tanpa baris transaksi).
 */
class FinwaReconcile extends Command
{
    protected $signature = 'finwa:reconcile
        {--tenant= : ID tenant (kosong = semua)}
        {--fix : Catat baris saldo dasar untuk selisih (saldo tidak berubah)}
        {--tolerance=1 : Selisih (Rp) yang diabaikan}';

    protected $description = 'Cocokkan saldo dompet dengan riwayat transaksi & cek pasangan transfer';

    public function handle(TransactionLedger $ledger): int
    {
        $tolerance = (float) $this->option('tolerance');
        $fix = (bool) $this->option('fix');

        $balances = Balance::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->orderBy('tenant_id')->orderBy('id')
            ->get();

        $rows = [];
        $fixed = 0;
        foreach ($balances as $balance) {
            $computed = TransactionLedger::computedBalance($balance);
            $drift = round((float) $balance->balance - $computed, 2);
            if (abs($drift) <= $tolerance) {
                continue;
            }

            $rows[] = [
                $balance->tenant_id, $balance->id, $balance->account_name,
                $this->rp((float) $balance->balance), $this->rp($computed), $this->rp($drift),
            ];

            if ($fix) {
                $ledger->recordBaseline($balance, $drift);
                $fixed++;
            }
        }

        $this->info('Dompet diperiksa: '.$balances->count());
        if ($rows === []) {
            $this->info('✓ Semua saldo cocok dengan riwayat transaksi.');
        } else {
            $this->warn(count($rows).' dompet tidak cocok:');
            $this->table(['Tenant', 'Dompet ID', 'Nama', 'Saldo', 'Menurut riwayat', 'Selisih'], $rows);
            $this->line($fix
                ? "✓ {$fixed} baris saldo dasar dicatat (saldo tidak berubah)."
                : 'Jalankan dengan --fix untuk mencatat selisih sebagai saldo dasar.');
        }

        // Pasangan transfer yang rusak: satu sisi aktif, pasangannya hilang/terhapus
        $broken = Transaction::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->whereIn('type', ['debit_internal', 'kredit_internal'])
            ->whereNotNull('linked_transaction_id')
            ->whereDoesntHave('linkedTransaction')
            ->get(['id', 'tenant_id', 'type', 'amount', 'linked_transaction_id']);
        if ($broken->isNotEmpty()) {
            $this->warn($broken->count().' sisi transfer tanpa pasangan aktif:');
            $this->table(['ID', 'Tenant', 'Jenis', 'Nominal', 'Pasangan'], $broken->map(fn ($t) => [
                $t->id, $t->tenant_id, $t->type, $this->rp((float) $t->amount), $t->linked_transaction_id,
            ])->all());
        }

        return self::SUCCESS;
    }

    private function rp(float $v): string
    {
        return ($v < 0 ? '-' : '').'Rp '.number_format(abs($v), 0, ',', '.');
    }
}
