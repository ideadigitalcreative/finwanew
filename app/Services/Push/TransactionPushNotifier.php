<?php

namespace App\Services\Push;

use App\Jobs\SendTransactionPush;
use App\Models\DeviceToken;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi push "transaksi baru" ke anggota tenant.
 *
 * Alur: Transaction::created → queue() mengumpulkan ID per (tenant, pencatat) →
 * job tertunda {batch_window} detik → flush() mengirim SATU notifikasi
 * (detail bila 1 transaksi, ringkasan bila banyak — batch / struk).
 */
class TransactionPushNotifier
{
    public function __construct(private FcmClient $fcm = new FcmClient) {}

    /**
     * Dipanggil observer saat transaksi dibuat.
     */
    public function queue(Transaction $tx, ?int $authUserId = null): void
    {
        if (! config('finwa_push.enabled') || ! $this->shouldNotify($tx)) {
            return;
        }

        $actorId = $this->resolveActorId($tx, $authUserId);
        $inApp = in_array((string) $tx->source, (array) config('finwa_push.in_app_sources', []), true);
        $key = "{$tx->tenant_id}:".($actorId ?? 'x');

        $pending = (array) Cache::get("push_pending:{$key}", []);
        $pending[] = ['id' => $tx->id, 'actor' => $actorId, 'in_app' => $inApp];
        Cache::put("push_pending:{$key}", $pending, 300);

        // JALUR CEPAT: kirim begitu job / request yang mencatat transaksi SELESAI
        // (di proses yang sama). Dulu notifikasi dijadwalkan sebagai job baru yang
        // harus antre di belakang job pesan (worker hanya 1) → popup telat ±10 dtk.
        // Batch / struk tetap 1 notifikasi karena dikirim setelah semua baris tercatat.
        self::deferFlush($key);

        // CADANGAN: bila proses berhenti sebelum jalur cepat jalan, job ini mengirim.
        // Bila jalur cepat sudah mengirim, job ini tidak melakukan apa-apa.
        $window = max(0, (int) config('finwa_push.batch_window_seconds', 1));
        if (Cache::add("push_scheduled:{$key}", 1, $window + 30)) {
            SendTransactionPush::dispatch($key)->delay(now()->addSeconds(max($window, 5)))->afterCommit();
        }
    }

    /** @var array<string, true> kunci yang menunggu dikirim di akhir job/request ini */
    private static array $deferred = [];

    /** @var array<int, true> instance aplikasi yang sudah dipasangi hook */
    private static array $hookedApps = [];

