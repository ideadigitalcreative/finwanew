<?php

/**
 * AI tahap 1–3: pertanyaan umum dijawab Chat LLM, bukan Panduan statis.
 *
 * Bug produksi: "cara agar bisa menabung ?" → classifier FinWa-AI memberi label
 * `help` → job langsung mengirim "📱 Panduan FinWa" dan Chat LLM tidak pernah dipanggil.
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\WhatsApp\MessageSignals;
use App\Services\WhatsAppService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const CHAT_LLM_ANSWER = 'Sisihkan 20% gaji di awal bulan ke rekening terpisah ya!';

function chatSeedWorld(): array
{
    $tenant = Tenant::create(['name' => 'Chat', 'slug' => 'chat', 'is_active' => true]);
    $channel = Channel::create([
        'tenant_id' => $tenant->id,
        'type' => 'whatsapp',
        'channel_account' => '628999000222',
        'name' => 'Chat Channel',
        'config' => ['session_id' => 'wa_chat_1'],
        'is_active' => true,
    ]);
    User::create([
        'name' => 'Chat User',
        'email' => 'chat@example.com',
        'password' => bcrypt('password'),
        'whatsapp_number' => '6281200000001',
        'tenant_id' => $tenant->id,
    ]);
    Subscription::create([
        'tenant_id' => $tenant->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active',
    ]);
    (new CategoryManagerService)->createCategoriesForTenant($tenant->id);
    Balance::create([
        'tenant_id' => $tenant->id, 'account_name' => 'Cash', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 1000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);

    return [$tenant, $channel];
}

function chatSend(string $text, array $world): array
{
    [$tenant, $channel] = $world;
    $GLOBALS['finwa_chat_replies'] = [];
    $message = Message::create([
        'tenant_id' => $tenant->id,
        'channel_id' => $channel->id,
        'channel' => 'whatsapp',
        'channel_account' => '628999000222',
        'sender_id' => '6281200000001',
        'message_id' => 'chat-'.uniqid(),
        'type' => 'text',
        'content' => $text,
        'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return $GLOBALS['finwa_chat_replies'];
}

/** @param string $classifierIntent intent yang dijawab server FinWa-AI */
function chatFakeApis(string $classifierIntent = 'help', bool $llmOk = true): void
{
    Http::fake(function (Request $request) use ($classifierIntent, $llmOk) {
        if (str_contains($request->url(), '/chat/completions')) {
            return $llmOk
                ? Http::response(['choices' => [['message' => ['content' => CHAT_LLM_ANSWER]]]], 200)
                : Http::response(['error' => 'down'], 503);
        }
        if (str_contains($request->url(), '/process/text')) {
            return Http::response(['intent' => $classifierIntent, 'confidence' => 0.9, 'entities' => []], 200);
        }

        return Http::response(['error' => 'offline'], 500);
    });
}

beforeEach(function () {
    Cache::flush();
    config([
        'services.finwa_ai.enabled' => true,
        'services.finwa_ai.url' => 'https://finwa-ai.test',
        'finwa_reply_edit.enabled' => false,
        // Hybrid ekstraksi MATI, Chat LLM HIDUP → membuktikan flag sudah terpisah
        'services.finwa_hybrid_llm.enabled' => false,
        'services.finwa_chat_llm.enabled' => true,
        'services.groq_llm.base_url' => 'https://llm.test/v1',
        'services.groq_llm.model' => 'test-model',
        'services.groq_llm.api_keys' => 'test-key',
    ]);

    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($sessionId, $to, $text) {
        $GLOBALS['finwa_chat_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['finwa_chat_replies'])]];
    });

    $this->world = chatSeedWorld();
});

it('menjawab pertanyaan menabung dengan LLM walau classifier bilang help', function () {
    chatFakeApis('help');

    $replies = chatSend('cara agar bisa menabung ?', $this->world);

    expect(implode("\n", $replies))->toContain(CHAT_LLM_ANSWER)
        ->and(implode("\n", $replies))->not->toContain('Panduan FinWa');
});

it('menjawab pertanyaan nasihat keuangan dengan LLM', function (string $text) {
    chatFakeApis('query');

    expect(implode("\n", chatSend($text, $this->world)))->toContain(CHAT_LLM_ANSWER);
})->with([
    'apa itu dana darurat',
    'tips hemat bulan ini',
    'gimana cara atur keuangan keluarga?',
    'nabung itu penting ga?',
]);

it('pertanyaan tentang aplikasi tetap dijawab Panduan/FAQ, bukan LLM', function (string $text) {
    chatFakeApis('help');

    $joined = implode("\n", chatSend($text, $this->world));
    expect($joined)->not->toContain(CHAT_LLM_ANSWER)
        ->and($joined)->toMatch('/Panduan FinWa|Cara Menggunakan FinWa|Fitur/u');
})->with(['bantuan', 'cara pakai finwa', 'fitur finwa apa aja']);

it('LLM gagal & topik tidak dikenal → classifier help tetap dibalas Panduan (tidak diam)', function () {
    chatFakeApis('help', llmOk: false);

    expect(implode("\n", chatSend('bagaimana cara jadi kaya raya ?', $this->world)))->toContain('Panduan FinWa');
});

it('Chat LLM mati → LLM tidak dipanggil sama sekali', function () {
    config(['services.finwa_chat_llm.enabled' => false]);
    chatFakeApis('help');

    chatSend('cara agar bisa menabung ?', $this->world);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/chat/completions'));
});

