<?php

/**
 * Pemahaman pesan ambigu — Tahap 1:
 *  - jenis yang dulu salah tebak (sumbangan, bunga deposito/pinjaman)
 *  - harga satuan "@20rb" / "masing-masing"
 *  - arah pinjaman: "budi pinjam" = piutang; "pinjam budi" = tanya
 *  - pindah antar dompet sendiri (tarik/setor tunai, top up, tf ke dompet) = transfer berpasangan
 *  - nominal tanpa satuan ("bensin 50") → "maksudnya Rp 50.000?"
 *  - konfirmasi & transfer ambigu → pilihan bernomor; bug "Transfer Rp 0"
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
use App\Services\Transaction\TransactionExtractorService;
use App\Services\Transaction\TransactionTypeDetector;
use App\Services\Wallet\InternalMoveDetector;
use App\Services\WhatsAppService;
use App\Support\AmountParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function ambSend(string $text): string
{
    $GLOBALS['amb_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000222', 'sender_id' => '6281800000022',
        'message_id' => 'amb-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['amb_replies']);
}

function ambUserTx()
{
    return Transaction::where('tenant_id', test()->tenant->id)
        ->where(fn ($q) => $q->whereNull('source')->orWhereNotIn('source', ['opening_balance', 'balance_adjustment']))
        ->get();
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['amb_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['amb_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Ambigu', 'slug' => 'ambigu', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000222',
        'name' => 'Ambigu', 'config' => ['session_id' => 'wa_ambigu'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'Ambigu', 'email' => 'ambigu@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281800000022', 'tenant_id' => $this->tenant->id,
    ]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $mk = fn ($name, $type, $default) => Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => $name, 'account_type' => $type, 'currency' => 'IDR',
        'balance' => 1_000_000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => $default,
    ]);
    $this->cash = $mk('Dompet Utama', 'cash', true);
    $this->bca = $mk('BCA', 'bank', false);
    $this->gopay = $mk('Gopay', 'wallet', false);
});

// ── Jenis & nominal ─────────────────────────────────────────────────────────

it('jenis yang dulu salah tebak', function (string $text, string $type) {
    expect(app(TransactionTypeDetector::class)->detect($text))->toBe($type);
})->with([
    ['sumbangan 50rb', 'expense'],
    ['donasi masjid 100rb', 'expense'],
    ['dapat sumbangan 500rb', 'income'],
    ['bunga deposito 50rb', 'income'],
    ['bunga tabungan 12rb', 'income'],
    ['bunga pinjaman 150rb', 'expense'],
    ['dividen saham 300rb', 'income'],
]);

it('harga satuan × jumlah', function (string $text, ?int $expected) {
    expect(AmountParser::composite($text))->toBe($expected);
})->with([
    ['beli 3 buku @20rb', 60000],
    ['beli 2 kaos masing-masing 75rb', 150000],
    ['4 nasi bungkus 15rb per bungkus', 60000],
    ['3 tiket 50rb/orang', 150000],
    ['beli 2 kopi 25rb', null], // tanpa penanda satuan → total
]);

it('arah pinjaman dari subjek kalimat', function (string $text, ?string $expected) {
    expect(TransactionExtractorService::debtDirectionFromSubject($text))->toBe($expected);
})->with([
    ['budi pinjam 100rb', 'catat_piutang'],
    ['ibu minjem 200rb', 'catat_piutang'],
    ['pinjam budi 100rb', 'ambiguous'],
    ['saya pinjam ke budi 100rb', null],
    ['pinjam uang dari budi 100rb', null],
    ['mau pinjam 100rb', null],
]);

it('WA: "budi pinjam 100rb" tercatat sebagai piutang', function () {
    ambSend('budi pinjam 100rb');

    $tx = ambUserTx()->sole();
    expect($tx->type)->toBe('expense')
        ->and($tx->category->type)->toBe('pengeluaran_piutang');
});

it('WA: "pinjam budi 100rb" → tanya arah, jawaban menentukan hutang/piutang', function (string $answer, string $type, string $category) {
    $ask = ambSend('pinjam budi 100rb');
    expect($ask)->toContain('siapa yang meminjam')
        ->and(ambUserTx())->toHaveCount(0);

    ambSend($answer);
    $tx = ambUserTx()->sole();
    expect($tx->type)->toBe($type)
        ->and((int) $tx->amount)->toBe(100000)
        ->and($tx->category->type)->toBe($category);
})->with([
    ['1', 'income', 'pendapatan_hutang'],
    ['2', 'expense', 'pengeluaran_piutang'],
    ['piutang', 'expense', 'pengeluaran_piutang'],
]);

// ── Pindah antar dompet sendiri ─────────────────────────────────────────────

it('mengenali pindah antar dompet', function (string $text, ?string $kind) {
    expect(InternalMoveDetector::detect($text)['kind'] ?? null)->toBe($kind);
})->with([
    ['tarik tunai 500rb', 'withdraw'],
    ['tarik tunai 500rb di BCA', 'withdraw'],
    ['setor tunai 1jt ke BCA', 'deposit'],
    ['topup gopay 100rb', 'topup'],
    ['isi ovo 50rb pakai BCA', 'topup'],
    ['tf ke BCA 1jt', 'to_wallet'],
    ['tarik saldo shopee 300rb', null],   // pencairan marketplace = pemasukan
    ['narik grab 150rb', null],
    ['beli kopi 25rb', null],
]);

it('WA: "tarik tunai 500rb di BCA" → pindah BCA → tunai, bukan pemasukan', function () {
    $reply = ambSend('tarik tunai 500rb di BCA');

    expect($reply)->toContain('Tarik tunai Tercatat')
        ->and(ambUserTx()->whereIn('type', ['income', 'expense']))->toHaveCount(0)
        ->and((float) $this->bca->fresh()->balance)->toBe(500_000.0)
        ->and((float) $this->cash->fresh()->balance)->toBe(1_500_000.0);
});

it('WA: "tarik tunai 500rb" tanpa sumber → tanya dari dompet mana', function () {
    $ask = ambSend('tarik tunai 500rb');
    expect($ask)->toContain('dari dompet mana')->toContain('BCA');

    ambSend('1'); // opsi pertama = BCA (dompet non-tunai pertama)
    expect((float) $this->cash->fresh()->balance)->toBe(1_500_000.0)
        ->and(ambUserTx()->whereIn('type', ['income', 'expense']))->toHaveCount(0);
});

it('WA: "setor tunai 1jt ke BCA" → tunai → BCA', function () {
    ambSend('setor tunai 1jt ke BCA');

    expect((float) $this->cash->fresh()->balance)->toBe(0.0)
        ->and((float) $this->bca->fresh()->balance)->toBe(2_000_000.0);
});

it('WA: "isi gopay 100rb pakai BCA" → BCA → Gopay', function () {
    ambSend('isi gopay 100rb pakai BCA');

    expect((float) $this->bca->fresh()->balance)->toBe(900_000.0)
        ->and((float) $this->gopay->fresh()->balance)->toBe(1_100_000.0);
});

it('WA: "topup ovo 50rb" → dompet OVO dibuat, tanya sumber, opsi "uang dari luar"', function () {
    $ask = ambSend('topup ovo 50rb');
    expect($ask)->toContain('dari dompet mana')->toContain('Uang dari luar FinWa');
    $ovo = Balance::where('tenant_id', $this->tenant->id)->whereRaw('LOWER(account_name) = ?', ['ovo'])->first();
    expect($ovo)->not->toBeNull();

    // Opsi terakhir = uang dari luar (3 dompet lain + 1)
    ambSend('4');
    expect((float) $ovo->fresh()->balance)->toBe(50_000.0)
        ->and(ambUserTx()->whereIn('type', ['income', 'expense']))->toHaveCount(0);
});

it('WA: "tf ke BCA 1jt" → tanya sumber; opsi terakhir = pengeluaran', function () {
    $ask = ambSend('tf ke BCA 1jt');
    expect($ask)->toContain('dari dompet mana');

    ambSend('1'); // Dompet Utama → BCA
    expect((float) $this->cash->fresh()->balance)->toBe(0.0)
        ->and((float) $this->bca->fresh()->balance)->toBe(2_000_000.0);
});

it('WA: "kirim ibu 500rb" tetap pengeluaran biasa (ibu bukan dompet)', function () {
    ambSend('kirim ibu 500rb');

    expect(ambUserTx()->sole()->type)->toBe('expense');
});

// ── Nominal tanpa satuan ────────────────────────────────────────────────────

it('WA: "bensin 50" → tanya Rp 50.000, jawaban ya mencatat', function () {
    $ask = ambSend('bensin 50');
    expect($ask)->toContain('Rp 50.000');

    ambSend('ya');
    expect((int) ambUserTx()->sole()->amount)->toBe(50000);
});

it('WA: "bensin 50" → jawab nominal lain langsung dipakai', function () {
    ambSend('bensin 50');
    ambSend('45rb');

    expect((int) ambUserTx()->sole()->amount)->toBe(45000);
});

it('WA: "beli 2 kopi" tidak dianggap nominal kecil', function () {
    expect(ambSend('beli 2 kopi'))->not->toContain('Rp 2.000');
});

// ── Pilihan bernomor ───────────────────────────────────────────────────────

it('WA: konfirmasi transaksi bernomor — jawab 2 mencatat pemasukan', function () {
    $ask = ambSend('50rb');
    expect($ask)->toContain('1️⃣')->toContain('2️⃣');

    ambSend('2');
    $tx = ambUserTx()->sole();
    expect($tx->type)->toBe('income')->and((int) $tx->amount)->toBe(50000);
});

it('WA: konfirmasi transaksi — jawab 3 tidak mencatat', function () {
    ambSend('50rb');
    expect(ambSend('3'))->toContain('tidak dicatat')
        ->and(ambUserTx())->toHaveCount(0);
});

it('WA: "transfer 500rb" menampilkan nominal (bukan Rp 0) & bisa dijawab bernomor', function () {
    $ask = ambSend('transfer 500rb');
    expect($ask)->toContain('Rp 500.000')->not->toContain('Rp 0');

    ambSend('masuk');
    expect(ambUserTx()->sole()->type)->toBe('income');
});

it('pesan lain setelah pertanyaan → pertanyaan batal, pesan diproses biasa', function () {
    ambSend('pinjam budi 100rb');
    ambSend('beli kopi 25rb');

    expect(ambUserTx()->sole()->description)->toContain('kopi');
    ambSend('1'); // pertanyaan sudah batal → tidak mencatat pinjaman
    expect(ambUserTx())->toHaveCount(1);
});