    private static function deferFlush(string $key): void
    {
        self::$deferred[$key] = true;

        $appId = spl_object_id(app());
        if (isset(self::$hookedApps[$appId])) {
            return;
        }
        self::$hookedApps[$appId] = true;

        $run = static function (): void {
            $keys = array_keys(self::$deferred);
            self::$deferred = [];
            foreach ($keys as $k) {
                try {
                    app(self::class)->flush($k);
                } catch (\Throwable $e) {
                    Log::warning('Push: kirim cepat gagal (job cadangan akan mencoba)', ['key' => $k, 'error' => $e->getMessage()]);
                }
            }
        };

        // Queue worker: setelah job (mis. ProcessIncomingMessage) selesai / gagal
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessed::class, $run);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobFailed::class, $run);
        // Request web (dashboard / API) & perintah artisan: setelah respons dikirim
        app()->terminating($run);
    }

    /**
     * Kirim notifikasi untuk semua transaksi yang terkumpul pada kunci ini.
     *
     * @return int jumlah perangkat yang berhasil dikirimi
     */
    public function flush(string $key): int
    {
        // Hapus penanda jadwal DULU, baru ambil antrean: transaksi yang masuk di
        // antara keduanya tetap ikut terkirim sekarang, dan yang masuk setelahnya
        // menjadwalkan job baru (tidak ada transaksi yang tertinggal).
        Cache::forget("push_scheduled:{$key}");
        $pending = (array) Cache::pull("push_pending:{$key}", []);

        if ($pending === [] || $this->inQuietHours()) {
            return 0;
        }

        $txs = Transaction::with(['category', 'whatsappNumber.user'])
            ->whereIn('id', array_column($pending, 'id'))
            ->orderBy('id')
            ->get();
        if ($txs->isEmpty()) {
            return 0; // sudah dihapus (mis. "batal") sebelum notifikasi terkirim
        }

        $first = $pending[0];
        $actorId = $first['actor'] ?? null;
        $allInApp = collect($pending)->every(fn ($p) => ! empty($p['in_app']));

        $recipients = $this->recipients((int) $txs->first()->tenant_id, $actorId, $allInApp);
        if ($recipients->isEmpty()) {
            return 0;
        }

        $actorName = $this->actorName($txs->first(), $actorId);
        $sent = 0;

        foreach ($recipients as $user) {
            [$title, $body, $data] = $this->buildMessage($txs, $actorName, (bool) $user->push_hide_amount);

            foreach ($user->deviceTokens as $device) {
                $result = $this->fcm->send($device->token, $title, $body, $data);
                if ($result === FcmClient::RESULT_OK) {
                    $sent++;
                    $device->forceFill(['last_seen_at' => now()])->save();
                } elseif ($result === FcmClient::RESULT_INVALID_TOKEN) {
                    $device->delete();
                }
            }
        }

        Log::info('Push transaksi terkirim', [
            'key' => $key,
            'transactions' => $txs->count(),
            'devices' => $sent,
            // Jeda dari transaksi pertama tercatat sampai notifikasi dikirim ke FCM
            'delay_ms' => (int) $txs->first()->created_at?->diffInMilliseconds(now()),
        ]);

        return $sent;
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    public function buildMessage(Collection $txs, ?string $actorName, bool $hideAmount): array
    {
        $who = $actorName ? "{$actorName}: " : '';
        $fmt = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');

        if ($txs->count() === 1) {
            $tx = $txs->first();
            $isIncome = $tx->type === 'income';
            $title = $isIncome ? '💰 Pemasukan baru' : '💸 Pengeluaran baru';
            $desc = \App\Helpers\KeywordNormalizer::stripAmountFromDescription((string) $tx->description);
            $desc = mb_strimwidth(trim(strtok($desc, "\n") ?: $desc), 0, 60, '…');
            $body = $who.$desc
                .($hideAmount ? '' : ' — '.$fmt($tx->amount))
                .($tx->category?->name ? " ({$tx->category->name})" : '');

            return [$title, $body, ['type' => 'transaction', 'transaction_id' => (string) $tx->id, 'url' => '/transactions']];
        }

        $income = $txs->where('type', 'income')->sum('amount');
        $expense = $txs->where('type', 'expense')->sum('amount');
        $title = "🧾 {$txs->count()} transaksi baru";
        $parts = [];
        if (! $hideAmount) {
            if ($expense > 0) {
                $parts[] = 'keluar '.$fmt($expense);
            }
            if ($income > 0) {
                $parts[] = 'masuk '.$fmt($income);
            }
        }
        $body = trim(($actorName ? "{$actorName} mencatat {$txs->count()} transaksi" : "{$txs->count()} transaksi dicatat")
            .($parts ? ' · '.implode(' · ', $parts) : ''));

        return [$title, $body, ['type' => 'transactions', 'count' => (string) $txs->count(), 'url' => '/transactions']];
    }

    /**
     * Diagnosa: kenapa transaksi ini (tidak) dinotifikasi & ke siapa.
     *
     * @return array{eligible: bool, reason: string|null, actor_id: int|null, recipients: list<string>}
     */
    public function explain(Transaction $tx): array
    {
        $reason = null;
        if (! config('finwa_push.enabled')) {
            $reason = 'FINWA_PUSH_ENABLED=false';
        } elseif (($tx->status ?? 'confirmed') !== 'confirmed') {
            $reason = "status transaksi '{$tx->status}' (hanya 'confirmed' yang dikirim)";
        } elseif (in_array((string) $tx->type, (array) config('finwa_push.skip_types', []), true)) {
            $reason = "tipe '{$tx->type}' dilewati (pindah saldo internal)";
        } elseif (in_array((string) $tx->source, (array) config('finwa_push.skip_sources', []), true)) {
            $reason = "sumber '{$tx->source}' dilewati";
        } elseif ($this->inQuietHours()) {
            $reason = 'sedang jam tenang ('.config('finwa_push.quiet_hours').')';
        }

        $actorId = $this->resolveActorId($tx, null);
        $inApp = in_array((string) $tx->source, (array) config('finwa_push.in_app_sources', []), true);
        $recipients = $this->recipients((int) $tx->tenant_id, $actorId, $inApp)
            ->map(fn (User $u) => "{$u->name} ({$u->deviceTokens->count()} perangkat)")
            ->all();

        if ($reason === null && $recipients === []) {
            $reason = 'tidak ada penerima (anggota tenant dengan push aktif & perangkat terdaftar)';
        }

        return ['eligible' => $reason === null, 'reason' => $reason, 'actor_id' => $actorId, 'recipients' => $recipients];
    }

    /** Kirim langsung (tanpa antrean) untuk satu transaksi — dipakai diagnosa. */
    public function sendNow(Transaction $tx): int
    {
        $key = "{$tx->tenant_id}:diag:".$tx->id;
        Cache::put("push_pending:{$key}", [['id' => $tx->id, 'actor' => $this->resolveActorId($tx, null), 'in_app' => false]], 60);

        return $this->flush($key);
    }

    private function shouldNotify(Transaction $tx): bool
    {
        if (($tx->status ?? 'confirmed') !== 'confirmed') {
            return false;
        }
        if (in_array((string) $tx->type, (array) config('finwa_push.skip_types', []), true)) {
            return false;
        }
        // Saldo awal / penyesuaian saldo bukan transaksi baru
        if ($tx->isBalanceAdjustment()) {
            return false;
        }

        return ! in_array((string) $tx->source, (array) config('finwa_push.skip_sources', []), true);
    }

    private function resolveActorId(Transaction $tx, ?int $authUserId): ?int
    {
        if ($tx->user_whatsapp_number_id) {
            $userId = \App\Models\UserWhatsAppNumber::whereKey($tx->user_whatsapp_number_id)->value('user_id');
            if ($userId) {
                return (int) $userId;
            }
        }

        return $authUserId;
    }

    /**
     * Anggota tenant (aktif) yang push-nya menyala & punya perangkat terdaftar.
     *
     * @return Collection<int, User>
     */
    private function recipients(int $tenantId, ?int $actorId, bool $allInApp): Collection
    {
        $users = User::query()
            ->where('push_enabled', true)
            ->whereHas('deviceTokens')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                    ->orWhereHas('tenants', fn ($t) => $t->where('tenants.id', $tenantId)->where('user_tenants.is_active', true));
            })
            ->with('deviceTokens')
            ->get();

        // Pencatat tidak dikirimi bila ia mencatat langsung di aplikasi (ia sudah melihatnya),
        // atau bila notify_actor dimatikan.
        if ($actorId !== null && ($allInApp || ! config('finwa_push.notify_actor', true))) {
            $users = $users->reject(fn (User $u) => $u->id === $actorId);
        }

        return $users->values();
    }

    private function actorName(Transaction $tx, ?int $actorId): ?string
    {
        $name = $tx->whatsappNumber?->name ?: $tx->whatsappNumber?->user?->name;
        if (! $name && $actorId) {
            $name = User::whereKey($actorId)->value('name');
        }

        return $name ? mb_strimwidth(trim($name), 0, 30, '…') : null;
    }

    private function inQuietHours(): bool
    {
        $range = trim((string) config('finwa_push.quiet_hours', ''));
        if (! preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $range, $m)) {
            return false;
        }

        $now = Carbon::now('Asia/Jakarta');
        $minutes = $now->hour * 60 + $now->minute;
        $start = (int) $m[1] * 60 + (int) $m[2];
        $end = (int) $m[3] * 60 + (int) $m[4];

        return $start <= $end
            ? ($minutes >= $start && $minutes < $end)
            : ($minutes >= $start || $minutes < $end); // melewati tengah malam
    }

    /** Daftarkan / perbarui token perangkat untuk user. */
    public static function registerToken(User $user, string $token, string $platform = 'android', ?string $deviceName = null): DeviceToken
    {
        // Token pindah tangan (HP dipakai login akun lain) → pindahkan ke user ini
        return DeviceToken::updateOrCreate(
            ['token' => $token],
            ['user_id' => $user->id, 'platform' => $platform, 'device_name' => $deviceName, 'last_seen_at' => now()]
        );
    }
}
