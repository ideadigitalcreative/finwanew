<?php

/**
 * Fase 2 — integrasi routing hybrid + guard matrix (Hybrid.md §7-2a, 2d, 2f).
 *
 * Menjalankan ProcessIncomingMessage sungguhan dengan Http::fake:
 *  - sukses → hasil lolos validator mengintercept (transaksi tercatat).
 *  - LLM error → jatuh-when-in-doubt (output identik jalur lama).
 *  - guard matrix → pesan yang dilarang disela TIDAK memanggil LLM.
 *
 * Nama fungsi global ber-prefix hyb2Ft agar tidak bentrok dengan
 * GoldenSetTest saat suite penuh.
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const HYB2_SEED_BALANCE = 999999999;
const HYB2_SENDER = '6281234567890';
const HYB2_CHANNEL_ACCOUNT = '628999000111';
const HYB2_LLM_BASE = 'https://api.test/v1';

/** Seed dunia tenant/channel/user/kategori/dompet (pola GoldenSetTest). */
function hyb2SeedWorld(): Tenant
{
    $tenant = Tenant::create([
        'name' => 'Hybrid F2 Tenant',
        'slug' => 'hybrid-f2-tenant',
        'is_active' => true,
    ]);

    Channel::create([
        'tenant_id' => $tenant->id,
        'type' => 'whatsapp',
        'channel_account' => HYB2_CHANNEL_ACCOUNT,
        'name' => 'Hybrid F2 Channel',
        'config' => ['session_id' => 'wa_hyb2_1'],
        'is_active' => true,
    ]);

    User::create([
        'name' => 'Hybrid F2 User',
        'email' => 'hybf2@example.com',
        'password' => bcrypt('password'),
        'whatsapp_number' => HYB2_SENDER,
        'tenant_id' => $tenant->id,
    ]);

    Subscription::create([
        'tenant_id' => $tenant->id,
        'plan' => 'growth',
        'duration_months' => 1,
        'price' => 0,
        'status' => 'active',
    ]);

    (new CategoryManagerService)->createCategoriesForTenant($tenant->id);

    Balance::create([
        'tenant_id' => $tenant->id,
        'account_name' => 'Cash',
        'account_type' => 'cash',
        'currency' => 'IDR',
        'balance' => HYB2_SEED_BALANCE,
        'balance_date' => now()->toDateString(),
        'is_active' => true,
        'is_default' => true,
    ]);

    return $tenant;
}

/**
 * Kirim satu pesan text lewat job sungguhan.
 *
 * @return array<int, string> balasan WhatsApp yang direkam
 */
function hyb2Send(Tenant $tenant, string $text, ?array $metadata = null): array
{
    $GLOBALS['hyb2_replies'] = [];

    $message = Message::create([
        'tenant_id' => $tenant->id,
        'channel_id' => Channel::where('tenant_id', $tenant->id)->firstOrFail()->id,
        'channel' => 'whatsapp',
        'channel_account' => HYB2_CHANNEL_ACCOUNT,
        'sender_id' => HYB2_SENDER,
        'message_id' => 'hyb2-'.uniqid(),
        'type' => 'text',
        'content' => $text,
        'metadata' => $metadata,
        'timestamp' => time(),
    ]);

    try {
        (new ProcessIncomingMessage($message))->handle();
    } catch (\Throwable $e) {
        $GLOBALS['hyb2_replies'][] = '[ERROR] '.get_class($e).': '.$e->getMessage();
    }

    return $GLOBALS['hyb2_replies'];
}

/** Jumlah panggilan ke endpoint LLM hybrid (api.test) sejauh ini. */
function hyb2LlmCalls(): int
{
    // Hanya hitung panggilan hybrid ekstraksi (selalu membawa
    // response_format json_object). Chat LLM fallback (FinWaChatService)
    // memakai base_url yang sama tetapi jalur terpisah — memang dirancang
    // untuk pesan tanpa nominal, sehingga tidak boleh dihitung di sini.
    return count(array_filter(
        $GLOBALS['hyb2_llm_bodies'] ?? [],
        fn ($body) => str_contains((string) $body, 'response_format'),
    ));
}

/** Body request LLM ke-i (JSON decode). */
function hyb2LlmBody(int $i): array
{
    return json_decode($GLOBALS['hyb2_llm_bodies'][$i], true) ?? [];
}

beforeEach(function () {
    // Hybrid aktif via env-merge (tanpa baris AppSetting → baca config service).
    config([
        'services.finwa_ai.enabled' => false,
        'finwa_reply_edit.enabled' => false,
        'services.finwa_hybrid_llm.enabled' => true,
        'services.groq_llm.base_url' => HYB2_LLM_BASE,
        'services.groq_llm.model' => 'test-model',
        'services.groq_llm.api_keys' => 'test-key',
    ]);

    $GLOBALS['hyb2_llm_bodies'] = [];
    $GLOBALS['hyb2_llm_response'] = [
        'choices' => [[
            'message' => ['content' => json_encode([
                'intent' => 'transaction',
                'type' => 'income',
                'items' => [['amount' => 128000]],
                'confidence' => 0.95,
            ])],
        ]],
    ];
    $GLOBALS['hyb2_llm_status'] = 200;

    // Semua HTTP direkam; endpoint LLM hybrid dibalas sesuai skenario.
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'api.test')) {
            $GLOBALS['hyb2_llm_bodies'][] = $request->body();

            return Http::response($GLOBALS['hyb2_llm_response'], $GLOBALS['hyb2_llm_status']);
        }

        return Http::response(['error' => 'hyb2_offline'], 500);
    });

    $GLOBALS['hyb2_replies'] = [];
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($sessionId, $to, $text) {
        $GLOBALS['hyb2_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-hyb2-'.count($GLOBALS['hyb2_replies'])]];
    });
});

