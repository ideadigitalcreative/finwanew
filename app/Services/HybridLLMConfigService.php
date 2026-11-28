<?php

namespace App\Services;

use App\Models\AppSetting;

/**
 * Konfigurasi hybrid LLM (Hybrid.md §6A).
 *
 * Key-value di tabel app_settings (key `finwa_hybrid_llm`, value ter-encrypt)
 * dengan fallback ke config env `services.groq_llm.*`.
 *
 * Prioritas (bertingkat):
 *  1. Admin (DB app_settings) → override bila terisi
 *  2. Config services.groq_llm.* (env) → fallback bila admin kosong
 *  3. Kosong semua → hybrid LLM dianggap nonaktif → jalur rules murni
 */
class HybridLLMConfigService
{
    public const SETTING_KEY = 'finwa_hybrid_llm';

    /**
     * Konfigurasi efektif.
     *
     * @return array{enabled: bool, base_url: string, model: string, api_key: string, timeout: int}
     */
    public function getMergedConfig(): array
    {
        $defaults = [
            'enabled' => false,
            'base_url' => (string) config('services.groq_llm.base_url', ''),
            'model' => (string) config('services.groq_llm.model', ''),
            'api_key' => $this->firstEnvApiKey(),
            'timeout' => 4,
        ];

        $v = $this->readSetting();
        if ($v === null) {
            // Admin belum pernah menyimpan → flag mengikuti env (default off).
            $defaults['enabled'] = (bool) config('services.finwa_hybrid_llm.enabled', false);

            return $defaults;
        }

        if (array_key_exists('enabled', $v)) {
            $defaults['enabled'] = (bool) $v['enabled'];
        }
        if (! empty($v['base_url']) && is_string($v['base_url'])) {
            $defaults['base_url'] = trim($v['base_url']);
        }
        if (! empty($v['model']) && is_string($v['model'])) {
            $defaults['model'] = trim($v['model']);
        }
        if (! empty($v['api_key']) && is_string($v['api_key'])) {
            $defaults['api_key'] = trim($v['api_key']);
        }
        if (! empty($v['timeout']) && is_numeric($v['timeout'])) {
            $defaults['timeout'] = max(1, min(60, (int) $v['timeout']));
        }

        return $defaults;
    }

    /**
     * Hybrid LLM siap dipakai: flag menyala + konfigurasi lengkap.
     */
    public function isReady(): bool
    {
        $cfg = $this->getMergedConfig();

        return $cfg['enabled'] && $this->hasEndpoint($cfg);
    }

    /**
     * Chat LLM (pertanyaan umum) siap dipakai. Flag-nya terpisah dari ekstraksi
     * hybrid: FINWA_CHAT_LLM_ENABLED=true menyalakan chat walau hybrid mati.
     * Bila env tidak diisi → ikut flag hybrid (kompatibel dengan perilaku lama).
     */
    public function isChatReady(): bool
    {
        $cfg = $this->getMergedConfig();
        $chatFlag = config('services.finwa_chat_llm.enabled');
        $enabled = ($chatFlag === null || $chatFlag === '')
            ? $cfg['enabled']
            : filter_var($chatFlag, FILTER_VALIDATE_BOOLEAN);

        return $enabled && $this->hasEndpoint($cfg);
    }

    /** Timeout khusus chat (detik). Jawaban teks lebih panjang dari ekstraksi JSON. */
    public function chatTimeout(): int
    {
        return max(1, min(60, (int) config('services.finwa_chat_llm.timeout', 8)));
    }

    private function hasEndpoint(array $cfg): bool
    {
        return $cfg['base_url'] !== '' && $cfg['model'] !== '' && $cfg['api_key'] !== '';
    }

    /**
     * @param  array{enabled?: bool, base_url?: string, model?: string, api_key?: string, timeout?: int}  $payload
     */
    public function save(array $payload): void
    {
        $current = $this->readSetting() ?? [];

        if (array_key_exists('enabled', $payload)) {
            $current['enabled'] = (bool) $payload['enabled'];
        }
        if (array_key_exists('base_url', $payload)) {
            $current['base_url'] = self::normalizeBaseUrl((string) $payload['base_url']);
        }
        if (array_key_exists('model', $payload)) {
            $current['model'] = trim((string) $payload['model']);
        }
        // API key write-only: hanya diganti bila diisi (tidak pernah dikosongkan
        // secara tidak sengaja dari form — biarkan kosong = pertahankan key lama).
        if (! empty($payload['api_key']) && is_string($payload['api_key'])) {
            $current['api_key'] = trim($payload['api_key']);
        }
        if (array_key_exists('timeout', $payload)) {
            $current['timeout'] = max(1, min(60, (int) $payload['timeout']));
        }

        AppSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $current],
        );
    }

    /**
     * Payload aman untuk halaman admin: API key hanya mask (write-only, §6A).
     *
     * @return array{enabled: bool, base_url: string, model: string, api_key_mask: string, api_key_set: bool, timeout: int}
     */
    public function getAdminDisplayPayload(): array
    {
        $cfg = $this->getMergedConfig();

        return [
            'enabled' => $cfg['enabled'],
            'base_url' => $cfg['base_url'],
            'model' => $cfg['model'],
            'api_key_mask' => $cfg['api_key'] === '' ? '' : $this->maskKey($cfg['api_key']),
            'api_key_set' => $cfg['api_key'] !== '',
            'timeout' => $cfg['timeout'],
        ];
    }

    /**
     * Normalisasi sesuai §6A: tanpa slash akhir, tanpa /chat/completions,
     * wajib https:// (http:// hanya untuk localhost/IP lokal).
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = preg_replace('#/chat/completions/?$#i', '', $url);
        $url = rtrim((string) $url, '/');

        return $url;
    }

    public static function isValidBaseUrl(string $url): bool
    {
        if ($url === '') {
            return true; // kosong = pakai env fallback
        }
        if (! preg_match('#^https://#i', $url)) {
            // http:// diizinkan untuk host lokal dan alamat IP (proxy
            // self-hosted sering tanpa TLS); domain publik tetap wajib https.
            if (preg_match('#^http://(localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\])(:\d+)?(/|$)#i', $url)) {
                return true;
            }
            if (preg_match('#^http://\d{1,3}(\.\d{1,3}){3}(:\d+)?(/|$)#', $url)) {
                return true;
            }

            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('•', max(1, $len));
        }

        return '••••'.substr($key, -4);
    }

    private function readSetting(): ?array
    {
        // Lewat model instance → cast `encrypted:array` ikut diterapkan
        // (query builder biasa mengembalikan string ter-encrypt mentah).
        $setting = AppSetting::query()->where('key', self::SETTING_KEY)->first();
        if ($setting === null) {
            return null;
        }

        $value = $setting->value;

        return is_array($value) ? $value : null;
    }

    private function firstEnvApiKey(): string
    {
        $keysRaw = (string) config('services.groq_llm.api_keys', '');
        foreach (array_map('trim', explode(',', $keysRaw)) as $key) {
            if ($key !== '') {
                return $key;
            }
        }

        return '';
    }
}
