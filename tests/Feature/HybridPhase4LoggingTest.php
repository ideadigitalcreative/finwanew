<?php

/**
 * Fase 4 (Hybrid.md §7): log ekstraksi LLM ke `llm_extraction_logs`.
 *
 * Satu baris per panggilan extractor: pesan mentah, hasil LLM, hasil
 * validator, keputusan akhir, alasan override — bahan audit + dataset
 * perbaikan prompt.
 *
 * Prefix global hyb4Ft agar tidak bentrok dengan test hybrid lain.
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\LlmExtractionLog;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const HYB4_SEED_BALANCE = 999999999;
const HYB4_SENDER = '6281234567890';
const HYB4_CHANNEL_ACCOUNT = '628999000111';
const HYB4_LLM_BASE = 'https://api.test/v1';

/** Seed dunia tenant/channel/user/kategori/dompet (pola GoldenSetTest). */
function hyb4SeedWorld(): Tenant
{
    $tenant = Tenant::create([
        'name' => 'Hybrid F4 Tenant',
        'slug' => 'hybrid-f4-tenant',
        'is_active' => true,
    ]);

    Channel::create([
        'tenant_id' => $tenant->id,
        'type' => 'whatsapp',
        'channel_account' => HYB4_CHANNEL_ACCOUNT,
        'name' => 'Hybrid F4 Channel',
        'config' => ['session_id' => 'wa_hyb4_1'],
        'is_active' => true,
    ]);

    User::create([
        'name' => 'Hybrid F4 User',
        'email' => 'hybf4@example.com',
        'password' => bcrypt('password'),
        'whatsapp_number' => HYB4_SENDER,
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
        'balance' => HYB4_SEED_BALANCE,
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
function hyb4Send(Tenant $tenant, string $text): array
{
    $GLOBALS['hyb4_replies'] = [];

    $message = Message::create([
        'tenant_id' => $tenant->id,
        'channel_id' => Channel::where('tenant_id', $tenant->id)->firstOrFail()->id,
        'channel' => 'whatsapp',
        'channel_account' => HYB4_CHANNEL_ACCOUNT,
        'sender_id' => HYB4_SENDER,
        'message_id' => 'hyb4-'.uniqid(),
        'type' => 'text',
        'content' => $text,
        'metadata' => null,
        'timestamp' => time(),
    ]);

    try {
        (new ProcessIncomingMessage($message))->handle();
    } catch (\Throwable $e) {
        $GLOBALS['hyb4_replies'][] = '[ERROR] '.get_class($e).': '.$e->getMessage();
    }

    return $GLOBALS['hyb4_replies'];
}

/** Log hybrid terakhir milik tenant. */
function hyb4LastLog(Tenant $tenant): ?LlmExtractionLog
{
    return LlmExtractionLog::where('tenant_id', $tenant->id)->latest('id')->first();
}

beforeEach(function () {
    config([
        'services.finwa_ai.enabled' => false,
        'finwa_reply_edit.enabled' => false,
        'services.finwa_hybrid_llm.enabled' => true,
        'services.groq_llm.base_url' => HYB4_LLM_BASE,
        'services.groq_llm.model' => 'test-model',
        'services.groq_llm.api_keys' => 'test-key',
    ]);

    $GLOBALS['hyb4_replies'] = [];
    // Respons LLM dikustomisasi per test lewat variabel global (jangan
    // panggil Http::fake() lagi — fake kedua MENUMPUK dan yang pertama menang).
    $GLOBALS['hyb4_llm_response'] = [
        'choices' => [['message' => ['content' => json_encode([
            'intent' => 'transaction',
            'type' => 'income',
            'items' => [['amount' => 128000]],
            'confidence' => 0.95,
        ])]]],
    ];
    $GLOBALS['hyb4_llm_status'] = 200;
    Cache::flush();

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'api.test')) {
            return Http::response($GLOBALS['hyb4_llm_response'], $GLOBALS['hyb4_llm_status']);
        }

        return Http::response(['error' => 'hyb4_offline'], 500);
    });

    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($sessionId, $to, $text) {
        $GLOBALS['hyb4_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-hyb4-'.count($GLOBALS['hyb4_replies'])]];
    });
});

// ── Keputusan: intercepted ─────────────────────────────────────────

