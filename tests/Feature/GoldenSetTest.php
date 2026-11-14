<?php

/**
 * Fase 0 — Harness evaluasi golden set (Hybrid.md).
 *
 * Menjalankan seluruh pesan di tests/golden/messages.jsonl melewati alur
 * processTextMessage sungguhan (rules deterministik saat ini, LLM dimatikan),
 * lalu membandingkan hasilnya dengan ekspektasi. Hasil = BASELINE akurasi
 * rules yang akan dibandingkan pada Fase 1-2 (target >= 95%).
 *
 * Threshold: env GOLDEN_MIN_ACCURACY (default 0 = mode ukur saja, tidak gagal).
 * Setelah baseline dicatat di Hybrid.md, naikkan env ini untuk regression guard.
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\ConversationContext;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\ConversationContextService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const GOLDEN_SEED_BALANCE = 999999999;
const GOLDEN_SENDER = '6281234567890';
const GOLDEN_CHANNEL_ACCOUNT = '628999000111';

/** Muat seluruh kasus dari messages.jsonl. */
function goldenLoadCases(): array
{
    $path = base_path('tests/golden/messages.jsonl');
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $cases = [];
    foreach ($lines as $i => $line) {
        $case = json_decode($line, true);
        if (! is_array($case)) {
            throw new RuntimeException('JSONL tidak valid di baris '.($i + 1).': '.json_last_error_msg());
        }
        $cases[] = $case;
    }

    return $cases;
}

