<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Push\TransactionPushNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosa notifikasi push TRANSAKSI (bukan notifikasi uji):
 * observer → antrean → job → FCM. Menunjukkan di tahap mana macetnya.
 *
 *   php artisan finwa:push-check haerulhadi00@gmail.com
 *   php artisan finwa:push-check haerulhadi00@gmail.com --send   (kirim transaksi terakhir sekarang, tanpa antrean)
 */
class FinwaPushCheck extends Command
{
    protected $signature = 'finwa:push-check {user : Email / ID / nomor WA / nama} {--send : Kirim notifikasi transaksi terakhir langsung (tanpa antrean)}';

    protected $description = 'Telusuri kenapa notifikasi transaksi tidak muncul';

    public function handle(TransactionPushNotifier $notifier): int
    {
        $q = (string) $this->argument('user');
        $user = User::where('email', $q)->orWhere('whatsapp_number', $q)
            ->when(ctype_digit($q) && strlen($q) < 10, fn ($b) => $b->orWhere('id', (int) $q))
            ->first()
            ?? User::where('name', 'like', '%'.$q.'%')->first();
        if (! $user) {
            $this->error("User '{$q}' tidak ditemukan.");

            return self::FAILURE;
        }

        $tenantId = (int) ($user->currentTenant()?->id ?? $user->tenant_id);

        $this->info('== Sistem ==');
        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->where('payload', 'like', '%SendTransactionPush%')->count() : null;
        $oldestJob = Schema::hasTable('jobs') ? DB::table('jobs')->min('available_at') : null;
        $failed = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('payload', 'like', '%SendTransactionPush%')->latest('failed_at')->first(['failed_at', 'exception'])
            : null;

        $this->table(['Item', 'Nilai'], [
            ['FINWA_PUSH_ENABLED', config('finwa_push.enabled') ? 'YA' : 'TIDAK'],
            ['Koneksi antrean (QUEUE_CONNECTION)', config('queue.default').(config('queue.default') === 'sync' ? ' (langsung, tanpa worker)' : '')],
            ['Cache (CACHE_STORE)', config('cache.default').(config('cache.default') === 'array' ? ' ⚠ array tidak berbagi data antar proses → notifikasi batch hilang' : '')],
            ['Observer transaksi terdaftar', Event::hasListeners('eloquent.created: '.Transaction::class) ? 'YA' : 'TIDAK ⚠'],
            ['Job push menunggu di antrean', $pendingJobs === null ? '-' : (string) $pendingJobs],
            ['Job tertua di antrean', $oldestJob ? now()->diffForHumans(\Carbon\Carbon::createFromTimestamp($oldestJob), true).' lalu'.(time() - $oldestJob > 120 ? ' ⚠ worker tidak memproses antrean?' : '') : '(kosong)'],
            ['Job push gagal terakhir', $failed ? $failed->failed_at.' — '.mb_substr(strtok((string) $failed->exception, "\n"), 0, 120) : '(tidak ada)'],
            ['Perangkat '.$user->name, $user->deviceTokens()->count().' | push '.($user->push_enabled ? 'aktif' : 'NONAKTIF')],
        ]);

        $txs = Transaction::where('tenant_id', $tenantId)->latest('id')->limit(3)->get();
        if ($txs->isEmpty()) {
            $this->warn("Belum ada transaksi di tenant #{$tenantId}.");

            return self::SUCCESS;
        }

        $this->info('== 3 transaksi terakhir (tenant #'.$tenantId.') ==');
        $rows = [];
        foreach ($txs as $tx) {
            $e = $notifier->explain($tx);
            $rows[] = [
                $tx->id,
                $tx->created_at?->format('d/m H:i:s'),
                mb_strimwidth((string) $tx->description, 0, 28, '…'),
                (string) $tx->source,
                $e['eligible'] ? 'YA → '.implode(', ', $e['recipients']) : 'TIDAK: '.$e['reason'],
            ];
        }
        $this->table(['ID', 'Dibuat', 'Keterangan', 'Sumber', 'Dikirim?'], $rows);

        if ($this->option('send')) {
            $tx = $txs->first();
            $this->info("== Kirim langsung transaksi #{$tx->id} (tanpa antrean) ==");
            $sent = $notifier->sendNow($tx);
            $sent > 0
                ? $this->info("✓ terkirim ke {$sent} perangkat — kalau popup ini muncul, masalahnya di ANTREAN (worker / restart).")
                : $this->error('✗ tidak terkirim — lihat kolom "Dikirim?" di atas & storage/logs/laravel.log (cari "FCM:" / "Push").');
        } else {
            $this->line('Tip: tambahkan --send untuk mengirim notifikasi transaksi terakhir sekarang juga.');
        }

        $this->line('Setelah deploy / ubah .env: php artisan optimize:clear && php artisan queue:restart');

        return self::SUCCESS;
    }
}