test('intercept → satu baris log dengan decision=intercepted + hasil LLM & validator', function () {
    $tenant = hyb4SeedWorld();

    hyb4Send($tenant, 'saya punya 128k');

    $log = hyb4LastLog($tenant);
    expect($log)->not->toBeNull()
        ->and($log->decision)->toBe(LlmExtractionLog::DECISION_INTERCEPTED)
        ->and($log->raw_message)->toBe('saya punya 128k')
        ->and($log->llm_result['amount'])->toBe(128000)
        ->and($log->llm_result['confidence'])->toBe(0.95)
        ->and($log->validator_result['passed'])->toBeTrue()
        ->and($log->validator_result['amount'])->toBe(128000)
        ->and($log->message_id)->not->toBeNull()
        ->and($log->latency_ms)->not->toBeNull();
});

// ── Keputusan: not_intercepted (confidence rendah) ─────────────────

test('confidence rendah → decision=not_intercepted, validator disimpan untuk audit', function () {
    $tenant = hyb4SeedWorld();
    $GLOBALS['hyb4_llm_response'] = [
        'choices' => [['message' => ['content' => json_encode([
            'intent' => 'transaction',
            'type' => 'income',
            'items' => [['amount' => 128000]],
            'confidence' => 0.3,
        ])]]],
    ];

    hyb4Send($tenant, 'saya punya 128k');

    $log = hyb4LastLog($tenant);
    expect($log)->not->toBeNull()
        ->and($log->decision)->toBe(LlmExtractionLog::DECISION_NOT_INTERCEPTED)
        ->and($log->llm_result['confidence'])->toBe(0.3);
});

// ── Override: nominal LLM menyalahi teks ───────────────────────────

test('nominal LLM menyalahi teks → tetap intercepted, alasan amount_mismatch tercatat', function () {
    $tenant = hyb4SeedWorld();
    $GLOBALS['hyb4_llm_response'] = [
        'choices' => [['message' => ['content' => json_encode([
            'intent' => 'transaction',
            'type' => 'income',
            'items' => [['amount' => 999999]],
            'confidence' => 0.95,
        ])]]],
    ];

    hyb4Send($tenant, 'saya punya 128k');

    $log = hyb4LastLog($tenant);
    expect($log)->not->toBeNull()
        ->and($log->decision)->toBe(LlmExtractionLog::DECISION_INTERCEPTED)
        ->and($log->override_reasons)->toContain('amount_mismatch')
        ->and($log->validator_result['amount'])->toBe(128000); // rules menang
});

// ── Batch ditolak di gate ──────────────────────────────────────────

test('batch items dari LLM → ditolak di gate, decision=not_intercepted + items tercatat', function () {
    $tenant = hyb4SeedWorld();
    $GLOBALS['hyb4_llm_response'] = [
        'choices' => [['message' => ['content' => json_encode([
            'intent' => 'transaction',
            'type' => 'expense',
            'items' => [
                ['amount' => 24000, 'label' => 'rokok'],
                ['amount' => 6000, 'label' => 'jajan'],
            ],
            'confidence' => 0.95,
        ])]]],
    ];

    // Pesan TUNGGAL (lolos gate), tapi LLM ngawur mengirim 2 items → batch ditolak.
    hyb4Send($tenant, 'saya punya 128k');

    $log = hyb4LastLog($tenant);
    expect($log)->not->toBeNull()
        ->and($log->decision)->toBe(LlmExtractionLog::DECISION_NOT_INTERCEPTED)
        ->and($log->llm_result['items'])->toHaveCount(2);
});

// ── Keputusan: unavailable (API down) ──────────────────────────────

test('API down → decision=unavailable dicatat (bahan audit ketahanan)', function () {
    $tenant = hyb4SeedWorld();
    $GLOBALS['hyb4_llm_status'] = 500;

    hyb4Send($tenant, 'saya punya 128k');

    $log = hyb4LastLog($tenant);
    expect($log)->not->toBeNull()
        ->and($log->decision)->toBe(LlmExtractionLog::DECISION_UNAVAILABLE)
        ->and($log->llm_result['status'])->toBe('unavailable')
        ->and($log->llm_result['note'])->toBe('llm_error');
});

// ── Tidak ada log untuk pesan di luar injeksi ──────────────────────

test('fast-path perintah TIDAK menghasilkan baris log', function () {
    $tenant = hyb4SeedWorld();

    hyb4Send($tenant, 'makan 25rb');

    expect(LlmExtractionLog::where('tenant_id', $tenant->id)->count())->toBe(0);
});
