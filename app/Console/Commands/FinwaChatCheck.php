<?php

namespace App\Console\Commands;

use App\Services\AI\FinWaChatService;
use App\Services\HybridLLMConfigService;
use App\Services\Transaction\HybridCircuitBreaker;
use App\Services\WhatsApp\MessageSignals;
use Illuminate\Console\Command;

/**
 * Diagnosa Chat LLM di server: kenapa pertanyaan umum ("cara agar bisa menabung ?")
 * dijawab Panduan, bukan AI. Tidak pernah mencetak API key.
 *
 *   php artisan finwa:chat-check "cara agar bisa menabung ?"
 */
class FinwaChatCheck extends Command
{
    protected $signature = 'finwa:chat-check {message? : Pesan yang diuji (default: "cara agar bisa menabung ?")} {--no-call : Jangan panggil LLM} {--models : Tampilkan daftar model yang tersedia di penyedia LLM utama}';

    protected $description = 'Cek kesiapan Chat LLM & cara pesan diarahkan (tanpa membocorkan API key)';

    public function handle(): int
    {
        $message = (string) ($this->argument('message') ?: 'cara agar bisa menabung ?');
        $config = new HybridLLMConfigService;
        $cfg = $config->getMergedConfig();
        $chatFlag = config('services.finwa_chat_llm.enabled');
        $signals = MessageSignals::analyze(mb_strtolower($message));
        $breakerOpen = (new HybridCircuitBreaker('chat_llm'))->isOpen();

        $this->info('== Konfigurasi ==');
        $this->table(['Item', 'Nilai'], [
            ['config di-cache (config:cache)', app()->configurationIsCached() ? 'YA — jalankan php artisan optimize:clear setelah ubah .env' : 'tidak'],
            ['FINWA_CHAT_LLM_ENABLED', $chatFlag === null || $chatFlag === '' ? '(kosong → ikut flag hybrid)' : var_export($chatFlag, true)],
            ['Flag hybrid (admin/env)', $cfg['enabled'] ? 'ON' : 'OFF'],
            ['Base URL', $cfg['base_url'] !== '' ? $cfg['base_url'] : '(KOSONG)'],
            ['Model', $cfg['model'] !== '' ? $cfg['model'] : '(KOSONG)'],
            ['API key', $cfg['api_key'] !== '' ? 'terisi ('.strlen($cfg['api_key']).' karakter)' : '(KOSONG)'],
            ['Timeout chat', $config->chatTimeout().' detik'],
            ['Circuit breaker chat', $breakerOpen ? 'TERBUKA (LLM dilewati ±5 menit karena gagal beruntun)' : 'normal'],
            ['LLM cadangan', ($fb = \App\Services\AI\OpenAIChatClient::fallbackEndpoint($cfg)) !== null
                ? "{$fb['base_url']} · {$fb['model']}"
                : '(tidak ada — isi FINWA_LLM_FALLBACK_* atau GROQ_LLM_API_KEYS)'],
            ['>> Chat LLM siap?', $config->isChatReady() ? 'YA' : 'TIDAK'],
        ]);

        if ($this->option('models') && $cfg['base_url'] !== '' && $cfg['api_key'] !== '') {
            $this->info('== Model tersedia di '.$cfg['base_url'].' ==');
            try {
                $res = \Illuminate\Support\Facades\Http::timeout(10)
                    ->withToken($cfg['api_key'])
                    ->get(rtrim($cfg['base_url'], '/').'/models');
                $ids = collect($res->json('data', []))->pluck('id')->filter()->sort()->values();
                if ($res->successful() && $ids->isNotEmpty()) {
                    foreach ($ids as $id) {
                        $this->line(($id === $cfg['model'] ? '  → ' : '    ').$id);
                    }
                } else {
                    $this->warn('Tidak bisa membaca daftar model: HTTP '.$res->status().' '.mb_substr($res->body(), 0, 200));
                }
            } catch (\Throwable $e) {
                $this->warn('Tidak bisa membaca daftar model: '.$e->getMessage());
            }
        }

        $this->info('== Pesan: "'.$message.'" ==');
        $local = FinWaChatService::localAnswer($message);
        $this->table(['Item', 'Nilai'], [
            ['Pertanyaan nasihat keuangan?', $signals->isAdviceQuestion() ? 'YA → dijawab AI sebelum classifier' : 'tidak'],
            ['Jawaban lokal cadangan', $local !== null ? 'ada' : 'tidak ada'],
        ]);

        if (! $config->isChatReady()) {
            $this->warn('Chat LLM TIDAK siap → pertanyaan dijawab '.($local !== null ? 'jawaban lokal' : 'Panduan FinWa').'.');
            $this->line('Perbaikan: isi Hybrid LLM (base URL, model, API key) di admin, lalu set FINWA_CHAT_LLM_ENABLED=true di .env,');
            $this->line('kemudian: php artisan optimize:clear && php artisan queue:restart');

            return self::SUCCESS;
        }

        if ($this->option('no-call')) {
            return self::SUCCESS;
        }

        $this->info('== Uji panggil LLM ==');
        $start = microtime(true);
        $answer = (new FinWaChatService)->answer($message);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($answer === null) {
            $this->error("LLM GAGAL ({$ms} ms)");
            $this->line('Penyebab: '.(\App\Services\AI\OpenAIChatClient::$lastError ?? 'tidak diketahui (lihat storage/logs/laravel.log)'));
            $this->line('');
            $this->line('Petunjuk: 401/403 = API key ditolak · 404 = base URL / nama model salah · 400 = parameter tidak didukung model · 429 = limit/kuota habis · 5xx = server LLM bermasalah.');
            $this->line('Sementara itu pertanyaan keuangan umum tetap dijawab dengan jawaban lokal cadangan.');

            return self::FAILURE;
        }

        $this->line("Berhasil ({$ms} ms):");
        $this->line($answer);

        return self::SUCCESS;
    }
}
