<?php

/**
 * Fase 3 (Hybrid.md §7): fallback chain + ketahanan.
 *
 *  - API down → pesan tetap tercatat via rules (jatuh-when-in-doubt).
 *  - Fast-path perintah TIDAK tersentuh LLM (tanpa latensi tambahan).
 *  - 3x gagal beruntun → circuit breaker buka → panggilan HTTP dilewati.
 *
 * Prefix global hyb3Ft agar tidak bentrok dengan GoldenSetTest /
 * HybridPhase2RoutingTest saat suite penuh.
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

const HYB3_SEED_BALANCE = 999999999;
const HYB3_SENDER = '6281234567890';
const HYB3_CHANNEL_ACCOUNT = '628999000111';
const HYB3_LLM_BASE = 'https://api.test/v1';

/** Seed dunia tenant/channel/user/kategori/dompet (pola GoldenSetTest). */
function hyb3SeedWorld(): Tenant
{
    $tenant = Tenant::create([
        'name' => 'Hybrid F3 Tenant',
        'slug' => 'hybrid-f3-tenant',
        'is_active' => true,
    ]);

    Channel::create([
        'tenant_id' => $tenant->id,
        'type' => 'whatsapp',
        'channel_account' => HYB3_CHANNEL_ACCOUNT,
        'name' => 'Hybrid F3 Channel',
        'config' => ['session_id' => 'wa_hyb3_1'],
        'is_active' => true,
    ]);

    User::create([
        'name' => 'Hybrid F3 User',
        'email' => 'hybf3@example.com',
        'password' => bcrypt('password'),
        'whatsapp_number' => HYB3_SENDER,
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
        'balance' => HYB3_SEED_BALANCE,
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
function hyb3Send(Tenant $tenant, string $text, ?array $metadata = null): array
{
    $GLOBALS['hyb3_replies'] = [];

    $message = Message::create([
        'tenant_id' => $tenant->id,
        'channel_id' => Channel::where('tenant_id', $tenant->id)->firstOrFail()->id,
        'channel' => 'whatsapp',
        'channel_account' => HYB3_CHANNEL_ACCOUNT,
        'sender_id' => HYB3_SENDER,
        'message_id' => 'hyb3-'.uniqid(),
        'type' => 'text',
        'content' => $text,
        'metadata' => $metadata,
        'timestamp' => time(),
    ]);

    try {
        (new ProcessIncomingMessage($message))->handle();
    } catch (\Throwable $e) {
        $GLOBALS['hyb3_replies'][] = '[ERROR] '.get_class($e).': '.$e->getMessage();
    }

    return $GLOBALS['hyb3_replies'];
}

/** Jumlah panggilan ke endpoint LLM hybrid (api.test) sejauh ini. */
function hyb3LlmCalls(): int
{
    return count($GLOBALS['hyb3_llm_bodies'] ?? []);
}

beforeEach(function () {
    config([
        'services.finwa_ai.enabled' => false,
        'finwa_reply_edit.enabled' => false,
        'services.finwa_hybrid_llm.enabled' => true,
        'services.groq_llm.base_url' => HYB3_LLM_BASE,
        'services.groq_llm.model' => 'test-model',
        'services.groq_llm.api_keys' => 'test-key',
    ]);

    $GLOBALS['hyb3_llm_bodies'] = [];
    $GLOBALS['hyb3_replies'] = [];
    $GLOBALS['hyb3_llm_up'] = false; // simulasi API down (boleh diubah per test).
    Cache::flush(); // state circuit breaker bersih di tiap test.

    // Satu fake sejak awal (Http::fake berikutnya akan MENUMPUK — jangan dipanggil ulang).
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'api.test')) {
            $GLOBALS['hyb3_llm_bodies'][] = $request->body();

            if ($GLOBALS['hyb3_llm_up']) {
                return Http::response([
                    'choices' => [['message' => ['content' => json_encode([
                        'intent' => 'transaction',
                        'type' => 'income',
                        'items' => [['amount' => 128000]],
                        'confidence' => 0.95,
                    ])]]],
                ], 200);
            }

            return Http::response(['error' => 'hyb3_down'], 500);
        }

        return Http::response(['error' => 'hyb3_offline'], 500);
    });

    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($sessionId, $to, $text) {
        $GLOBALS['hyb3_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-hyb3-'.count($GLOBALS['hyb3_replies'])]];
    });
});

// ── Acceptance: API down → rules tetap mencatat ────────────────────

test('acceptance: API down, "makan 25rb" tetap tercatat via rules', function () {
    $tenant = hyb3SeedWorld();

    hyb3Send($tenant, 'makan 25rb');

    // Fast-path perintah tidak tersentuh LLM (tanpa latensi tambahan),
    // aturan rules tetap mencatat transaksi.
    expect(hyb3LlmCalls())->toBe(0)
        ->and(Transaction::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and((int) Transaction::where('tenant_id', $tenant->id)->first()->amount)->toBe(25000);
});

test('acceptance: pesan yang menyentuh hybrid saat API down → jatuh ke jalur lama', function () {
    $tenant = hyb3SeedWorld();

    $replies = hyb3Send($tenant, 'saya punya 128k');

    expect(hyb3LlmCalls())->toBe(1)
        ->and(Transaction::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(implode("\n", $replies))->toContain('Konfirmasi Transaksi');
});

// ── Circuit breaker ────────────────────────────────────────────────

test('circuit breaker: 3x gagal beruntun → panggilan ke-4 dilewati, jalur lama tetap jalan', function () {
    $tenant = hyb3SeedWorld();

    // 3 kegagalan (3 pesan hybrid non-fast-path dengan API down).
    hyb3Send($tenant, 'saya punya 128k');
    hyb3Send($tenant, 'saya punya 128k');
    hyb3Send($tenant, 'saya punya 128k');
    expect(hyb3LlmCalls())->toBe(3);

    // Sirkuit terbuka → pesan ke-4 TIDAK memanggil HTTP, tetap dilayani rules.
    $replies = hyb3Send($tenant, 'saya punya 128k');

    expect(hyb3LlmCalls())->toBe(3)
        ->and(Transaction::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(implode("\n", $replies))->toContain('Konfirmasi Transaksi');
});

test('circuit breaker: API pulih sebelum ambang → sirkuit tetap tertutup', function () {
    $tenant = hyb3SeedWorld();

    // 2 kegagalan → masih di bawah ambang 3.
    hyb3Send($tenant, 'saya punya 128k');
    hyb3Send($tenant, 'saya punya 128k');
    expect(hyb3LlmCalls())->toBe(2);

    // API pulih → sukses, penghitung reset.
    $GLOBALS['hyb3_llm_up'] = true;

    $replies = hyb3Send($tenant, 'saya punya 128k');

    expect(hyb3LlmCalls())->toBe(3)
        ->and(Transaction::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and((int) Transaction::where('tenant_id', $tenant->id)->first()->amount)->toBe(128000)
        ->and(implode("\n", $replies))->not->toContain('Konfirmasi Transaksi');
});
