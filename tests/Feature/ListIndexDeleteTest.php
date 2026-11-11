<?php

/**
 * Bug produksi 20/10/2026 (Haerul): "lihat transaksi" → daftar bernomor → "1 hapus" → tidak dikenali.
 * Pola nomor dulu hanya untuk "N ubah jadi X". Sekarang "1 hapus", "hapus 1", "hapus nomor 1"
 * → konfirmasi Ya/Tidak untuk transaksi nomor itu (tahan LID ↔ nomor HP, juga via quote-reply).
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;
use App\Services\Category\CategoryManagerService;
use App\Services\Ledger\TransactionLedger;
use App\Services\Transaction\TransactionService;
use App\Services\WhatsAppService;
use App\Support\SenderIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const LST_PHONE = '6281300000055';
const LST_LID = '77788232532003';

function lstSend(string $text, string $sender = LST_PHONE, ?array $quote = null): string
{
    $GLOBALS['lst_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000124', 'sender_id' => $sender,
        'message_id' => 'lst-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
        'raw_data' => $quote ? ['reply_context' => ['messageId' => $quote[0], 'participant' => null, 'text' => $quote[1]]] : null,
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", array_column($GLOBALS['lst_replies'], 'text'));
}

function lstTx(string $desc, int $amount, string $type = 'expense'): Transaction
{
    $tx = app(TransactionLedger::class)->record([
        'tenant_id' => test()->tenant->id, 'balance_id' => test()->main->id,
        'category_id' => Category::where('tenant_id', test()->tenant->id)->where('type', $type === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya')->value('id'),
        'type' => $type, 'amount' => $amount, 'description' => $desc, 'transaction_date' => now()->toDateString(),
    ]);
    test()->travel(1)->seconds(); // urutan created_at jelas (daftar = terbaru dulu)

    return $tx;
}

beforeEach(function () {
    Cache::flush();
    SenderIdentity::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_push.enabled' => false, 'finwa_reply_edit.enabled' => true, 'finwa_reply_edit.dry_run' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $GLOBALS['lst_all'] = [];
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $id = 'BOT'.strtoupper(substr(md5(uniqid('', true)), 0, 12));
        $GLOBALS['lst_replies'][] = ['id' => $id, 'text' => $text];
        $GLOBALS['lst_all'][] = ['id' => $id, 'text' => $text];

        return ['success' => true, 'data' => ['messageId' => $id]];
    });

    $this->tenant = Tenant::create(['name' => 'Daftar', 'slug' => 'daftar', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000124',
        'name' => 'FinWa', 'config' => ['session_id' => 'wa_daftar'], 'is_active' => true,
    ]);
    $user = User::create([
        'name' => 'Haerul', 'email' => 'daftar@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => LST_PHONE, 'tenant_id' => $this->tenant->id,
    ]);
    UserWhatsAppNumber::create(['user_id' => $user->id, 'tenant_id' => $this->tenant->id, 'whatsapp_number' => LST_PHONE, 'name' => 'Haerul', 'is_primary' => true, 'is_active' => true]);
    UserLidMapping::linkLidToUser(LST_LID, $user->id, $this->tenant->id, LST_PHONE);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $this->main = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 1_000_000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);

    // Daftar (terbaru dulu): 1 ada uang masuk, 2 bayar ongkir, 3 bensin, 4 beli token listrik
    $this->token = lstTx('beli token listrik', 53_000);
    $this->bensin = lstTx('bensin', 20_000);
    $this->ongkir = lstTx('bayar ongkir', 8_000);
    $this->masuk = lstTx('ada uang masuk', 2_000_000, 'income');
});

it('mengenali perintah hapus bernomor', function (string $text, ?int $expected) {
    expect(TransactionService::parseListDeleteIndex($text))->toBe($expected);
})->with([
    ['1 hapus', 1],
    ['1. hapus aja', 1],
    ['2) hapus', 2],
    ['hapus 1', 1],
    ['hapus no 3', 3],
    ['hapus nomor 12', 12],
    ['Hapus transaksi nomor 2', 2],
    ['hapus', null],
    ['hapus beli kopi', null],
    ['1 ubah jadi 50rb', null],
    ['beli 2 kopi', null],
]);

it('daftar menyebut cara hapus bernomor', function () {
    expect(lstSend('lihat transaksi'))->toContain('3 hapus');
});

it('kasus Haerul: "lihat transaksi" → "1 hapus" → konfirmasi transaksi nomor 1 → "ya" → terhapus & bisa dikembalikan', function () {
    lstSend('lihat transaksi');

    $ask = lstSend('1 hapus');
    expect($ask)->toContain('Hapus transaksi ini?')->toContain('ada uang masuk')->toContain('Rp 2.000.000');

    lstSend('ya');
    expect(Transaction::find($this->masuk->id))->toBeNull()
        ->and(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(3)
        ->and((float) $this->main->fresh()->balance)->toBe(919_000.0); // 1jt − 81rb (saldo 2jt dikembalikan)

    lstSend('kembalikan');
    expect(Transaction::find($this->masuk->id))->not->toBeNull();
});

it('"hapus nomor 3" menghapus nomor 3 (bensin), bukan transaksi terakhir', function () {
    lstSend('lihat transaksi');
    expect(lstSend('hapus nomor 3'))->toContain('bensin');

    lstSend('ya');
    expect(Transaction::find($this->bensin->id))->toBeNull()
        ->and(Transaction::find($this->masuk->id))->not->toBeNull();
});

it('nomor di luar daftar → diberi tahu', function () {
    lstSend('lihat transaksi');
    expect(lstSend('9 hapus'))->toContain('tidak ada di daftar')
        ->and(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(4);
});

it('"1 hapus" sebelum membuka daftar → diminta "lihat transaksi" dulu', function () {
    expect(lstSend('1 hapus'))->toContain('lihat transaksi')
        ->and(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(4);
});

it('daftar dibuka lewat nomor HP, "1 hapus" & "ya" datang lewat LID → tetap jalan', function () {
    lstSend('lihat transaksi', LST_PHONE);
    expect(lstSend('1 hapus', LST_LID))->toContain('ada uang masuk');

    lstSend('ya', LST_LID);
    expect(Transaction::find($this->masuk->id))->toBeNull();
});

it('balas (quote) daftar dengan "2 hapus" → langsung konfirmasi nomor 2, tidak menampilkan daftar ulang', function () {
    lstSend('lihat transaksi');
    $list = collect($GLOBALS['lst_all'])->first(fn ($m) => str_contains($m['text'], 'Transaksi Hari Ini'));

    $ask = lstSend('2 hapus', LST_PHONE, [$list['id'], $list['text']]);
    expect($ask)->toContain('Hapus transaksi ini?')->toContain('bayar ongkir')
        ->not->toContain('Pilih transaksi');

    lstSend('ya');
    expect(Transaction::find($this->ongkir->id))->toBeNull();
});

it('"N ubah jadi X" tetap jalan (tidak terganggu)', function () {
    lstSend('lihat transaksi');
    lstSend('3 ubah jadi 25rb');

    expect((int) $this->bensin->fresh()->amount)->toBe(25_000);
});
