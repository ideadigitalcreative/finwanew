<?php

/**
 * Bug produksi 13/10/2026 (pesan asli user):
 *  1. "Pemasukan Tanggal 28 September sebesar 360rb, 5 Oktober 500rb, 12 Oktober 440rb"
 *     → item pertama hilang, keterangan ", 5 Oktober", semua bertanggal 28 September.
 *  2. Daftar baris diawali "amplop untuk 2x undangan 300rb" → baris pertama dibuang
 *     (dianggap judul) & kata "undangan" mencemari kategori baris lain
 *     ("selang dan sambungan pipa rumah" → Acara & Hajatan).
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
use App\Services\Transaction\BatchTransactionService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function batchWorld(): array
{
    $tenant = Tenant::create(['name' => 'Batch', 'slug' => 'batch', 'is_active' => true]);
    $channel = Channel::create([
        'tenant_id' => $tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000444',
        'name' => 'Batch', 'config' => ['session_id' => 'wa_batch'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'Batch User', 'email' => 'batch@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281400000001', 'tenant_id' => $tenant->id,
    ]);
    Subscription::create([
        'tenant_id' => $tenant->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active',
    ]);
    (new CategoryManagerService)->createCategoriesForTenant($tenant->id);
    Balance::create([
        'tenant_id' => $tenant->id, 'account_name' => 'Cash', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 10000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);

    return [$tenant, $channel];
}

function batchSend(string $text, array $world): string
{
    [$tenant, $channel] = $world;
    $GLOBALS['batch_replies'] = [];
    $message = Message::create([
        'tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000444', 'sender_id' => '6281400000001',
        'message_id' => 'batch-'.uniqid(), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n", $GLOBALS['batch_replies']);
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::create(2026, 10, 13, 10, 39));
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['batch_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['batch_replies'])]];
    });
    $this->world = batchWorld();
});

afterEach(fn () => Carbon::setTestNow());

it('satu kalimat dengan beberapa tanggal → 3 pemasukan dengan tanggal masing-masing', function () {
    $reply = batchSend(
        'tolong di catat juga dong Pemasukan Tanggal 28 September sebesar 360rb, 5 Oktober 500rb, 12 Oktober 440rb.',
        $this->world
    );

    $txs = Transaction::where('tenant_id', $this->world[0]->id)->orderBy('transaction_date')->get();

    expect($txs)->toHaveCount(3)
        ->and($txs->pluck('type')->unique()->all())->toBe(['income'])
        ->and($txs->map(fn ($t) => [Carbon::parse($t->transaction_date)->toDateString(), (int) $t->amount])->all())
        ->toBe([
            ['2026-09-28', 360000],
            ['2026-10-05', 500000],
            ['2026-10-12', 440000],
        ]);

    // Keterangan bersih (tanpa ", 5 Oktober" / "tolong di catat") & tanggal per baris tampil
    expect($txs->pluck('description')->all())->each->not->toMatch('/^,|oktober|september|tolong|sebesar/i')
        ->and($reply)->toContain('3 Transaksi Berhasil Dicatat')
        ->and($reply)->toContain('28 September 2026')
        ->and($reply)->toContain('12 Oktober 2026')
        ->and($reply)->toContain('Rp 1.300.000');
});

it('daftar baris: baris pertama bernominal ikut dicatat & kategori tidak tercemar', function () {
    batchSend(implode("\n", [
        'amplop untuk 2x undangan 300rb',
        'beli nasi kuning 95rb',
        'alat mesin air otomatis rumah 87rb',
        'beli beras 91rb',
        'beli ayam goreng suster 30rb',
        'selang dan sambungan pipa rumah 62rb',
        'beli nasi 5 pembantu di rumah 75rb',
        'beli gula 22rb',
    ]), $this->world);

    $txs = Transaction::with('category')->where('tenant_id', $this->world[0]->id)->get()->keyBy('description');

    expect($txs)->toHaveCount(8)
        ->and((int) $txs->sum('amount'))->toBe(762000)
        ->and($txs->has('amplop untuk 2x undangan'))->toBeTrue()
        ->and($txs['selang dan sambungan pipa rumah']->category->type)->toBe('pengeluaran_hunian')
        ->and($txs['alat mesin air otomatis rumah']->category->type)->toBe('pengeluaran_hunian')
        ->and((int) $txs['beli nasi 5 pembantu di rumah']->amount)->toBe(75000);
});

it('judul bertanggal tetap dianggap judul (tidak dicatat sebagai transaksi)', function () {
    batchSend("Biaya tanggal 10 Oktober 2026\n1. Makan malam 17.000\n2. Grab pulang 13.500", $this->world);

    $txs = Transaction::where('tenant_id', $this->world[0]->id)->get();
    expect($txs)->toHaveCount(2)
        ->and($txs->every(fn ($t) => Carbon::parse($t->transaction_date)->toDateString() === '2026-10-10'))->toBeTrue();
});

it('judul "Pengeluaran <tanggal>" tanpa kata tanggal → pakai tanggal judul', function (string $header, string $date) {
    // Bug produksi 26/10/2026: "Pengeluaran 22 Oktober 2026" terbaca transaksi
    // "Pengeluaran 22" → tanggal judul diabaikan, semua item bertanggal hari ini.
    batchSend($header."\n\n- laundry baju 45.000\n- tarik tunai 55.000", $this->world);

    $txs = Transaction::where('tenant_id', $this->world[0]->id)->get();
    expect($txs)->toHaveCount(2)
        ->and((int) $txs->sum('amount'))->toBe(100000)
        ->and($txs->map(fn ($t) => Carbon::parse($t->transaction_date)->toDateString())->unique()->values()->all())->toBe([$date]);
})->with([
    ['Pengeluaran 22 Oktober 2026', '2026-10-22'],
    ['Pengeluaran 5 Okt', '2026-10-05'],
    ['Pemasukan 22/09/2026', '2026-09-22'],
]);

// ── Unit helper ──

it('mengenali baris yang diakhiri nominal', function (string $line, bool $expected) {
    expect(BatchTransactionService::lineHasTrailingAmount($line))->toBe($expected);
})->with([
    ['amplop untuk 2x undangan 300rb', true],
    ['selang pipa 62.000', true],
    ['parkir 5000', true],
    ['Biaya tanggal 10 Desember 2025', false],
    ['Pengeluaran hari ini:', false],
    ['Belanja:', false],
]);

it('membersihkan keterangan item batch', function (string $in, string $out) {
    expect(BatchTransactionService::cleanBatchDescription($in))->toBe($out);
})->with([
    ['tolong di catat juga dong Pemasukan sebesar', 'Pemasukan'],
    [', ', ''],
    ['catat makan siang', 'makan siang'],
    ['beli nasi kuning', 'beli nasi kuning'],
]);

it('membuang tanggal dari baris tanpa menyentuh nominal', function (string $in, string $out) {
    expect(BatchTransactionService::stripDates($in))->toBe($out);
})->with([
    ['Pengeluaran 22 Oktober 2026', 'Pengeluaran'],
    ['Pemasukan 22/09/2026', 'Pemasukan'],
    ['beli makan 5 Okt 25rb', 'beli makan 25rb'],
    ['beli martabak 2 porsi 30rb', 'beli martabak 2 porsi 30rb'],
    ['Pemasukan 21.851', 'Pemasukan 21.851'],
]);