/** Seed dunia tenant/channel/user/subscription/kategori/dompet. */
function goldenSeedWorld(): Tenant
{
    $tenant = Tenant::create([
        'name' => 'Golden Tenant',
        'slug' => 'golden-tenant',
        'is_active' => true,
    ]);

    Channel::create([
        'tenant_id' => $tenant->id,
        'type' => 'whatsapp',
        'channel_account' => GOLDEN_CHANNEL_ACCOUNT,
        'name' => 'Golden Channel',
        'config' => ['session_id' => 'wa_golden_1'],
        'is_active' => true,
    ]);

    User::create([
        'name' => 'Golden User',
        'email' => 'golden@example.com',
        'password' => bcrypt('password'),
        'whatsapp_number' => GOLDEN_SENDER,
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

    // Dompet wajib: findOrCreateBalance(accountType=null) TIDAK auto-create,
    // dan transfer punya guard saldo cukup — tanpa seed, trf-* & saldoset-* gagal.
    $wallets = [
        ['Bank BCA', 'bank', false],
        ['Bank Mandiri', 'bank', false],
        ['Bank BNI', 'bank', false],
        ['Tabungan Jago', 'bank', false],
        ['Gopay', 'wallet', false],
        ['Dana', 'wallet', false],
        ['OVO', 'wallet', false],
        ['Cash', 'cash', true], // primary (is_default) — target "saldo 1000000"
    ];
    foreach ($wallets as [$name, $type, $isDefault]) {
        Balance::create([
            'tenant_id' => $tenant->id,
            'account_name' => $name,
            'account_type' => $type,
            'currency' => 'IDR',
            'balance' => GOLDEN_SEED_BALANCE,
            'balance_date' => now()->toDateString(),
            'is_active' => true,
            'is_default' => $isDefault,
        ]);
    }

    return $tenant;
}

/** Reset state antar kasus agar setiap pesan dinilai dari kondisi awal yang sama. */
function goldenResetState(int $tenantId): void
{
    Transaction::where('tenant_id', $tenantId)->delete();
    ConversationContext::where('tenant_id', $tenantId)->delete();
    Balance::where('tenant_id', $tenantId)->update(['balance' => GOLDEN_SEED_BALANCE]);
    Cache::flush();
    $GLOBALS['finwa_golden_replies'] = [];
}

/**
 * Skor satu kasus.
 *
 * @return array{skip: bool, pass: bool, fields: array<string, bool>, detail: string}
 */
function goldenScore(array $case, Tenant $tenant, array $replies): array
{
    $tags = $case['tags'] ?? [];
    $intent = $case['intent'] ?? '';
    $joined = implode("\n---\n", $replies);

    if (in_array('skip', $tags, true) || $intent === 'stateful') {
        return ['skip' => true, 'pass' => false, 'fields' => [], 'detail' => 'dilewati (stateful)'];
    }

    $fields = [];
    $detail = '';

    switch ($intent) {
        case 'transaction':
        case 'debt':
            $txs = Transaction::where('tenant_id', $tenant->id)->where('type', '!=', 'debit_internal')->where('type', '!=', 'kredit_internal')->get();
            $fields['jumlah_item'] = $txs->count() === ($case['items'] ?? 1);
            $fields['total_nominal'] = (int) round($txs->sum('amount')) === (int) $case['amount'];
            $fields['tipe'] = $txs->isNotEmpty()
                && $txs->every(fn ($t) => $t->type === $case['type']);
            if ($intent === 'debt') {
                $fields['kategori'] = $txs->isNotEmpty()
                    && $txs->every(fn ($t) => $t->category?->type === ($case['category'] ?? ''));
            }
            $detail = sprintf(
                'tx=%d (total=%s, tipe=[%s])',
                $txs->count(),
                $txs->sum('amount'),
                $txs->pluck('type')->implode(',')
            );
            break;

        case 'transfer':
            $txs = Transaction::where('tenant_id', $tenant->id)
                ->whereIn('type', ['debit_internal', 'kredit_internal'])
                ->get();
            $types = $txs->pluck('type')->sort()->values()->all();
            $fields['pasangan_tx'] = $types === ['debit_internal', 'kredit_internal'];
            $fields['nominal'] = $txs->count() === 2
                && $txs->every(fn ($t) => (int) $t->amount === (int) $case['amount']);
            $detail = sprintf('tx_internal=%d [%s]', $txs->count(), implode(',', $types));
            break;

        case 'set_balance':
            $isIncrease = in_array('increase', $tags, true);
            $query = Balance::where('tenant_id', $tenant->id);
            if (! empty($case['wallet'])) {
                $query->whereRaw('LOWER(account_name) LIKE ?', ['%'.strtolower($case['wallet']).'%']);
            } else {
                $query->where('is_default', true);
            }
            $balance = $query->first();
            $expected = $isIncrease
                ? GOLDEN_SEED_BALANCE + (int) $case['amount']
                : (int) $case['amount'];
            $fields['saldo_dompet'] = $balance !== null
                && (int) (float) $balance->balance === $expected;
            $fields['balasan_sukes'] = str_contains($joined, 'Saldo Berhasil')
                || str_contains($joined, 'Saldo Ditambah');
            $detail = sprintf(
                'wallet=%s saldo=%s expected=%s reply=%s',
                $balance?->account_name ?? '(tidak ditemukan)',
                $balance?->balance ?? '-',
                $expected,
                mb_substr($replies[0] ?? '(tanpa balasan)', 0, 70)
            );
            break;

        case 'query':
            $rejectReply = str_contains($joined, 'Konfirmasi Transaksi')
                || str_contains($joined, 'bukan nama dompet')
                || str_contains($joined, 'Maaf, pesan tidak dikenali');
            $markers = [
                'Ringkasan Keuangan', 'Saldo Rekening', 'Saldo Bank', 'Cashflow',
                'Top Pengeluaran', 'Total Saldo', 'Belum ada transaksi',
                'Tidak ada transaksi', 'Pemasukan', 'Pengeluaran', 'Rekening',
                'Dompet', 'Riwayat', 'Transaksi',
            ];
            $hasMarker = array_filter($markers, fn ($m) => str_contains($joined, $m));
            $fields['balasan_query'] = ! $rejectReply && $hasMarker !== [];
            $detail = 'reply='.mb_substr($replies[0] ?? '(tanpa balasan)', 0, 70);
            break;

        case 'help':
            $fields['panduan'] = str_contains($joined, $case['marker'] ?? 'Panduan FinWa');
            $detail = 'reply='.mb_substr($replies[0] ?? '(tanpa balasan)', 0, 70);
            break;

        case 'greeting':
            $fields['sapaan'] = str_contains($joined, '👋')
                || (bool) preg_match('/Selamat (pagi|siang|sore|malam)/u', $joined);
            $detail = 'reply='.mb_substr($replies[0] ?? '(tanpa balasan)', 0, 70);
            break;

        case 'pending_confirmation':
            $ctx = new ConversationContextService($tenant->id, GOLDEN_SENDER);
            $hasPending = $ctx->getEntityFromContext('pending_confirmation') !== null
                || $ctx->getEntityFromContext('pending_wallet_balance_confirmation') !== null;
            $fields['konfirmasi_ditampilkan'] = $hasPending
                || str_contains($joined, 'Konfirmasi Transaksi')
                // Pertanyaan bernomor (mis. hutang baru: masuk dompet / hanya dicatat)
                || str_contains($joined, 'Balas dengan nomor pilihan')
                || str_contains($joined, 'bukan nama dompet');
            $detail = 'reply='.mb_substr($replies[0] ?? '(tanpa balasan)', 0, 70);
            break;

        default:
            return ['skip' => true, 'pass' => false, 'fields' => [], 'detail' => "intent tak dikenal: {$intent}"];
    }

    $pass = $fields !== [] && ! in_array(false, $fields, true);

    return ['skip' => false, 'pass' => $pass, 'fields' => $fields, 'detail' => $detail];
}

beforeEach(function () {
    // LLM & API eksternal dimatikan: yang dinilai murni rules deterministik.
    config([
        'services.finwa_ai.enabled' => false,
        'finwa_reply_edit.enabled' => false,
    ]);
    Http::fake(['*' => Http::response(['error' => 'golden_eval_offline'], 500)]);

    $GLOBALS['finwa_golden_replies'] = [];
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($sessionId, $to, $text) {
        $GLOBALS['finwa_golden_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-golden-'.count($GLOBALS['finwa_golden_replies'])]];
    });
});