// ── Unit: deteksi pertanyaan nasihat ──

it('mengenali pertanyaan nasihat keuangan', function (string $text, bool $expected) {
    expect(MessageSignals::analyze(mb_strtolower($text))->isAdviceQuestion())->toBe($expected);
})->with([
    ['cara agar bisa menabung ?', true],
    ['apa itu dana darurat', true],
    ['tips hemat bulan ini', true],
    ['nabung itu penting ga?', true],
    ['mending nabung emas atau deposito?', true],
    ['cara tambah dompet', false],           // pertanyaan aplikasi → FAQ
    ['berapa pengeluaran bulan ini?', false], // pertanyaan data → laporan
    ['cara investasi 1 juta', false],        // bawa nominal → jalur lama
    ['beli kopi 25rb', false],
    ['gaji bulan ini?', false],              // data, bukan nasihat
]);

// ── Tahap 7–8 ──

it('prompt chat berisi batasan topik, larangan nasihat investasi, & perintah asli', function () {
    $prompt = \App\Services\AI\FinWaChatService::systemPrompt();

    expect($prompt)->toContain('cuma bisa bantu soal keuangan')
        ->and($prompt)->toContain('JANGAN menyuruh membeli/menjual')
        ->and($prompt)->toContain('"cek saldo"')
        ->and($prompt)->toContain(config('finwa_chat.plans'))
        ->and($prompt)->toContain('<pesan>');
});

it('pesan user dibungkus <pesan> & pesan sebelumnya ikut sebagai konteks', function () {
    chatFakeApis('query');

    chatSend('aku mau mulai nabung', $this->world);
    chatSend('terus caranya gimana biar konsisten?', $this->world);

    Http::assertSent(function (Request $r) {
        if (! str_contains($r->url(), '/chat/completions')) {
            return false;
        }
        $user = $r['messages'][1]['content'] ?? '';

        return str_contains($user, '<pesan>terus caranya gimana biar konsisten?</pesan>')
            && str_contains($user, '<pesan>aku mau mulai nabung</pesan>');
    });
});

it('FinWa-AI down → fallback aturan lokal, bukan dipaksa jadi transaksi', function () {
    $result = (function () {
        Http::fake(['*/process/text' => Http::response(['error' => 'down'], 503)]);

        return (new \App\Services\FinWaAIService)->classifyIntent('apa kabar finwa');
    })();

    expect($result['success'])->toBeFalse();
});

it('tanda tanya saja tidak lagi membuat pesan dianggap query keuangan', function () {
    $handler = new \App\Services\Query\FinancialQueryHandler(new \App\Models\Message, fn () => null);

    expect($handler->isRecognizedFinancialQuery('kenapa langit biru?'))->toBeFalse()
        ->and($handler->isRecognizedFinancialQuery('ringkasan bulan ini'))->toBeTrue();
});

// ── Cadangan saat LLM tidak aktif ──

it('Chat LLM mati → pertanyaan menabung dijawab jawaban lokal, bukan Panduan', function () {
    config(['services.finwa_chat_llm.enabled' => false]);
    chatFakeApis('help');

    $joined = implode("\n", chatSend('cara agar bisa menabung ?', $this->world));

    expect($joined)->toContain('sisihkan dulu')
        ->and($joined)->not->toContain('Panduan FinWa');
});

it('LLM error → jawaban lokal untuk topik yang dikenal', function () {
    chatFakeApis('help', llmOk: false);

    expect(implode("\n", chatSend('apa itu dana darurat', $this->world)))->toContain('Dana darurat itu');
});

it('perintah diagnosa berjalan tanpa membocorkan API key', function () {
    $this->artisan('finwa:chat-check', ['message' => 'cara agar bisa menabung ?', '--no-call' => true])
        ->expectsOutputToContain('Chat LLM siap?')
        ->doesntExpectOutputToContain('test-key')
        ->assertSuccessful();
});

// ── LLM cadangan ──

it('LLM utama 503 → otomatis memakai LLM cadangan', function () {
    config([
        'services.finwa_llm_fallback.base_url' => 'https://backup-llm.test/v1',
        'services.finwa_llm_fallback.model' => 'backup-model',
        'services.finwa_llm_fallback.api_key' => 'backup-key',
    ]);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'backup-llm.test')) {
            return Http::response(['choices' => [['message' => ['content' => 'Jawaban dari cadangan']]]], 200);
        }
        if (str_contains($request->url(), '/chat/completions')) {
            return Http::response(['error' => ['message' => 'This model is currently unavailable.']], 503);
        }

        return Http::response(['intent' => 'help', 'confidence' => 0.9, 'entities' => []], 200);
    });

    expect(implode("\n", chatSend('cara agar bisa menabung ?', $this->world)))->toContain('Jawaban dari cadangan');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'backup-llm.test') && $r['model'] === 'backup-model');
});

it('LLM utama 401 (bukan gangguan sementara) → cadangan tidak dipanggil', function () {
    config([
        'services.finwa_llm_fallback.base_url' => 'https://backup-llm.test/v1',
        'services.finwa_llm_fallback.model' => 'backup-model',
        'services.finwa_llm_fallback.api_key' => 'backup-key',
    ]);
    Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

    (new \App\Services\AI\OpenAIChatClient(new \App\Services\HybridLLMConfigService, chatMode: true))
        ->complete([['role' => 'user', 'content' => 'hai']]);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'backup-llm.test'));
    expect(\App\Services\AI\OpenAIChatClient::$lastError)->toStartWith('HTTP 401');
});
