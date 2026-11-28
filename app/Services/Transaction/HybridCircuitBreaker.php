<?php

namespace App\Services\Transaction;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit breaker untuk LLM hybrid (Hybrid.md Fase 3).
 *
 * 3 kegagalan beruntun dalam 1 menit → buka sirkuit selama 5 menit:
 * hybridAvailable() menolak → LLM dilewati → jalur rules murni (bot tidak
 * pernah diam). Keberhasilan apa pun me-reset penghitung kegagalan.
 *
 * State di Cache (tanpa DB) supaya aman untuk queue worker multi-proses.
 * Saat sirkuit terbuka, setiap pembukaan dicatat sebagai warning sekali.
 */
class HybridCircuitBreaker
{
    public const FAILURE_THRESHOLD = 3;   // kegagalan beruntun sebelum buka
    public const WINDOW_SECONDS = 60;     // jendela penghitungan kegagalan
    public const COOLDOWN_SECONDS = 300;  // durasi sirkuit terbuka (5 menit)

    private string $keyFailures;
    private string $keyOpen;

    /**
     * @param  string  $scope  'hybrid_llm' (ekstraksi transaksi) atau 'chat_llm'
     *                         (pertanyaan umum) — state terpisah per jalur.
     */
    public function __construct(string $scope = 'hybrid_llm')
    {
        $this->keyFailures = "{$scope}:breaker_failures";
        $this->keyOpen = "{$scope}:breaker_open";
    }

    /**
     * Sirkuit terbuka? (true → LLM wajib dilewati, jalur rules murni)
     */
    public function isOpen(): bool
    {
        return (bool) Cache::get($this->keyOpen, false);
    }

    /**
     * Catat keberhasilan LLM → reset penghitung kegagalan.
     */
    public function recordSuccess(): void
    {
        Cache::forget($this->keyFailures);
    }

    /**
     * Catat kegagalan LLM (error/timeout/JSON rusak) → buka sirkuit
     * bila ambang tercapai.
     */
    public function recordFailure(): void
    {
        $now = now()->getTimestamp();

        // Buang kegagalan yang sudah keluar dari jendela 1 menit.
        $failures = array_values(array_filter(
            (array) Cache::get($this->keyFailures, []),
            fn ($ts) => ($now - (int) $ts) < self::WINDOW_SECONDS,
        ));
        $failures[] = $now;

        if (count($failures) >= self::FAILURE_THRESHOLD) {
            Cache::put($this->keyOpen, true, self::COOLDOWN_SECONDS);
            Cache::forget($this->keyFailures);
            Log::warning('Hybrid: circuit breaker TERBUKA — LLM dilewati sementara', [
                'failures' => count($failures),
                'threshold' => self::FAILURE_THRESHOLD,
                'cooldown_seconds' => self::COOLDOWN_SECONDS,
            ]);

            return;
        }

        Cache::put($this->keyFailures, $failures, self::WINDOW_SECONDS);
    }
}