test('golden set: baseline akurasi rules deterministik (Fase 0)', function () {
    $cases = goldenLoadCases();
    $tenant = goldenSeedWorld();
    $channel = Channel::where('tenant_id', $tenant->id)->firstOrFail();

    $scored = 0;
    $passed = 0;
    $fieldTotal = 0;
    $fieldPass = 0;
    $skipped = 0;
    $byIntent = [];
    $failures = [];

    foreach ($cases as $i => $case) {
        goldenResetState($tenant->id);

        $message = Message::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'channel' => 'whatsapp',
            'channel_account' => GOLDEN_CHANNEL_ACCOUNT,
            'sender_id' => GOLDEN_SENDER,
            'message_id' => 'golden-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
            'type' => 'text',
            'content' => $case['text'],
            'timestamp' => time() + $i,
        ]);

        $error = null;
        try {
            (new ProcessIncomingMessage($message))->handle();
        } catch (\Throwable $e) {
            $error = get_class($e).': '.$e->getMessage();
        }

        $result = $error !== null
            ? ['skip' => false, 'pass' => false, 'fields' => ['eksekusi' => false], 'detail' => 'ERROR '.$error]
            : goldenScore($case, $tenant, $GLOBALS['finwa_golden_replies']);

        $intent = $case['intent'] ?? '?';
        $byIntent[$intent] ??= ['total' => 0, 'pass' => 0];
        $byIntent[$intent]['total']++;

        if ($result['skip']) {
            $skipped++;
            continue;
        }

        $scored++;
        $fieldTotal += count($result['fields']);
        $fieldPass += count(array_filter($result['fields']));
        if ($result['pass']) {
            $passed++;
            $byIntent[$intent]['pass']++;
        } else {
            $failedFields = array_keys(array_filter($result['fields'], fn ($v) => ! $v));
            $failures[] = sprintf(
                '%s [%s] gagal: %s | %s',
                $case['id'],
                implode(',', $failedFields),
                $case['text'],
                $result['detail']
            );
        }
    }

    $caseAcc = $scored > 0 ? $passed / $scored : 0.0;
    $fieldAcc = $fieldTotal > 0 ? $fieldPass / $fieldTotal : 0.0;

    $report = ["", '=== GOLDEN BASELINE (rules deterministik, LLM OFF) ==='];
    $report[] = sprintf('Kasus: %d dinilai, %d dilewati, %d lulus (%.2f%%)', $scored, $skipped, $passed, $caseAcc * 100);
    $report[] = sprintf('Akurasi per-field: %.2f%% (%d/%d)', $fieldAcc * 100, $fieldPass, $fieldTotal);
    $report[] = 'Per intent:';
    foreach ($byIntent as $intentName => $stat) {
        $pct = $stat['total'] > 0 ? $stat['pass'] / $stat['total'] * 100 : 0;
        $report[] = sprintf('  %-22s %3d/%3d (%5.1f%%)', $intentName, $stat['pass'], $stat['total'], $pct);
    }
    if ($failures !== []) {
        $report[] = sprintf('Gagal (%d, tampil maks 80):', count($failures));
        foreach (array_slice($failures, 0, 80) as $f) {
            $report[] = '  - '.$f;
        }
    }
    $report[] = '';
    echo implode("\n", $report).PHP_EOL;

    // Threshold bisa dinaikkan lewat env setelah baseline dicatat (Fase 1-2).
    $min = (float) (getenv('GOLDEN_MIN_ACCURACY') ?: 0);
    expect($caseAcc)->toBeGreaterThanOrEqual($min);
});
