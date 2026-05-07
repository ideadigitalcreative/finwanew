<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Konfigurasi runtime Gemini: gabungan .env/config + override dari Super Admin (DB).
 * Mendukung beberapa API key dengan rotasi round-robin (cache).
 */
class GeminiConfigService
{
    public const SETTING_KEY = 'gemini';

    private const CACHE_INDEX_KEY = 'gemini_api_key_rotation_index';

    /**
     * @return array{model: string, base_url: string, timeout: int, api_keys: array<int, string>}
     */
    public function getMergedConfig(): array
    {
        $defaults = [
            'model' => (string) config('services.gemini.model', 'gemini-2.5-flash'),
            'base_url' => (string) config('services.gemini.base_url', ''),
            'timeout' => (int) config('services.gemini.timeout', 60),
            'api_keys' => $this->apiKeysFromEnv(),
        ];

        $rawValue = AppSetting::query()
            ->where('key', self::SETTING_KEY)
            ->value('value');
        if (! is_string($rawValue) || trim($rawValue) === '') {
            return $defaults;
        }

        $v = null;
        try {
            $decrypted = Crypt::decryptString($rawValue);
            $decoded = json_decode($decrypted, true);
            if (is_array($decoded)) {
                $v = $decoded;
            }
        } catch (\Throwable $e) {
            Log::error('GeminiConfigService: failed to decrypt AppSetting value', [
                'setting_key' => self::SETTING_KEY,
                'error' => $e->getMessage(),
            ]);
            $v = null;
        }
        if (! is_array($v)) {
            return $defaults;
        }
        if (! empty($v['model']) && is_string($v['model'])) {
        }
        if (array_key_exists('base_url', $v) && is_string($v['base_url'])) {
            $defaults['base_url'] = trim($v['base_url']);
        }
            $defaults['base_url'] = trim($v['base_url']);
        }

        if (! empty($v['api_keys']) && is_array($v['api_keys'])) {
            $filtered = array_values(array_filter(array_map('trim', $v['api_keys']), fn ($k) => $k !== ''));
            if ($filtered !== []) {
                $defaults['api_keys'] = $filtered;
            }
        }

        return $defaults;
    }

    /**
     * @return array<int, string>
     */
    public function apiKeysFromEnv(): array
    {
        $keys = [];
        $single = trim((string) config('services.gemini.api_key', ''));
        if ($single !== '') {
            $keys[] = $single;
        }

        return array_values(array_unique($keys));
    }

    public function hasAnyApiKey(): bool
    {
        return $this->getMergedConfig()['api_keys'] !== [];
    }

    /**
     * Rotasi round-robin antar API key (mirip GroqLLMService).
     */
    public function nextRotatedApiKey(): string
    {
        $keys = $this->getMergedConfig()['api_keys'];
        $count = count($keys);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $keys[0];
        }

        $index = (int) Cache::get(self::CACHE_INDEX_KEY, 0);
        $key = $keys[$index % $count];
        Cache::put(self::CACHE_INDEX_KEY, ($index + 1) % $count, 3600);

        return $key;
    }

    /**
     * Untuk halaman admin: ringkasan tanpa mengekspos key penuh.
     *
     * @return array{model: string, base_url: string, timeout: int, key_count: int, keys_preview: array<int, array{mask: string}>}
     */
    public function getAdminDisplayPayload(): array
    {
        $cfg = $this->getMergedConfig();
        $previews = [];
        foreach ($cfg['api_keys'] as $k) {
            $previews[] = ['mask' => $this->maskKey($k)];
        }

        return [
            'model' => $cfg['model'],
            'base_url' => $cfg['base_url'],
            'timeout' => (int) config('services.gemini.timeout', 60),
            'key_count' => count($cfg['api_keys']),
            'keys_preview' => $previews,
        ];
    }

    public function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('•', min(8, $len));
        }

        return '••••'.substr($key, -4);
    }
}
