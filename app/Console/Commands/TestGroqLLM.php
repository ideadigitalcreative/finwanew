<?php

namespace App\Console\Commands;

use App\Services\GroqLLMService;
use Illuminate\Console\Command;

class TestGroqLLM extends Command
{
    protected $signature = 'groq:test {--all : Jalankan semua test sekaligus}';

    protected $description = 'Test koneksi dan fungsi Groq LLM (Llama via Groq)';

    public function handle(): int
    {
        $this->info('');
        $this->info('====================================');
        $this->info('  🧪 GROQ LLM TEST SUITE');
        $this->info('====================================');
        $this->info('');

        $groq = new GroqLLMService;

        // 1. Cek konfigurasi
        $this->info('📋 [1/5] Cek Konfigurasi...');
        $this->line('   Base URL : '.config('services.groq_llm.base_url'));
        $this->line('   Model    : '.config('services.groq_llm.model'));
        $this->line('   Timeout  : '.config('services.groq_llm.timeout').'s');
        $this->line('   Enabled  : '.(config('services.groq_llm.enabled') ? 'Ya' : 'Tidak'));

        $keysRaw = config('services.groq_llm.api_keys', '');
        $keys = array_filter(array_map('trim', explode(',', $keysRaw)));
        $this->line('   API Keys : '.count($keys).' key(s)');
        foreach ($keys as $i => $key) {
            $masked = substr($key, 0, 8).'...'.substr($key, -4);
            $this->line("             [{$i}] {$masked}");
        }
        $this->info('');

        if (! $groq->isAvailable()) {
            $this->error('❌ Groq TIDAK tersedia! Cek GROQ_LLM_API_KEYS dan GROQ_LLM_ENABLED di .env');

            return 1;
        }
        $this->info('   ✅ Konfigurasi OK');
        $this->info('');

        $runAll = $this->option('all');

        // 2. Test koneksi dasar (raw HTTP call untuk debug)
        $this->info('🔌 [2/5] Test Koneksi Dasar...');

        $baseUrl = rtrim(config('services.groq_llm.base_url', 'https://api.groq.com/openai/v1'), '/');
        $model = config('services.groq_llm.model', 'llama-3.1-8b-instant');
        $firstKey = $keys[0] ?? '';

        $this->line("   Calling: {$baseUrl}/chat/completions");
        $this->line("   Model  : {$model}");
        $this->line('   Key    : '.substr($firstKey, 0, 8).'...'.substr($firstKey, -4));

        $start = microtime(true);
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$firstKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Jawab singkat: 1+1 = ?'],
                    ],
                    'max_tokens' => 20,
                ]);

            $elapsed = round((microtime(true) - $start) * 1000);
            $status = $response->status();
            $body = $response->body();

            $this->line("   Status : {$status}");

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? '(empty)';
                $this->info("   ✅ Koneksi berhasil ({$elapsed}ms)");
                $this->line("   Response: {$content}");
            } else {
                $this->error("   ❌ API Error ({$elapsed}ms)");
                $this->error("   Status: {$status}");
                $this->error('   Body: '.mb_substr($body, 0, 500));

                if ($status === 401) {
                    $this->warn('   💡 API key tidak valid. Cek di https://console.groq.com/keys');
                } elseif ($status === 429) {
                    $this->warn('   💡 Rate limited. Coba lagi nanti atau gunakan key lain.');
                } elseif ($status === 404) {
                    $this->warn("   💡 Model '{$model}' tidak ditemukan. Cek model yang tersedia di Groq.");
                }

                return 1;
            }
        } catch (\Exception $e) {
            $elapsed = round((microtime(true) - $start) * 1000);
            $this->error("   ❌ Connection Error ({$elapsed}ms)");
            $this->error('   Error: '.$e->getMessage());

            if (str_contains($e->getMessage(), 'SSL')) {
                $this->warn('   💡 SSL error. Coba: sudo apt install ca-certificates -y');
            } elseif (str_contains($e->getMessage(), 'resolve')) {
                $this->warn('   💡 DNS error. Coba: curl -I https://api.groq.com dari VPS');
            } elseif (str_contains($e->getMessage(), 'timed out')) {
                $this->warn('   💡 Timeout. Cek firewall: curl https://api.groq.com/openai/v1/models');
            }

            return 1;
        }
        $this->info('');

        // 3. Test jawab pertanyaan keuangan
        if ($runAll || $this->confirm('🧠 [3/5] Test Jawab Pertanyaan Keuangan?', true)) {
            $questions = [
                'Tips mengatur keuangan untuk mahasiswa?',
                'Apa bedanya tabungan dan deposito?',
                'Gimana cara budgeting 50/30/20?',
            ];

            $q = $questions[array_rand($questions)];
            $this->info("   Pertanyaan: \"{$q}\"");
            $this->line('   Menunggu response...');

            $start = microtime(true);
            $answer = $groq->answerFinancialQuestion($q);
            $elapsed = round((microtime(true) - $start) * 1000);

            if ($answer) {
                $this->info("   ✅ Berhasil ({$elapsed}ms)");
                $this->info('');
                $this->line($this->indentText($answer, '   '));
            } else {
                $this->error("   ❌ Gagal ({$elapsed}ms)");
            }
            $this->info('');
        }

        // 4. Test classify intent
        if ($runAll || $this->confirm('🎯 [4/5] Test Classify Intent?', true)) {
            $testCases = [
                ['msg' => 'makan bakso 15rb',                   'expected' => 'transaction'],
                ['msg' => 'berapa pengeluaran bulan ini?',       'expected' => 'query'],
                ['msg' => 'halo selamat pagi',                   'expected' => 'greeting'],
                ['msg' => 'tips menabung buat anak kos',         'expected' => 'faq'],
                ['msg' => 'cuaca hari ini bagaimana?',           'expected' => 'irrelevant'],
            ];

            $correct = 0;
            foreach ($testCases as $tc) {
                $start = microtime(true);
                $result = $groq->classifyIntent($tc['msg']);
                $elapsed = round((microtime(true) - $start) * 1000);

                $intent = $result['intent'] ?? 'NULL';
                $conf = isset($result['confidence']) ? round($result['confidence'], 2) : '?';
                $match = ($intent === $tc['expected']);
                $icon = $match ? '✅' : '⚠️';

                if ($match) {
                    $correct++;
                }

                $this->line("   {$icon} \"{$tc['msg']}\"");
                $this->line("      Expected: {$tc['expected']} | Got: {$intent} (conf: {$conf}) [{$elapsed}ms]");
            }

            $total = count($testCases);
            $pct = round(($correct / $total) * 100);
            $this->info('');
            $this->info("   Akurasi: {$correct}/{$total} ({$pct}%)");
            $this->info('');
        }

        // 5. Test generate insight
        if ($runAll || $this->confirm('📊 [5/5] Test Generate Financial Insight?', true)) {
            $sampleData = [
                'period' => 'Maret 2026',
                'total_income' => 8500000,
                'total_expense' => 6200000,
                'net_cashflow' => 2300000,
                'transaction_count' => 47,
                'prev_month_income' => 8000000,
                'prev_month_expense' => 5800000,
                'top_expense_categories' => [
                    ['category' => 'Makanan & Minuman', 'amount' => 2100000, 'count' => 28],
                    ['category' => 'Transportasi', 'amount' => 1500000, 'count' => 12],
                    ['category' => 'Hiburan', 'amount' => 800000, 'count' => 5],
                ],
            ];

            $this->info('   Data sample: Income 8.5jt, Expense 6.2jt, 47 transaksi');
            $this->line('   Menunggu response...');

            $start = microtime(true);
            $insight = $groq->generateFinancialInsight($sampleData);
            $elapsed = round((microtime(true) - $start) * 1000);

            if ($insight) {
                $this->info("   ✅ Berhasil ({$elapsed}ms)");
                $this->info('');
                $this->line($this->indentText($insight, '   '));
            } else {
                $this->error("   ❌ Gagal ({$elapsed}ms)");
            }
            $this->info('');
        }

        $this->info('====================================');
        $this->info('  ✅ TEST SELESAI');
        $this->info('====================================');
        $this->info('');

        return 0;
    }

    protected function indentText(string $text, string $indent = '  '): string
    {
        return $indent.str_replace("\n", "\n{$indent}", $text);
    }
}