// ── Intersepsi sukses ──────────────────────────────────────────────

test('hybrid sukses: lolos validator → transaksi tercatat tanpa prompt konfirmasi', function () {
    $tenant = hyb2SeedWorld();

    $replies = hyb2Send($tenant, 'saya punya 128k');

    expect(hyb2LlmCalls())->toBe(1);

    $txs = Transaction::where('tenant_id', $tenant->id)->get();
    expect($txs)->toHaveCount(1)
        ->and((int) $txs->first()->amount)->toBe(128000)
        ->and($txs->first()->type)->toBe('income')
        ->and(implode("\n", $replies))->not->toContain('Konfirmasi Transaksi');
});

test('hybrid nominal bohong: LLM bilang 999999, teks 128k → rules menang (128000)', function () {
    $tenant = hyb2SeedWorld();
    $GLOBALS['hyb2_llm_response'] = [
        'choices' => [[
            'message' => ['content' => json_encode([
                'intent' => 'transaction',
                'type' => 'income',
                'items' => [['amount' => 999999]],
                'confidence' => 0.95,
            ])],
        ]],
    ];

    hyb2Send($tenant, 'saya punya 128k');

    $txs = Transaction::where('tenant_id', $tenant->id)->get();
    expect($txs)->toHaveCount(1)
        ->and((int) $txs->first()->amount)->toBe(128000);
});

test('hybrid confidence rendah (0.3) → lepas saringan ke alur konfirmasi existing', function () {
    $tenant = hyb2SeedWorld();
    $GLOBALS['hyb2_llm_response'] = [
        'choices' => [[
            'message' => ['content' => json_encode([
                'intent' => 'transaction',
                'type' => 'income',
                'items' => [['amount' => 128000]],
                'confidence' => 0.3,
            ])],
        ]],
    ];

    $replies = hyb2Send($tenant, 'saya punya 128k');

    // LLM dipanggil tapi TIDAK disela → jalur lama → prompt konfirmasi, tanpa row.
    expect(hyb2LlmCalls())->toBe(1)
        ->and(Transaction::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(implode("\n", $replies))->toContain('Konfirmasi Transaksi');
});

// ── Jatuh-when-in-doubt ────────────────────────────────────────────

test('jatuh-when-in-doubt: LLM error (500) → output identik jalur lama', function () {
    $tenant = hyb2SeedWorld();
    $GLOBALS['hyb2_llm_status'] = 500;

    $replies = hyb2Send($tenant, 'saya punya 128k');

    // Hybrid unavailable → prompt konfirmasi jalur lama, tanpa transaksi.
    expect(hyb2LlmCalls())->toBe(1)
        ->and(Transaction::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(implode("\n", $replies))->toContain('Konfirmasi Transaksi');
});

test('jatuh-when-in-doubt: JSON rusak dari LLM → lepas saringan', function () {
    $tenant = hyb2SeedWorld();
    $GLOBALS['hyb2_llm_response'] = ['choices' => [['message' => ['content' => 'bukan-json{']]]];

    $replies = hyb2Send($tenant, 'saya punya 128k');

    expect(Transaction::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(implode("\n", $replies))->toContain('Konfirmasi Transaksi');
});

// ── Guard matrix (2d/2e) — pesan terlarang TIDAK memanggil LLM ────

test('guard: pesan dwimakna "pengeluaran 500rb bulan ini" TIDAK disela', function () {
    $tenant = hyb2SeedWorld();

    hyb2Send($tenant, 'pengeluaran 500rb bulan ini');

    expect(hyb2LlmCalls())->toBe(0);
});

test('guard: debt keyword "hutang budi 50000" TIDAK disela', function () {
    $tenant = hyb2SeedWorld();

    hyb2Send($tenant, 'hutang budi 50000');

    expect(hyb2LlmCalls())->toBe(0);
});

test('guard: transfer ambigu "transfer 500rb" TIDAK disela', function () {
    $tenant = hyb2SeedWorld();

    hyb2Send($tenant, 'transfer 500rb');

    expect(hyb2LlmCalls())->toBe(0);
});

test('guard: pesan grup TIDAK disela (konfirmasi dulu, §10 no.2)', function () {
    $tenant = hyb2SeedWorld();

    hyb2Send($tenant, 'saya punya 128k', ['is_group' => true, 'author' => '62881112223']);

    expect(hyb2LlmCalls())->toBe(0);
});

test('guard: pesan tanpa nominal TIDAK memanggil LLM (pre-check murah)', function () {
    $tenant = hyb2SeedWorld();

    hyb2Send($tenant, 'saya punya uang banyak');

    expect(hyb2LlmCalls())->toBe(0);
});

// ── Flag mati = jalur lama murni ───────────────────────────────────

test('flag disabled → LLM tidak pernah dipanggil, jalur lama berjalan', function () {
    config(['services.finwa_hybrid_llm.enabled' => false]);
    $tenant = hyb2SeedWorld();

    $replies = hyb2Send($tenant, 'saya punya 128k');

    expect(hyb2LlmCalls())->toBe(0)
        ->and(Transaction::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(implode("\n", $replies))->toContain('Konfirmasi Transaksi');
});
