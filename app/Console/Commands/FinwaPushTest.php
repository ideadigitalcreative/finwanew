<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Push\FcmClient;
use Illuminate\Console\Command;

/**
 * Kirim notifikasi uji ke semua perangkat milik satu user.
 *
 *   php artisan finwa:push-test user@email.com
 *   php artisan finwa:push-test 6281234567890
 */
class FinwaPushTest extends Command
{
    protected $signature = 'finwa:push-test {user : Email, ID, nomor WhatsApp, atau nama user}';

    protected $description = 'Kirim notifikasi push uji (FCM) ke perangkat Android milik user';

    public function handle(FcmClient $fcm): int
    {
        $this->line('Push aktif (FINWA_PUSH_ENABLED): '.(config('finwa_push.enabled') ? 'YA' : 'TIDAK — notifikasi transaksi tidak akan dikirim'));

        $this->line('FIREBASE_CREDENTIALS: '.(str_starts_with(trim((string) config('finwa_push.credentials')), '{')
            ? '(isi JSON langsung)'
            : (string) config('finwa_push.credentials').'  →  '.base_path()));

        if (($problem = $fcm->diagnose()) !== null || ! $fcm->isConfigured()) {
            $this->error('FCM belum siap: '.($problem ?? 'kredensial tidak lengkap'));
            $this->line('Panduan: docs/ANDROID_PUSH_FCM.md bagian 2. Setelah ubah .env jalankan: php artisan optimize:clear');

            return self::FAILURE;
        }
        $this->line('Firebase project: '.$fcm->projectId());

        $q = (string) $this->argument('user');
        $user = User::query()
            ->where('email', $q)
            ->orWhere('whatsapp_number', $q)
            ->when(ctype_digit($q) && strlen($q) < 10, fn ($b) => $b->orWhere('id', (int) $q))
            ->first();

        if (! $user) {
            // Cari berdasarkan nama / sebagian email / nomor (mis. "haerul").
            // Kata pertama ≥3 huruf dari input ("Haerul_hadi_email@domain.com" → "Haerul").
            $local = preg_replace('/@.*$/', '', $q);
            preg_match('/\p{L}{3,}/u', $local, $w);
            $word = $w[0] ?? '';
            $digits = preg_replace('/\D/', '', $q);

            $matches = collect();
            if ($word !== '' || strlen($digits) >= 6) {
                $matches = User::query()
                    ->where(function ($b) use ($word, $digits) {
                        if ($word !== '') {
                            $b->orWhere('name', 'like', "%{$word}%")->orWhere('email', 'like', "%{$word}%");
                        }
                        if (strlen($digits) >= 6) {
                            $b->orWhere('whatsapp_number', 'like', "%{$digits}%");
                        }
                    })
                    ->limit(10)
                    ->get(['id', 'name', 'email', 'whatsapp_number']);
            }

            if ($matches->count() === 1) {
                $user = $matches->first();
                $this->line("Ditemukan berdasarkan pencarian: {$user->name} ({$user->email})");
            } else {
                $this->error("User '{$q}' tidak ditemukan.");
                if ($matches->isNotEmpty()) {
                    $this->line('Mungkin maksud Anda (jalankan ulang dengan email / ID):');
                    $this->table(['ID', 'Nama', 'Email', 'WhatsApp'], $matches->map(fn ($u) => [$u->id, $u->name, $u->email, $u->whatsapp_number])->all());
                } else {
                    $this->line('Coba dengan nama, mis.: php artisan finwa:push-test haerul');
                }

                return self::FAILURE;
            }
        }

        $devices = $user->deviceTokens;
        if ($devices->isEmpty()) {
            $this->warn("{$user->name} belum punya perangkat terdaftar. Buka aplikasi Android & login dulu.");

            return self::FAILURE;
        }

        $ok = 0;
        foreach ($devices as $device) {
            $result = $fcm->send($device->token, '🔔 Tes notifikasi FinWa', 'Kalau ini muncul, notifikasi transaksi sudah siap!', ['type' => 'test', 'url' => '/dashboard']);
            $label = $device->device_name ?: $device->platform;
            if ($result === FcmClient::RESULT_OK) {
                $ok++;
                $this->info("✓ {$label}");
            } elseif ($result === FcmClient::RESULT_INVALID_TOKEN) {
                $device->delete();
                $this->warn("✗ {$label}: token tidak berlaku (dihapus) — buka ulang aplikasi untuk mendaftar lagi");
            } else {
                $this->error("✗ {$label}: gagal (lihat storage/logs/laravel.log, cari 'FCM:')");
            }
        }

        return $ok > 0 ? self::SUCCESS : self::FAILURE;
    }
}
