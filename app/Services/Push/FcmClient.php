<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien Firebase Cloud Messaging HTTP v1 tanpa dependensi tambahan.
 *
 * Autentikasi: service account → JWT (RS256) → OAuth2 access token (di-cache ±55 menit).
 * https://firebase.google.com/docs/cloud-messaging/send-message
 */
class FcmClient
{
    public const RESULT_OK = 'ok';
    public const RESULT_INVALID_TOKEN = 'invalid_token'; // token harus dihapus
    public const RESULT_ERROR = 'error';                 // gangguan sementara / konfigurasi

    private ?array $credentials = null;

    public function isConfigured(): bool
    {
        $c = $this->credentials();

        return $c !== null && ! empty($c['client_email']) && ! empty($c['private_key']) && $this->projectId() !== '';
    }

    /**
     * Penjelasan kenapa FCM belum siap (untuk diagnosa), null bila siap.
     * Tidak pernah mencetak isi private key.
     */
    public function diagnose(): ?string
    {
        $raw = trim((string) config('finwa_push.credentials', ''));
        if ($raw === '') {
            return 'FIREBASE_CREDENTIALS kosong di .env.';
        }

        if (! str_starts_with($raw, '{')) {
            $path = $this->resolvePath($raw);
            if (! file_exists($path)) {
                return "File tidak ditemukan: {$path}";
            }
            if (! is_readable($path)) {
                return "File ada tapi tidak bisa dibaca oleh user PHP (".(function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?')."): {$path} — cek izin (chmod 640 / chown).";
            }
            $raw = (string) file_get_contents($path);
        }

        $json = json_decode($raw, true);
        if (! is_array($json)) {
            return 'Isi kredensial bukan JSON yang valid ('.json_last_error_msg().').';
        }
        if (isset($json['project_info']) || isset($json['client'])) {
            return 'File ini adalah google-services.json (untuk aplikasi Android), BUKAN service account. '
                .'Ambil dari Firebase Console → Project settings → Service accounts → Generate new private key.';
        }
        if (($json['type'] ?? null) !== 'service_account') {
            return 'JSON bukan service account (field "type" bukan "service_account").';
        }
        foreach (['client_email', 'private_key'] as $field) {
            if (empty($json[$field])) {
                return "Field \"{$field}\" kosong di file service account.";
            }
        }
        if (openssl_pkey_get_private((string) $json['private_key']) === false) {
            return 'private_key tidak bisa dibaca OpenSSL (file rusak / terpotong saat upload?).';
        }
        if ($this->projectId() === '') {
            return 'project_id tidak ada — isi FIREBASE_PROJECT_ID di .env.';
        }

        return null;
    }

    private function resolvePath(string $raw): string
    {
        return str_starts_with($raw, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $raw) ? $raw : base_path($raw);
    }

    public function projectId(): string
    {
        return (string) (config('finwa_push.project_id') ?: ($this->credentials()['project_id'] ?? ''));
    }

    /**
     * Kirim ke satu token perangkat.
     *
     * @param  array<string, string>  $data  payload data (semua nilai string), mis. ['url' => '/transactions/1']
     * @return string salah satu RESULT_*
     */
    public function send(string $token, string $title, string $body, array $data = []): string
    {
        if (! $this->isConfigured()) {
            Log::warning('FCM: belum dikonfigurasi (FIREBASE_CREDENTIALS)');

            return self::RESULT_ERROR;
        }

        $accessToken = $this->accessToken();
        if ($accessToken === null) {
            return self::RESULT_ERROR;
        }

        $message = [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data),
            'android' => [
                'priority' => 'high',
                // Ikon diatur di AndroidManifest (default_notification_icon), lihat docs
                'notification' => [
                    'channel_id' => (string) config('finwa_push.android_channel', 'transactions'),
                ],
            ],
        ];

        try {
            $response = Http::timeout((int) config('finwa_push.timeout', 10))
                ->withToken($accessToken)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send", ['message' => $message]);
        } catch (\Throwable $e) {
            Log::warning('FCM: koneksi gagal', ['error' => $e->getMessage()]);

            return self::RESULT_ERROR;
        }

        if ($response->successful()) {
            return self::RESULT_OK;
        }

        $status = $response->status();
        $errorCode = (string) ($response->json('error.details.0.errorCode') ?? $response->json('error.status') ?? '');

        // Token kedaluwarsa / app di-uninstall / token rusak → hapus dari DB
        if ($status === 404 || in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return self::RESULT_INVALID_TOKEN;
        }

        if ($status === 401) {
            Cache::forget($this->tokenCacheKey()); // access token basi → ambil ulang lain kali
        }

        Log::warning('FCM: gagal kirim', ['status' => $status, 'code' => $errorCode, 'body' => mb_substr($response->body(), 0, 300)]);

        return self::RESULT_ERROR;
    }

    private function accessToken(): ?string
    {
        $cached = Cache::get($this->tokenCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $c = $this->credentials();
        $now = time();
        $tokenUri = $c['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        $jwt = $this->signJwt([
            'iss' => $c['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $c['private_key']);

        if ($jwt === null) {
            return null;
        }

        try {
            $response = Http::asForm()->timeout((int) config('finwa_push.timeout', 10))->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (\Throwable $e) {
            Log::warning('FCM: gagal ambil access token', ['error' => $e->getMessage()]);

            return null;
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            Log::warning('FCM: access token ditolak', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 200)]);

            return null;
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put($this->tokenCacheKey(), $token, $ttl);

        return $token;
    }

    private function signJwt(array $claims, string $privateKey): ?string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode($claims));

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            Log::error('FCM: private key service account tidak valid');

            return null;
        }

        return $unsigned.'.'.$b64($signature);
    }

    private function credentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $raw = trim((string) config('finwa_push.credentials', ''));
        if ($raw === '') {
            return null;
        }

        if (! str_starts_with($raw, '{')) {
            $path = $this->resolvePath($raw);
            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }
            $raw = (string) file_get_contents($path);
        }

        $decoded = json_decode($raw, true);

        return $this->credentials = is_array($decoded) ? $decoded : null;
    }

    private function tokenCacheKey(): string
    {
        return 'fcm:access_token:'.md5((string) ($this->credentials()['client_email'] ?? ''));
    }
}
