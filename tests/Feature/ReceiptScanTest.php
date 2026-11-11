<?php

/**
 * AI tahap 4–6: pembaca struk Gemini.
 *  - Gemini gagal ≠ "bukan struk" → kuota TIDAK dipotong, retry dengan key lain
 *  - Nominal "Rp 25.000" dibaca 25000 (dulu (int) → 0)
 *  - Batas scan per menit diterapkan, user_id terisi
 *  - Path di luar folder upload & host asing ditolak
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\OcrJob;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Services\Category\CategoryManagerService;
use App\Services\GeminiAIService;
use App\Services\WhatsAppService;
use App\Support\Rupiah;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const SCAN_SENDER = '6281300000001';

function scanImagePath(int $tenantId): string
{
    $rel = "whatsapp/{$tenantId}/_test/receipt-".uniqid().'.jpg';
    $abs = storage_path('app/public/'.$rel);
    @mkdir(dirname($abs), 0777, true);
    $img = imagecreatetruecolor(20, 20);
    imagejpeg($img, $abs);
    imagedestroy($img);
    $GLOBALS['scan_files'][] = $abs;

    return '/storage/'.$rel;
}

function scanWorld(): array
{
    $tenant = Tenant::create(['name' => 'Scan', 'slug' => 'scan', 'is_active' => true]);
    $channel = Channel::create([
        'tenant_id' => $tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000333',
        'name' => 'Scan', 'config' => ['session_id' => 'wa_scan'], 'is_active' => true,
    ]);
    $user = User::create([
        'name' => 'Scan User', 'email' => 'scan@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => SCAN_SENDER, 'tenant_id' => $tenant->id,
    ]);
    UserWhatsAppNumber::create([
        'user_id' => $user->id, 'tenant_id' => $tenant->id, 'whatsapp_number' => SCAN_SENDER,
        'is_primary' => true, 'is_active' => true,
    ]);
    Subscription::create([
        'tenant_id' => $tenant->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active',
    ]);
    (new CategoryManagerService)->createCategoriesForTenant($tenant->id);
    Balance::create([
        'tenant_id' => $tenant->id, 'account_name' => 'Cash', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 1000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);

    return [$tenant, $channel, $user];
}

function scanSend(array $world, ?string $path = null): array
{
    [$tenant, $channel] = $world;
    $GLOBALS['scan_replies'] = [];
    $path ??= scanImagePath($tenant->id);
    $message = Message::create([
        'tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000333', 'sender_id' => SCAN_SENDER,
        'message_id' => 'scan-'.uniqid(), 'type' => 'image', 'content' => $path,
        'metadata' => ['mimetype' => 'image/jpeg', 'media_url' => $path], 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return $GLOBALS['scan_replies'];
}

function geminiJson(array $data): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]], 'finishReason' => 'STOP']]];
}

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    $GLOBALS['scan_files'] = [];
    config([
        'services.gemini.api_key' => 'gemini-test-key',
        'services.gemini.model' => 'gemini-2.5-flash',
        'services.finwa_ai.enabled' => false,
        'finwa_reply_edit.enabled' => false,
    ]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['scan_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['scan_replies'])]];
    });
    $this->world = scanWorld();
});

afterEach(function () {
    foreach ($GLOBALS['scan_files'] ?? [] as $f) {
        @unlink($f);
    }
});

// ── Rupiah::parse ──

it('membaca nominal rupiah berbagai format', function ($input, ?int $expected) {
    expect(Rupiah::parse($input))->toBe($expected);
})->with([
    ['Rp 25.000', 25000],
    ['25.000', 25000],
    ['25,000', 25000],
    ['1.250.000', 1250000],
    ['Rp1.250.000,00', 1250000],
    ['25000', 25000],
    [25000, 25000],
    [25000.4, 25000],
    ['25rb', 25000],
    ['1,5jt', 1500000],
    ['Total: Rp 37.500,-', 37500],
    ['', null],
    ['abc', null],
    [null, null],
]);

// ── Alur scan ──

it('nominal string "Rp 25.000" dicatat Rp 25.000 (bukan Rp 0)', function () {
    Http::fake(['*generativelanguage*' => Http::response(geminiJson([
        'document_type' => 'receipt', 'merchant_name' => 'Warung Bu Siti', 'date' => now()->toDateString(),
        'items' => [['name' => 'Nasi Goreng', 'qty' => 1, 'price' => 'Rp 25.000']], 'tax' => 0,
        'total_amount' => 'Rp 25.000',
    ]))]);

    scanSend($this->world);

    $tx = Transaction::where('tenant_id', $this->world[0]->id)->latest('id')->first();
    expect($tx)->not->toBeNull()
        ->and((int) $tx->amount)->toBe(25000);
});

it('Gemini error → job failed, kuota tidak dipotong, pesan jujur', function () {
    Http::fake(['*generativelanguage*' => Http::response(['error' => 'overloaded'], 503)]);

    $replies = implode("\n", scanSend($this->world));

    $job = OcrJob::latest('id')->first();
    expect($job->status)->toBe('failed')
        ->and($replies)->toContain('tidak terpotong')
        ->and($replies)->not->toContain('tetap terpotong')
        ->and(app(\App\Services\SubscriptionLimitService::class)->getMonthlyScanCount($this->world[0]->id))->toBe(0);
    Http::assertSentCount(GeminiAIService::MAX_ATTEMPTS);
});

it('429 lalu sukses → retry berhasil, transaksi tercatat', function () {
    Http::fakeSequence('*generativelanguage*')
        ->push(['error' => 'rate'], 429)
        ->push(geminiJson([
            'document_type' => 'receipt', 'merchant_name' => 'Indomaret', 'date' => now()->toDateString(),
            'items' => [], 'tax' => 0, 'total_amount' => 15000,
        ]));

    scanSend($this->world);

    expect(Transaction::where('tenant_id', $this->world[0]->id)->sum('amount'))->toEqual(15000)
        ->and(OcrJob::latest('id')->first()->status)->toBe('completed');
});

it('gambar bukan struk tetap memotong kuota (perilaku lama dipertahankan)', function () {
    Http::fake(['*generativelanguage*' => Http::response(geminiJson(['document_type' => 'unknown', 'total_amount' => 0]))]);

    $replies = implode("\n", scanSend($this->world));

    expect(OcrJob::latest('id')->first()->status)->toBe('completed')
        ->and($replies)->toContain('Gambar Tidak Dikenali');
});

it('total tidak wajar tidak dicatat dan tidak memotong kuota', function () {
    Http::fake(['*generativelanguage*' => Http::response(geminiJson([
        'document_type' => 'receipt', 'merchant_name' => 'X', 'items' => [], 'total_amount' => 999999999999,
    ]))]);

    $replies = implode("\n", scanSend($this->world));

    expect(Transaction::where('tenant_id', $this->world[0]->id)->count())->toBe(0)
        ->and(OcrJob::latest('id')->first()->status)->toBe('failed')
        ->and($replies)->toContain('tidak wajar');
});

it('jumlah item jauh dari total → minta user cek kembali', function () {
    Http::fake(['*generativelanguage*' => Http::response(geminiJson([
        'document_type' => 'receipt', 'merchant_name' => 'Toko', 'date' => now()->toDateString(),
        'items' => [['name' => 'Beras', 'qty' => 1, 'price' => 50000]], 'tax' => 0, 'total_amount' => 500000,
    ]))]);

    expect(implode("\n", scanSend($this->world)))->toContain('Mohon cek kembali');
});

it('tanggal format Indonesia 03/05/2026 dibaca 3 Mei, bukan 5 Maret', function () {
    $this->travelTo(\Carbon\Carbon::create(2026, 5, 10));
    Http::fake(['*generativelanguage*' => Http::response(geminiJson([
        'document_type' => 'receipt', 'merchant_name' => 'Toko', 'date' => '03/05/2026',
        'items' => [], 'tax' => 0, 'total_amount' => 10000,
    ]))]);

    scanSend($this->world);

    $tx = Transaction::where('tenant_id', $this->world[0]->id)->latest('id')->first();
    expect(\Carbon\Carbon::parse($tx->transaction_date)->toDateString())->toBe('2026-05-03');
});

it('batas scan per menit diterapkan & user_id terisi', function () {
    Http::fake(['*generativelanguage*' => Http::response(geminiJson([
        'document_type' => 'receipt', 'merchant_name' => 'Toko', 'items' => [], 'total_amount' => 10000,
    ]))]);

    foreach (range(1, \App\Services\SubscriptionLimitService::SCAN_LIMIT_PER_MINUTE) as $i) {
        scanSend($this->world);
    }
    $replies = implode("\n", scanSend($this->world));

    expect($replies)->toContain('Terlalu cepat')
        ->and(OcrJob::whereNull('user_id')->count())->toBe(0)
        ->and(OcrJob::where('user_id', $this->world[2]->id)->count())->toBe(\App\Services\SubscriptionLimitService::SCAN_LIMIT_PER_MINUTE);
});

it('path di luar folder upload ditolak dan tidak dikirim ke Gemini', function () {
    Http::fake();

    scanSend($this->world, '/storage/whatsapp/../../../.env');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
});

it('URL dari host asing tidak diunduh', function () {
    Http::fake();

    scanSend($this->world, 'https://evil.example.com/api/files?x=1');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.com'));
});

// ── Tahap 9: duplikat & arah transfer ──

it('foto struk yang sama dikirim dua kali hanya dicatat sekali', function () {
    Http::fake(['*generativelanguage*' => Http::response(geminiJson([
        'document_type' => 'receipt', 'merchant_name' => 'Toko', 'items' => [], 'total_amount' => 12000,
    ]))]);

    $path = scanImagePath($this->world[0]->id);
    scanSend($this->world, $path);
    $replies = implode("\n", scanSend($this->world, $path));

    expect(Transaction::where('tenant_id', $this->world[0]->id)->count())->toBe(1)
        ->and($replies)->toContain('sudah pernah dicatat');
    Http::assertSentCount(1); // Gemini tidak dipanggil lagi untuk foto kedua
});

it('bukti transfer yang DITERIMA user dicatat sebagai pemasukan', function () {
    Http::fake(['*generativelanguage*' => Http::response(geminiJson([
        'document_type' => 'bank_transfer', 'bank_name' => 'BCA', 'merchant_name' => 'Scan User',
        'recipient_name' => 'SCAN USER', 'sender_name' => 'Budi Santoso', 'total_amount' => 750000,
        'reference_number' => 'REF-IN-1', 'items' => [],
    ]))]);

    scanSend($this->world);

    $tx = Transaction::where('tenant_id', $this->world[0]->id)->latest('id')->first();
    expect($tx->type)->toBe('income')
        ->and((int) $tx->amount)->toBe(750000);
});

it('bukti transfer KE orang lain tetap pengeluaran, referensi sama tidak dobel', function () {
    Http::fake(['*generativelanguage*' => Http::response(geminiJson([
        'document_type' => 'bank_transfer', 'bank_name' => 'BCA', 'merchant_name' => 'Budi Santoso',
        'recipient_name' => 'Budi Santoso', 'sender_name' => 'Scan User', 'total_amount' => 300000,
        'reference_number' => 'REF-OUT-1', 'items' => [],
    ]))]);

    scanSend($this->world);
    scanSend($this->world); // foto berbeda, nomor referensi sama

    $txs = Transaction::where('tenant_id', $this->world[0]->id)->get();
    expect($txs)->toHaveCount(1)
        ->and($txs->first()->type)->toBe('expense');
});
